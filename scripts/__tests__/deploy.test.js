// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { spawnSync } from 'node:child_process';
import {
    chmodSync,
    cpSync,
    existsSync,
    lstatSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    readdirSync,
    readlinkSync,
    rmSync,
    symlinkSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JOURNAL, callsIn, failingWhenFull, fillPath, locate } from './support/sandbox.js';
import {
    DATE,
    EPOCH,
    addRelease,
    holdLock,
    inService,
    layOut,
    lines,
    paths,
    read,
    releaseName,
    serve,
} from './support/site.js';

// scripts/deploy.sh, run for real in a release directory of a throwaway site. composer, npm and
// php are stand-ins that record how they were called and what the site looked like at that moment.
//
// The script works on the directory it lives in — and deletes it when it fails: it is copied into
// a release made for the test, never run from this repository.
const inRepository = (path) => fileURLToPath(new URL(`../../${path}`, import.meta.url));

// What a deployment runs, in order. docs/deployment.md lists the same lines, and a test below
// keeps the two from drifting apart.
const SEQUENCE = [
    'composer install --no-dev --optimize-autoloader --no-interaction',
    'npm ci',
    'npm run build',
    'php artisan migrate --force --no-interaction',
    'php artisan db:seed --class=HomepageContentSeeder --force --no-interaction',
    'php artisan config:cache',
    'php artisan route:cache',
    'php artisan view:cache',
];

const OLD = 'a'.repeat(40);
const NEW = 'b'.repeat(40);
const name = (sha, time) => releaseName(sha, `20261011-${time}`);
const SERVED = name(OLD, '070000');
const BUILT = name(NEW, '080000');

// Fails when asked to, by the start of its command line. What it writes itself goes through the real
// tools by their full path: the ones on the PATH are counted, and are the script's alone. Each call also notes where it ran, what
// .env was, and which release was in service: the only way to know what was true *during* a step.
const STANDIN = `${JOURNAL}
call="\${0##*/} $*"
printf '%s\\x1f%s\\x1f%s\\x1f%s\\n' "$call" "\${PWD##*/}" "$(readlink .env)" "$(readlink "$STUB_SITE/current")" >> "$STUB_DIR/state.log"
if [[ "$call" == 'npm run build' ]]; then
  for file in $STUB_BUILDS; do
    ${locate('mkdir')} -p public/build/assets
    printf 'built by %s\\n' "$(cat RELEASE)" > "public/build/assets/$file"
  done
fi
if [[ "$call" == 'php artisan migrate'* ]]; then
  printf 'written through the release\\n' > storage/logs/through.log
  ls public/build/assets > "$STUB_DIR/assets-at-migrate" 2> /dev/null
fi
if [[ -n "\${STUB_INTERRUPT:-}" && "$call" == "$STUB_INTERRUPT"* ]]; then
  kill "-\${STUB_SIGNAL:-TERM}" "$PPID"
fi
if [[ -n "\${STUB_FAIL:-}" && "$call" == "$STUB_FAIL"* ]]; then
  printf 'stand-in: "%s" failed\\n' "$call" >&2
  exit 1
fi
`;

// Does what mv does, then interrupts the script that called it: the instant between the link
// changing and anything the script could note down about it.
const MV_THEN_INTERRUPTED = `#!/bin/bash
${locate('mv')} "$@"
kill -TERM "$PPID"
`;

// The tools through which the script writes to the disk.
const WRITERS = ['rm', 'ln', 'cp', 'touch', 'mv', 'mkdir'];

let template;
const sandboxes = [];

beforeAll(() => {
    template = mkdtempSync(join(tmpdir(), 'deploy-template-'));
    layOut(join(template, 'site'));
    fillPath(join(template, 'bin'), {
        real: ['bash', 'dirname', 'basename', 'cat', 'ls', 'readlink', 'flock'],
        standins: {
            composer: STANDIN,
            npm: STANDIN,
            php: STANDIN,
            date: DATE,
            // Every tool that writes can be made to fail, one call at a time.
            ...Object.fromEntries(WRITERS.map((tool) => [tool, failingWhenFull(tool)])),
        },
    });
    fillPath(join(template, 'bin-interrupted'), {
        real: ['bash', 'dirname', 'basename', 'cat', 'rm', 'ln', 'touch', 'ls', 'cp', 'mkdir', 'readlink', 'flock'],
        standins: { composer: STANDIN, npm: STANDIN, php: STANDIN, date: DATE, mv: MV_THEN_INTERRUPTED },
    });
});

afterAll(() => rmSync(template, { recursive: true, force: true }));

afterEach(() => {
    sandboxes.splice(0).forEach((root) => rmSync(root, { recursive: true, force: true }));
});

