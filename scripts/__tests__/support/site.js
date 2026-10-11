// Shared by the tests of the deployment scripts (LUMN-51): a site as the server lays one out, in a
// temporary directory, with real git repositories.
//
//   <site>/repo/                  a bare clone of `origin.git`, which plays GitHub
//   <site>/releases/<name>/       one directory per release
//   <site>/shared/                .env, storage/ and the lock
//   <site>/current -> releases/…  the release in service
//
// Time is the one thing the scripts read that a test has to decide: a release is named after the
// second it was made, and the gate counts how long ago a deployment was. `date` is therefore a
// stand-in that answers what the test says, and the real one for everything else.
import { spawn, spawnSync } from 'node:child_process';
import {
    existsSync,
    lstatSync,
    mkdirSync,
    readdirSync,
    readFileSync,
    readlinkSync,
    rmSync,
    statSync,
    symlinkSync,
    writeFileSync,
} from 'node:fs';
import { basename, join } from 'node:path';
import { BASH, locate } from './sandbox.js';

export const GIT_ENV = {
    ...process.env,
    GIT_CONFIG_GLOBAL: '/dev/null',
    GIT_CONFIG_NOSYSTEM: '1',
    GIT_AUTHOR_NAME: 'Test',
    GIT_AUTHOR_EMAIL: 'test@example.org',
    GIT_COMMITTER_NAME: 'Test',
    GIT_COMMITTER_EMAIL: 'test@example.org',
};

export function git(cwd, ...args) {
    const result = spawnSync('git', args, { cwd, encoding: 'utf8', env: GIT_ENV });
    if (result.status !== 0) {
        throw new Error(`git ${args.join(' ')} failed: ${result.stderr}`);
    }
    return result.stdout.trim();
}

export const knows = (repository, object) =>
    spawnSync('git', ['cat-file', '-e', object], { cwd: repository, env: GIT_ENV }).status === 0;

// 2026-10-11T08:00:00Z, and the name a release made at that second carries.
export const EPOCH = 1791705600;
export const STAMP = '20261011-080000';

export const DATE = `#!${BASH}
case "$*" in
  *+%s*) [[ -n "\${STUB_EPOCH:-}" ]] && { printf '%s\\n' "$STUB_EPOCH"; exit 0; } ;;
  *%H%M%S*) [[ -n "\${STUB_STAMP:-}" ]] && { printf '%s\\n' "$STUB_STAMP"; exit 0; } ;;
esac
exec ${locate('date')} "$@"
`;

// Appended to a stand-in that wants to say whether a deployment lock is held while it runs: a
// second lock on the same file is refused as long as somebody holds one.
//
// Asked through a descriptor of its own, with flock and nothing else: a form that runs a command
// would need that command on the PATH of the test, and "could not run it" reads exactly like
// "could not lock" — which is how this line once answered `held` whatever the truth was.
export const LOCK_STATE = `if ( exec 8>> "$STUB_LOCK" && flock --nonblock 8 ) 2> /dev/null; then lock_state=free; else lock_state=held; fi`;

export const releaseName = (sha, stamp = STAMP) => `${stamp}-${sha}`;

export const paths = (site) => ({
    repo: join(site, 'repo'),
    releases: join(site, 'releases'),
    shared: join(site, 'shared'),
    current: join(site, 'current'),
    lock: join(site, 'shared', 'deploy.lock'),
    lastDeployment: join(site, 'shared', 'last-deployment'),
    gateJournal: join(site, 'shared', 'storage', 'logs', 'deploy-gate.log'),
});

/**
 * The empty shell of a site: no repository yet, no release.
 *
 * @param {string} site
 */
export function layOut(site) {
    mkdirSync(join(site, 'releases'), { recursive: true });
    mkdirSync(join(site, 'shared', 'storage', 'logs'), { recursive: true });
    writeFileSync(join(site, 'shared', '.env'), 'APP_ENV=production\n');
}

/**
 * A release directory as scripts/release.sh and scripts/deploy.sh leave one.
 *
 * @param {string} site
 * @param {string} name
 * @param {{sha: string, files?: Record<string, string>, served?: boolean}} release
 * @returns {string} the directory
 */
export function addRelease(site, name, { sha, files = {}, served = true }) {
    const directory = join(site, 'releases', name);
    mkdirSync(directory, { recursive: true });
    writeFileSync(join(directory, 'RELEASE'), `${sha}\n`);
    Object.entries(files).forEach(([path, content]) => {
        mkdirSync(join(directory, path, '..'), { recursive: true });
        writeFileSync(join(directory, path), content);
    });
    if (served) {
        writeFileSync(join(directory, '.served'), '');
    }
    return directory;
}

/** Puts a release in service, the way the scripts do: a relative link. */
export function serve(site, name) {
    rmSync(join(site, 'current'), { force: true });
    symlinkSync(join('releases', name), join(site, 'current'));
}

/** The name of the release in service, or null. */
export function inService(site) {
    const current = join(site, 'current');
    const entry = lstatSync(current, { throwIfNoEntry: false });
    if (!entry) {
        return null;
    }
    return entry.isSymbolicLink() ? basename(readlinkSync(current)) : 'not a link';
}

// The content of a file, or null when there is none — a directory is not one.
export const read = (path) => (existsSync(path) && statSync(path).isFile() ? readFileSync(path, 'utf8') : null);

export const lines = (path) => (read(path) ?? '').split('\n').filter(Boolean);

export const isLocked = (site) =>
    spawnSync('flock', ['--nonblock', paths(site).lock, 'true'], { env: GIT_ENV }).status !== 0;

/**
 * Holds the deployment lock of a site, as a deployment in progress does.
 *
 * @param {string} site
 * @returns {() => void} lets go of it
 */
export function holdLock(site) {
    // One process and no child: killing it is letting go of the lock.
    const holder = spawn(BASH, ['-c', 'exec 9>> "$1"; flock 9; exec sleep 30', 'bash', paths(site).lock], {
        stdio: 'ignore',
    });
    const deadline = Date.now() + 5000;
    while (!isLocked(site)) {
        if (Date.now() > deadline) {
            holder.kill('SIGKILL');
            throw new Error('The deployment lock could not be taken for the test.');
        }
    }
    return () => holder.kill('SIGKILL');
}

/**
 * Turns every pack of a repository into loose objects, one file each: the only form in which a
 * test can take a single object away.
 *
 * @param {string} repository  a bare repository
 */
export function loosen(repository) {
    const packs = join(repository, 'objects', 'pack');
    readdirSync(packs)
        .filter((file) => file.endsWith('.pack'))
        .forEach((file) => {
            const pack = readFileSync(join(packs, file));
            // git does not unpack what it already has: the pack has to be out of the way first.
            readdirSync(packs)
                .filter((other) => other.startsWith(file.replace(/\.pack$/, '')))
                .forEach((other) => rmSync(join(packs, other)));
            const result = spawnSync('git', ['unpack-objects', '-q'], { cwd: repository, input: pack, env: GIT_ENV });
            if (result.status !== 0) {
                throw new Error(`git unpack-objects failed: ${result.stderr}`);
            }
        });
}

/**
 * Removes one object from a repository whose objects are loose: what a disk that filled up, or a
 * file system that lied, leaves behind.
 *
 * @param {string} repository
 * @param {string} object  its full SHA
 */
export function lose(repository, object) {
    rmSync(join(repository, 'objects', object.slice(0, 2), object.slice(2)));
}