// A release as scripts/release.sh hands it over: the files of the commit, RELEASE, and nothing built.
function extracted(box, release, sha) {
    return addRelease(box.site, release, {
        sha,
        served: false,
        files: {
            'scripts/deploy.sh': readFileSync(inRepository('scripts/deploy.sh'), 'utf8'),
            'storage/logs/.gitignore': '*\n',
            'public/index.php': '<?php\n',
        },
    });
}

/**
 * A site serving one release, and a second one extracted and waiting to be built.
 *
 * @param {{firstRelease?: boolean}} [options]  true for a site that serves nothing yet
 */
function sandbox({ firstRelease = false } = {}) {
    const root = mkdtempSync(join(tmpdir(), 'deploy-'));
    sandboxes.push(root);
    cpSync(template, root, { recursive: true, verbatimSymlinks: true });

    const box = { root, site: join(root, 'site'), bin: join(root, 'bin'), log: join(root, 'calls.log') };
    if (!firstRelease) {
        addRelease(box.site, SERVED, { sha: OLD, files: { 'page.txt': 'the release in service\n' } });
        serve(box.site, SERVED);
    }
    box.release = extracted(box, BUILT, NEW);
    return box;
}

/**
 * Runs the script as scripts/release.sh does: with the deployment lock held, and announced.
 *
 * @param {object} box
 * @param {{args?: string[], fail?: string, interrupt?: string, cwd?: string, lockFd?: string|null,
 *          locked?: boolean, builds?: string[], release?: string, bin?: string, full?: string,
 *          signal?: string}} [options]
 */
function deploy(
    box,
    {
        args = [],
        fail = '',
        interrupt = '',
        cwd = box.root,
        lockFd = '9',
        locked = true,
        builds = [],
        release = box.release,
        bin = 'bin',
        full = '',
        signal = 'TERM',
    } = {},
) {
    const letGo = locked ? holdLock(box.site) : () => {};
    let result;
    try {
        result = spawnSync(join(box.root, bin, 'bash'), [join(release, 'scripts', 'deploy.sh'), ...args], {
            cwd,
            encoding: 'utf8',
            env: {
                PATH: join(box.root, bin),
                HOME: box.root,
                STUB_LOG: box.log,
                STUB_DIR: box.root,
                STUB_SITE: box.site,
                STUB_FAIL: fail,
                STUB_INTERRUPT: interrupt,
                STUB_SIGNAL: signal,
                STUB_FULL: full,
                STUB_BUILDS: builds.join(' '),
                STUB_EPOCH: String(EPOCH),
                ...(lockFd === null ? {} : { DEPLOY_LOCK_FD: lockFd }),
            },
        });
    } finally {
        letGo();
    }

    return {
        status: result.status,
        signal: result.signal,
        stdout: result.stdout,
        stderr: result.stderr,
        ran: callsIn(box.log).map((call) => call.join(' ')),
        during: lines(join(box.root, 'state.log')).map((line) => {
            const [call, directory, env, current] = line.split('\x1f');
            return { call, directory, env, current };
        }),
        inService: inService(box.site),
        releases: readdirSync(paths(box.site).releases).sort(),
        lastDeployment: read(paths(box.site).lastDeployment),
        // What the script wrote of this run in the journal the site keeps, without its date.
        journal: lines(join(box.site, 'shared', 'storage', 'logs', 'deployments.log')).map((line) =>
            line.replace(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z /, ''),
        ),
    };
}

// Refused before anything: nothing ran, and above all nothing was deleted.
function expectRefused(box, result, release = box.release) {
    expect(result.status).toBe(1);
    expect(result.ran).toEqual([]);
    expect(existsSync(join(release, 'scripts', 'deploy.sh'))).toBe(true);
    expect(result.lastDeployment).toBeNull();
}

describe('deploy.sh — the sequence', () => {
    it('runs the whole sequence, in order, in the release it sits in, and nothing else', () => {
        const result = deploy(sandbox());

        expect(result.stderr).toBe('');
        expect(result.status).toBe(0);
        expect(result.ran).toEqual(SEQUENCE);
        expect(new Set(result.during.map(({ directory }) => directory))).toEqual(new Set([BUILT]));
        expect(result.stdout).toContain(`Deployed ${NEW}`);
    });

    it('is the sequence docs/deployment.md announces, line for line', () => {
        const documentation = readFileSync(inRepository('docs/deployment.md'), 'utf8');
        const listing = documentation.match(
            /^`scripts\/deploy\.sh` runs, in this order:\n\n```bash\n([\s\S]*?)\n```$/m,
        );

        expect(listing, 'docs/deployment.md no longer lists what scripts/deploy.sh runs').not.toBeNull();
        expect(listing[1].split('\n')).toEqual(SEQUENCE);
    });

    it('announces each command before running it: the log of a failed deployment says where it stopped', () => {
        const result = deploy(sandbox(), { fail: 'npm run build' });
        const announced = result.stdout
            .split('\n')
            .filter((line) => line.startsWith('── '))
            .map((line) => line.slice(3));

        expect(announced).toEqual(SEQUENCE.slice(0, 3));
    });

    it('works from any directory: the release is the one the script lives in', () => {
        const box = sandbox();
        const result = deploy(box, { cwd: tmpdir() });

        expect(result.status).toBe(0);
        expect(result.ran).toEqual(SEQUENCE);
        expect(result.inService).toBe(BUILT);
    });

    it('gives every release the same .env and the same storage, before anything is installed', () => {
        const box = sandbox();
        const result = deploy(box);

        expect(result.during[0]).toMatchObject({ call: SEQUENCE[0], env: join(box.site, 'shared', '.env') });
        expect(readlinkSync(join(box.release, 'storage'))).toBe(join(box.site, 'shared', 'storage'));
        // The skeleton the repository ships is gone, and what the release writes lands in what
        // the releases share.
        expect(existsSync(join(box.site, 'shared', 'storage', 'logs', '.gitignore'))).toBe(false);
        expect(read(join(box.site, 'shared', 'storage', 'logs', 'through.log'))).toBe('written through the release\n');
    });
});

describe('deploy.sh — putting the release in service', () => {
    it('serves the previous release during every step, and the new one after the last', () => {
        const result = deploy(sandbox());

        expect(result.during.map(({ current }) => current)).toEqual(SEQUENCE.map(() => join('releases', SERVED)));
        expect(result.inService).toBe(BUILT);
    });

    it('changes one link and nothing else: the release it replaces is left as it was', () => {
        const box = sandbox();
        deploy(box);

        expect(lstatSync(paths(box.site).current).isSymbolicLink()).toBe(true);
        expect(readlinkSync(paths(box.site).current)).toBe(join('releases', BUILT));
        expect(read(join(box.site, 'releases', SERVED, 'page.txt'))).toBe('the release in service\n');
        expect(existsSync(join(box.site, 'current.next'))).toBe(false);
    });

    it('marks the release as served, and keeps it', () => {
        const box = sandbox();
        deploy(box);

        expect(existsSync(join(box.release, '.served'))).toBe(true);
        expect(existsSync(join(box.release, 'scripts', 'deploy.sh'))).toBe(true);
    });

    it('records the deployment: when, which release, and which one it replaced', () => {
        expect(deploy(sandbox()).lastDeployment).toBe(`${EPOCH} ${BUILT} ${SERVED}\n`);
    });

    it('takes a `current` that leads nowhere for a site with nothing in service', () => {
        // The release it pointed to was removed by hand: there is nothing to replace, and nothing
        // to keep the files of.
        const box = sandbox({ firstRelease: true });
        symlinkSync(join('releases', 'removed-by-hand'), join(box.site, 'current'));
        const result = deploy(box);

        expect(result.stderr).toBe('');
        expect(result.status).toBe(0);
        expect(result.inService).toBe(BUILT);
        expect(result.lastDeployment).toBe(`${EPOCH} ${BUILT} \n`);
    });

    it('puts the first release of a site in service, with nothing recorded before it', () => {
        const result = deploy(sandbox({ firstRelease: true }));

        expect(result.stderr).toBe('');
        expect(result.status).toBe(0);
        expect(result.inService).toBe(BUILT);
        expect(result.lastDeployment).toBe(`${EPOCH} ${BUILT} \n`);
    });

    it('is not stopped by the link a deployment that was killed left half-way', () => {
        const box = sandbox();
        symlinkSync(join('releases', 'gone'), join(box.site, 'current.next'));
        const result = deploy(box);

        expect(result.status).toBe(0);
        expect(result.inService).toBe(BUILT);
        expect(existsSync(join(box.site, 'current.next'))).toBe(false);
    });

    it('still succeeds when the deployment cannot be recorded, and says what is lost', () => {
        // The release is in service: reporting a failure now would be reporting something false.
        const box = sandbox();
        mkdirSync(paths(box.site).lastDeployment);
        const result = deploy(box);

        expect(result.status).toBe(0);
        expect(result.inService).toBe(BUILT);
        expect(result.stderr).toContain('last-deployment could not be written');
        expect(result.stdout).toContain(`Deployed ${NEW}`);
    });
});

describe('deploy.sh — when a step fails', () => {
    it.each(SEQUENCE)('stops at "%s", and leaves the site exactly as it was', (step) => {
        const box = sandbox();
        const result = deploy(box, { fail: step });

        expect(result.status).not.toBe(0);
        expect(result.ran).toEqual(SEQUENCE.slice(0, SEQUENCE.indexOf(step) + 1));
        expect(result.stdout).not.toContain('Deployed');
        expect(result.inService).toBe(SERVED);
        expect(result.lastDeployment).toBeNull();
        expect(read(join(box.site, 'releases', SERVED, 'page.txt'))).toBe('the release in service\n');
    });

    it.each(SEQUENCE)('removes the release it was building when "%s" fails', (step) => {
        // Every directory left in releases/ is then one that was served: a rollback has nothing
        // half built to land on.
        expect(deploy(sandbox(), { fail: step }).releases).toEqual([SERVED]);
    });

    it('removes the release it was building when it is interrupted, and leaves the site as it was', () => {
        // A connection that drops, a workflow that is cancelled: the script is told to stop in
        // the middle of a step.
        const box = sandbox();
        const result = deploy(box, { interrupt: 'npm run build' });

        expect(result.status).not.toBe(0);
        expect(result.ran).toEqual(SEQUENCE.slice(0, 3));
        expect(result.releases).toEqual([SERVED]);
        expect(result.inService).toBe(SERVED);
        expect(result.lastDeployment).toBeNull();
    });

    it('never removes the release in service, even interrupted the instant after the switch', () => {
        // The one moment where "this release was not put in service" could be believed wrongly.
        // Removing it then would leave the link pointing at nothing: a site that is down.
        const box = sandbox();
        const result = deploy(box, { bin: 'bin-interrupted' });

        expect(result.inService).toBe(BUILT);
        expect(existsSync(join(box.release, 'public', 'index.php'))).toBe(true);
        expect(result.releases).toContain(BUILT);
    });

    it('stops before installing anything when the commit ships a .env of its own', () => {
        // The configuration of a release is the one the releases share, never one a commit brought.
        const box = sandbox();
        writeFileSync(join(box.release, '.env'), 'APP_KEY=committed-by-mistake\n');
        const result = deploy(box);

        expect(result.status).not.toBe(0);
        expect(result.ran).toEqual([]);
        expect(result.inService).toBe(SERVED);
        expect(result.releases).toEqual([SERVED]);
        expect(read(join(box.site, 'shared', '.env'))).toBe('APP_ENV=production\n');
    });

    it('fails at the switch, and removes its release, when `current` is a directory and not a link', () => {
        const box = sandbox({ firstRelease: true });
        mkdirSync(join(box.site, 'current'));
        writeFileSync(join(box.site, 'current', 'by-hand.txt'), 'put there by hand\n');
        const result = deploy(box);

        expect(result.status).not.toBe(0);
        expect(result.stdout).not.toContain('Deployed');
        expect(read(join(box.site, 'current', 'by-hand.txt'))).toBe('put there by hand\n');
        expect(result.releases).toEqual([]);
    });

    it('leaves what the releases share where it is when it removes the release', () => {
        const box = sandbox();
        deploy(box, { fail: 'php artisan config:cache' });

        expect(read(join(box.site, 'shared', '.env'))).toBe('APP_ENV=production\n');
        expect(read(join(box.site, 'shared', 'storage', 'logs', 'through.log'))).toBe('written through the release\n');
    });
});

describe('deploy.sh — what it says when it fails', () => {
    // The command that failed says why. The script says the rest: what it was doing, what the site
    // serves now, and what became of the release it was building — the three things somebody
    // reading the log of a failed deployment needs before deciding what to do.
    it.each(SEQUENCE)('names "%s" as what it was running', (step) => {
        const result = deploy(sandbox(), { fail: step });

        expect(result.stderr).toContain(`Deployment of ${NEW} FAILED while running "${step}" (exit status 1).`);
        expect(result.journal).toEqual([`failed ${NEW} while running "${step}" (exit 1)`]);
    });

    it('says what the site still serves, and that nothing is left to clean up', () => {
        const result = deploy(sandbox(), { fail: 'npm run build' });

        expect(result.stderr).toContain(`The site serves what it served before: ${OLD}, release ${SERVED}.`);
        expect(result.stderr).toContain('The release that was being built was removed: nothing is left to clean up.');
        // After the words of the command that failed, not instead of them.
        expect(result.stderr.indexOf('stand-in: "npm run build" failed')).toBeLessThan(
            result.stderr.indexOf('FAILED while'),
        );
    });

    it.each([
        ['preparing the release', 'ln#1'],
        ['keeping the compiled files of the release in service', 'cp#1'],
        ['putting the release in service', 'mv#1'],
    ])('says "%s" when that is where it stopped', (doing, full) => {
        const box = sandbox();
        const served = join(box.site, 'releases', SERVED);
        mkdirSync(join(served, 'public', 'build', 'assets'), { recursive: true });
        writeFileSync(join(served, 'public', 'build', 'assets', 'app-OLD.js'), 'old\n');
        writeFileSync(join(served, '.built-assets'), 'app-OLD.js\n');
        const result = deploy(box, { builds: ['app-NEW.js'], full });

        expect(result.stderr).toContain(`Deployment of ${NEW} FAILED while ${doing} (exit status 1).`);
        expect(result.journal).toEqual([`failed ${NEW} while ${doing} (exit 1)`]);
    });

    it.each([
        ['the job was cancelled', 'TERM', 143],
        ['the connection dropped', 'HUP', 129],
        ['somebody pressed Ctrl-C', 'INT', 130],
        ['nobody was reading its output any more', 'PIPE', 141],
    ])('says that it was interrupted, and fails, when %s', (_, signal, status) => {
        // Left to itself, bash ends an interrupted script with the status of the command it was
        // waiting for: a deployment cut in the middle of a step that had gone well said "exit
        // status 0" in the first version of this report.
        const box = sandbox();
        const result = deploy(box, { interrupt: 'npm run build', signal });

        expect(result.status).toBe(status);
        expect(result.stderr).toContain(
            `Deployment of ${NEW} FAILED while running "npm run build" (exit status ${status}, interrupted by a signal).`,
        );
        expect(result.journal).toEqual([`failed ${NEW} while running "npm run build" (exit ${status})`]);
        expect(result.inService).toBe(SERVED);
        expect(result.releases).toEqual([SERVED]);
    });

    it('says that the site serves nothing yet, when its first release fails', () => {
        const result = deploy(sandbox({ firstRelease: true }), { fail: 'composer install' });

        expect(result.stderr).toContain('The site serves what it served before: nothing yet.');
    });

    it('says that the release is in service when it is stopped after the switch, rather than that it failed', () => {
        const result = deploy(sandbox(), { bin: 'bin-interrupted' });

        expect(result.stderr).toContain(
            `Release ${BUILT} is in service, and the script stopped before it had finished (exit status 143).`,
        );
        expect(result.stderr).not.toContain('FAILED');
        expect(result.journal).toEqual([`deployed ${NEW} as ${BUILT}, then stopped (exit 143)`]);
    });

    it('says nothing of a failure when there was none, and writes what it did', () => {
        const result = deploy(sandbox());

        expect(result.stderr).toBe('');
        expect(result.stdout).toContain(`Release ${BUILT} is in service; it replaced ${SERVED}.`);
        expect(result.journal).toEqual([`deployed ${NEW} as ${BUILT}, replacing ${SERVED}`]);
    });

    it('says that it replaced nothing, for the first release of a site', () => {
        const result = deploy(sandbox({ firstRelease: true }));

        expect(result.stdout).toContain(`Release ${BUILT} is in service; it replaced nothing.`);
        expect(result.journal).toEqual([`deployed ${NEW} as ${BUILT}, replacing nothing`]);
    });

    it('dates each line of its journal, and adds to what is there', () => {
        const box = sandbox();
        const journal = join(box.site, 'shared', 'storage', 'logs', 'deployments.log');
        writeFileSync(journal, 'an earlier line\n');
        deploy(box);

        expect(lines(journal)).toEqual([
            'an earlier line',
            expect.stringMatching(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z deployed /),
        ]);
    });

    it.each([
        ['a deployment that succeeds', {}, 0],
        ['a deployment that fails', { fail: 'npm ci' }, 1],
    ])('is not changed by a journal that cannot be written: %s', (_, options, status) => {
        // The journal is a trace, never a step: a full disk under it changes neither outcome.
        const box = sandbox();
        symlinkSync('/dev/full', join(box.site, 'shared', 'storage', 'logs', 'deployments.log'));
        const result = deploy(box, options);

        expect(result.status).toBe(status);
        expect(result.stderr).toContain('deployments.log could not be written.');
        expect(result.inService).toBe(status === 0 ? BUILT : SERVED);
    });
});

describe('deploy.sh — when the disk is full', () => {
    // Not "a step fails": the steps are other programs, and their failure is tested above. These
    // are the script's own writes, each made to fail in turn the way a full disk makes it fail.
    // After each, one thing is asked: is the site entirely the release it was serving?
    const withAssets = (box) => {
        const served = join(box.site, 'releases', SERVED);
        mkdirSync(join(served, 'public', 'build', 'assets'), { recursive: true });
        writeFileSync(join(served, 'public', 'build', 'assets', 'app-OLD.js'), 'old\n');
        writeFileSync(join(served, '.built-assets'), 'app-OLD.js\n');
        return box;
    };

    it.each([
        ['removing the storage skeleton', 'rm#1'],
        ['linking the shared storage', 'ln#1'],
        ['linking the shared .env', 'ln#2'],
        ['making room for the previous compiled files', 'mkdir#1'],
        ['copying a previous compiled file', 'cp#1'],
        ['marking the release as served', 'touch#1'],
        ['preparing the new link', 'ln#3'],
        ['moving the link', 'mv#1'],
    ])('leaves the site entirely on the release in service when %s fails', (_, full) => {
        const box = withAssets(sandbox());
        const result = deploy(box, { builds: ['app-NEW.js'], full });

        expect(result.stderr).toContain('No space left on device');
        expect(result.status).not.toBe(0);
        expect(result.stdout).not.toContain('Deployed');
        expect(result.inService).toBe(SERVED);
        expect(read(join(box.site, 'releases', SERVED, 'page.txt'))).toBe('the release in service\n');
        // Nothing half built to land on, nothing recorded, no link left half-way.
        expect(result.releases).toEqual([SERVED]);
        expect(result.lastDeployment).toBeNull();
        expect(existsSync(join(box.site, 'current.next'))).toBe(false);
    });

    it('reaches every one of those writes in a deployment that goes well', () => {
        // If a write were renamed or removed, a case above would fail a call that never comes, and
        // pass for having tested nothing.
        const box = withAssets(sandbox());
        deploy(box, { builds: ['app-NEW.js'] });
        const calls = (tool) => Number(read(join(box.root, `${tool}.calls`)));

        expect(Object.fromEntries(WRITERS.map((tool) => [tool, calls(tool)]))).toEqual({
            rm: 1,
            ln: 3,
            cp: 1,
            touch: 1,
            mv: 1,
            mkdir: 1,
        });
    });

    it('stops when the list of its compiled files cannot be written, and leaves the site as it was', () => {
        // The real thing: /dev/full is the device the kernel answers "no space left" from.
        const box = sandbox();
        symlinkSync('/dev/full', join(box.release, '.built-assets'));
        const result = deploy(box, { builds: ['app-NEW.js'] });

        expect(result.stderr).toContain('No space left on device');
        expect(result.status).not.toBe(0);
        expect(result.ran).toEqual(SEQUENCE.slice(0, 3));
        expect(result.inService).toBe(SERVED);
        expect(result.releases).toEqual([SERVED]);
    });

    it('still succeeds when the disk fills after the switch: the release is in service, and it says what is lost', () => {
        const box = sandbox();
        symlinkSync('/dev/full', paths(box.site).lastDeployment);
        const result = deploy(box);

        expect(result.status).toBe(0);
        expect(result.inService).toBe(BUILT);
        expect(result.stderr).toContain('last-deployment could not be written');
        expect(result.stdout).toContain(`Deployed ${NEW}`);
    });
});

describe('deploy.sh — where it agrees to run', () => {
    // It deletes the directory it sits in when it fails. Each of these is a directory that must
    // survive being asked.
    it.each(['--force', 'main', NEW, ''])('refuses the argument %j: it builds the release it sits in', (argument) => {
        const box = sandbox();
        const result = deploy(box, { args: [argument] });

        expectRefused(box, result);
        expect(result.stderr).toContain('takes no argument');
    });

    it('refuses to run without the deployment lock: scripts/release.sh is the way in', () => {
        const box = sandbox();
        const result = deploy(box, { lockFd: null });

        expectRefused(box, result);
        expect(result.stderr).toContain('builds a release that scripts/release.sh <commit> has just made');
    });

    it('refuses a lock that is announced and held by nobody: it measures what it is told', () => {
        // A variable left in an environment says "locked" for ever. Two deployments would then
        // build side by side, each believing the other could not exist.
        const box = sandbox();
        const result = deploy(box, { locked: false });

        expectRefused(box, result);
        expect(result.stderr).toContain('the deployment lock is announced as held and is not');
    });

    it.each([
        ['is not named like a release', (box) => extracted(box, 'bootstrap', NEW)],
        // Its name ends with the commit it holds, and still is not one a rollback could ever name.
        ['is named after its commit and no moment', (box) => extracted(box, `candidate-${NEW}`, NEW)],
        ['is named after another commit than the one it holds', (box) => extracted(box, name(NEW, '090000'), OLD)],
        [
            'has no RELEASE file',
            (box) => {
                const release = extracted(box, name(NEW, '090000'), NEW);
                rmSync(join(release, 'RELEASE'));
                return release;
            },
        ],
        [
            'was already served',
            (box) => {
                const release = extracted(box, name(NEW, '090000'), NEW);
                writeFileSync(join(release, '.served'), '');
                return release;
            },
        ],
        [
            'is named like a release and sits outside releases/',
            (box) => {
                const release = extracted(box, name(NEW, '090000'), NEW);
                const elsewhere = join(box.site, 'elsewhere', name(NEW, '090000'));
                mkdirSync(join(box.site, 'elsewhere'));
                cpSync(release, elsewhere, { recursive: true });
                return elsewhere;
            },
        ],
    ])('refuses a directory that %s, and does not touch it', (_, make) => {
        const box = sandbox();
        const release = make(box);
        const result = deploy(box, { release });

        expectRefused(box, result, release);
        expect(result.stderr).toContain('builds a release that scripts/release.sh <commit> has just made');
        expect(result.inService).toBe(SERVED);
    });

    it('refuses the release in service: a release is built once', () => {
        const box = sandbox();
        const served = join(box.site, 'releases', SERVED);
        mkdirSync(join(served, 'scripts'));
        cpSync(inRepository('scripts/deploy.sh'), join(served, 'scripts', 'deploy.sh'));
        const result = deploy(box, { release: served });

        expectRefused(box, result, served);
        expect(read(join(served, 'page.txt'))).toBe('the release in service\n');
    });

    it.each([
        ['no .env', (shared) => rmSync(join(shared, '.env'))],
        ['no storage', (shared) => rmSync(join(shared, 'storage'), { recursive: true })],
        [
            'a directory where .env should be',
            (shared) => {
                rmSync(join(shared, '.env'));
                mkdirSync(join(shared, '.env'));
            },
        ],
    ])('refuses a site whose shared directory has %s, and does not touch the release', (_, damage) => {
        const box = sandbox();
        damage(paths(box.site).shared);
        const result = deploy(box);

        expectRefused(box, result);
        expect(result.stderr).toContain('shared has no .env or no storage/');
    });
});

describe('deploy.sh — the compiled files of the release it replaces', () => {
    const assets = (release) => join(release, 'public', 'build', 'assets');
    const give = (box, files, listed) => {
        const served = join(box.site, 'releases', SERVED);
        mkdirSync(assets(served), { recursive: true });
        files.forEach((file) => writeFileSync(join(assets(served), file), `built by ${OLD}\n`));
        if (listed !== null) {
            writeFileSync(join(served, '.built-assets'), listed.map((file) => `${file}\n`).join(''));
        }
    };

    it('lists the files it built itself', () => {
        const box = sandbox();
        deploy(box, { builds: ['app-NEW.js', 'app-NEW.css'] });

        expect(lines(join(box.release, '.built-assets'))).toEqual(['app-NEW.css', 'app-NEW.js']);
    });

    it('keeps next to them the files the release in service had built: its pages still name them', () => {
        const box = sandbox();
        give(box, ['app-OLD.js', 'app-OLD.css'], ['app-OLD.js', 'app-OLD.css']);
        deploy(box, { builds: ['app-NEW.js'] });

        expect(readdirSync(assets(box.release)).sort()).toEqual(['app-NEW.js', 'app-OLD.css', 'app-OLD.js']);
        expect(read(join(assets(box.release), 'app-OLD.js'))).toBe(`built by ${OLD}\n`);
        // Only its own: the next release will carry these, not the ones before them.
        expect(lines(join(box.release, '.built-assets'))).toEqual(['app-NEW.js']);
    });

    it('does not carry what the release in service had itself only kept: one release back, no further', () => {
        const box = sandbox();
        give(box, ['app-OLD.js', 'app-OLDER.js'], ['app-OLD.js']);
        deploy(box, { builds: ['app-NEW.js'] });

        expect(readdirSync(assets(box.release)).sort()).toEqual(['app-NEW.js', 'app-OLD.js']);
    });

    it('never replaces a file it has just built by an older one of the same name', () => {
        const box = sandbox();
        give(box, ['app-SAME.js'], ['app-SAME.js']);
        deploy(box, { builds: ['app-SAME.js'] });

        expect(read(join(assets(box.release), 'app-SAME.js'))).toBe(`built by ${NEW}\n`);
    });

    it.each([
        [
            'listed a file that is no longer there',
            ['app-OLD.js'],
            ['app-OLD.js', 'app-GONE.js'],
            ['app-NEW.js', 'app-OLD.js'],
        ],
        ['kept no list, as a release from before this script', ['app-OLD.js'], null, ['app-NEW.js']],
    ])('goes on when the release in service %s', (_, files, listed, expected) => {
        const box = sandbox();
        give(box, files, listed);
        const result = deploy(box, { builds: ['app-NEW.js'] });

        expect(result.status).toBe(0);
        expect(readdirSync(assets(box.release)).sort()).toEqual(expected);
    });

    it('reads the list as names, never as paths: a line that climbs out of the directory is skipped', () => {
        const box = sandbox();
        give(box, ['app-OLD.js'], ['../../../secret.txt', 'app-OLD.js']);
        writeFileSync(join(box.site, 'releases', SERVED, 'secret.txt'), 'not a compiled file\n');
        const result = deploy(box, { builds: ['app-NEW.js'] });

        expect(result.status).toBe(0);
        expect(existsSync(join(box.release, 'secret.txt'))).toBe(false);
        expect(readdirSync(assets(box.release)).sort()).toEqual(['app-NEW.js', 'app-OLD.js']);
    });

    it('keeps them even when it built none itself', () => {
        const box = sandbox();
        give(box, ['app-OLD.js'], ['app-OLD.js']);
        const result = deploy(box, { builds: [] });

        expect(result.status).toBe(0);
        expect(readdirSync(assets(box.release))).toEqual(['app-OLD.js']);
        expect(read(join(box.release, '.built-assets'))).toBe('');
    });

    it('has them in place right after the build, long before the release is put in service', () => {
        // The database is about to change and the link to move: from here on, a page of either
        // generation has to find its files.
        const box = sandbox();
        give(box, ['app-OLD.js'], ['app-OLD.js']);
        deploy(box, { builds: ['app-NEW.js'] });

        expect(lines(join(box.root, 'assets-at-migrate'))).toEqual(['app-NEW.js', 'app-OLD.js']);
    });
});

describe('deploy.sh — the releases it lets go', () => {
    const served = (box, times) =>
        times.map((time) => {
            const release = name(OLD, time);
            addRelease(box.site, release, { sha: OLD });
            return release;
        });

    it('keeps five served releases, the one in service included, and removes the older ones', () => {
        const box = sandbox();
        const older = served(box, ['010000', '020000', '030000', '040000', '050000', '060000']);
        const result = deploy(box);

        expect(result.status).toBe(0);
        expect(result.releases).toEqual([...older.slice(3), SERVED, BUILT].sort());
    });

    it('removes what was never served: what a deployment that was killed left behind', () => {
        const box = sandbox();
        extracted(box, name(OLD, '073000'), OLD);
        extracted(box, 'bootstrap', OLD);
        const result = deploy(box);

        expect(result.releases).toEqual([SERVED, BUILT]);
    });

    it('always keeps the release it has just replaced, however old: it is where a rollback goes', () => {
        // After rollbacks, the release in service can be the oldest on disk.
        const box = sandbox({ firstRelease: true });
        const all = served(box, ['010000', '020000', '030000', '040000', '050000', '060000', '070000']);
        serve(box.site, all[0]);
        const result = deploy(box);

        expect(result.lastDeployment).toBe(`${EPOCH} ${BUILT} ${all[0]}\n`);
        expect(result.releases).toEqual([all[0], ...all.slice(3), BUILT].sort());
    });

    it('touches nothing in releases/ that is not a directory of its own', () => {
        // A note left there, a hidden directory, and a link towards somewhere else: removing "what
        // was never served" must never follow that link.
        const box = sandbox();
        const outside = join(box.root, 'outside');
        mkdirSync(outside);
        writeFileSync(join(outside, 'precious.txt'), 'not a release\n');
        symlinkSync(outside, join(box.site, 'releases', 'link-to-outside'));
        writeFileSync(join(box.site, 'releases', 'NOTES.txt'), 'by hand\n');
        mkdirSync(join(box.site, 'releases', '.hidden'));
        const result = deploy(box);

        expect(result.status).toBe(0);
        expect(read(join(outside, 'precious.txt'))).toBe('not a release\n');
        expect(read(join(box.site, 'releases', 'NOTES.txt'))).toBe('by hand\n');
        expect(existsSync(join(box.site, 'releases', '.hidden'))).toBe(true);
    });

    it('still succeeds when an old release cannot be removed, and says so', () => {
        const box = sandbox();
        const [stuck] = served(box, ['010000', '020000', '030000', '040000', '050000']).slice(0, 1);
        const locked = join(box.site, 'releases', stuck, 'locked');
        mkdirSync(locked);
        writeFileSync(join(locked, 'file'), '');
        chmodSync(locked, 0o500);
        try {
            const result = deploy(box);

            expect(result.status).toBe(0);
            expect(result.inService).toBe(BUILT);
            expect(result.stderr).toContain('Warning: some old releases could not be removed.');
            expect(result.stdout).toContain(`Deployed ${NEW}`);
        } finally {
            chmodSync(locked, 0o700);
        }
    });
});
