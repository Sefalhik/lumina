// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { spawn, spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JOURNAL, fillPath } from './support/sandbox.js';
import { DATE, EPOCH, git, inService, isLocked, layOut, lines, paths, read, releaseName } from './support/site.js';

// The four deployment scripts together, each one the real one, from the first release of an empty
// site to a rollback asked through the gate. Their own suites prove what each does with what it is
// handed; this one proves that what one hands over is what the next expects — a directory name, an
// environment variable, a file in shared/ — which no suite of a single script can see.
//
//   main    c1 ── c2 ── c3        each carries the four scripts; only page.txt changes
//
// composer, npm and php are stand-ins; git, tar and flock are real.
const SCRIPTS = ['deploy.sh', 'deploy-gate.sh', 'release.sh', 'rollback.sh'];
const inRepository = (path) => fileURLToPath(new URL(`../../${path}`, import.meta.url));

const TOOL = `${JOURNAL}
if [[ "\${0##*/} $*" == 'npm run build' ]]; then
  mkdir -p public/build/assets
  printf 'built\\n' > "public/build/assets/app-$(cut -c1-8 RELEASE).js"
fi
# A step that never ends, for the test that kills a deployment in the middle of one.
if [[ -n "\${STUB_HANG:-}" && "\${0##*/} $*" == "$STUB_HANG"* ]]; then
  : > "$STUB_HANGING"
  exec sleep 60
fi
`;

let template;
const commit = {};
const sandboxes = [];

beforeAll(() => {
    template = mkdtempSync(join(tmpdir(), 'deployment-chain-template-'));
    const work = join(template, 'work');
    const site = join(template, 'site');
    const record = (page) => {
        writeFileSync(join(work, 'page.txt'), `${page}\n`);
        git(work, 'add', '--all', '.');
        git(work, 'commit', '--quiet', '-m', page);
        return git(work, 'rev-parse', 'HEAD');
    };

    git(template, 'init', '--quiet', '-b', 'main', 'work');
    mkdirSync(join(work, 'scripts'));
    mkdirSync(join(work, 'storage', 'logs'), { recursive: true });
    writeFileSync(join(work, 'storage', 'logs', '.gitignore'), '*\n!.gitignore\n');
    SCRIPTS.forEach((script) => cpSync(inRepository(`scripts/${script}`), join(work, 'scripts', script)));
    commit.c1 = record('first');
    commit.c2 = record('second');
    commit.c3 = record('third');

    git(template, 'clone', '--quiet', '--bare', 'work', 'origin.git');
    layOut(site);
    git(template, 'clone', '--quiet', '--bare', 'origin.git', join('site', 'repo'));

    fillPath(join(template, 'bin'), {
        real: [
            'bash',
            'git',
            'tar',
            'flock',
            'dirname',
            'basename',
            'cat',
            'cut',
            'rm',
            'ln',
            'touch',
            'mv',
            'ls',
            'cp',
            'mkdir',
            'sleep',
            'readlink',
        ],
        standins: { composer: TOOL, npm: TOOL, php: TOOL, date: DATE },
    });
});

afterAll(() => rmSync(template, { recursive: true, force: true }));

afterEach(() => {
    sandboxes.splice(0).forEach((root) => rmSync(root, { recursive: true, force: true }));
});

function sandbox() {
    const root = mkdtempSync(join(tmpdir(), 'deployment-chain-'));
    sandboxes.push(root);
    cpSync(template, root, { recursive: true, verbatimSymlinks: true });

    const box = { root, site: join(root, 'site'), bin: join(root, 'bin') };
    git(paths(box.site).repo, 'remote', 'set-url', 'origin', join(root, 'origin.git'));
    return box;
}

// A minute apart, so that each release gets a name of its own and the gate can count the time.
const at = (minute) => ({
    STUB_STAMP: `20261011-08${String(minute).padStart(2, '0')}00`,
    STUB_EPOCH: String(EPOCH + minute * 60),
});
const named = (sha, minute) => releaseName(sha, at(minute).STUB_STAMP);

function run(box, script, args, env) {
    const result = spawnSync(join(box.bin, 'bash'), [script, ...args], {
        cwd: box.root,
        encoding: 'utf8',
        env: {
            PATH: box.bin,
            HOME: box.root,
            GIT_CONFIG_GLOBAL: '/dev/null',
            GIT_CONFIG_NOSYSTEM: '1',
            STUB_LOG: join(box.root, 'calls.log'),
            ...env,
        },
    });
    return { status: result.status, stdout: result.stdout, stderr: result.stderr };
}

// The first release of a site, as docs/deployment.md has a human make it: the scripts of the
// commit are extracted into a directory of releases/, and they do the rest.
function firstRelease(box, sha, minute) {
    const bootstrap = join(box.site, 'releases', 'bootstrap');
    mkdirSync(bootstrap);
    const archive = spawnSync('git', ['-C', paths(box.site).repo, 'archive', sha], { encoding: 'buffer' });
    spawnSync('tar', ['-x', '-C', bootstrap], { input: archive.stdout });
    return run(box, join(bootstrap, 'scripts', 'release.sh'), [sha], at(minute));
}

const ask = (box, request, minute) =>
    run(box, join(paths(box.site).current, 'scripts', 'deploy-gate.sh'), [], {
        ...at(minute),
        SSH_ORIGINAL_COMMAND: request,
        SSH_CONNECTION: '203.0.113.7 51234 198.51.100.1 22',
    });

const served = (box) => read(join(paths(box.site).current, 'RELEASE'))?.trim();
const releases = (box) => readdirSync(paths(box.site).releases).sort();

describe('the deployment scripts, one after the other', () => {
    it('makes the first release of an empty site, and leaves no trace of how it started', () => {
        const box = sandbox();
        const result = firstRelease(box, commit.c1, 0);

        expect(result.stderr).toBe('');
        expect(result.status).toBe(0);
        expect(served(box)).toBe(commit.c1);
        expect(releases(box)).toEqual([named(commit.c1, 0)]);
        expect(read(join(paths(box.site).current, 'page.txt'))).toBe('first\n');
        expect(isLocked(box.site)).toBe(false);
    });

    it('deploys through the gate, then undoes it through the gate, then deploys again', () => {
        const box = sandbox();
        firstRelease(box, commit.c1, 0);

        const deployed = ask(box, `deploy ${commit.c2}`, 1);
        expect(deployed.stderr).toBe('');
        expect(deployed.stdout).toContain(`Deployed ${commit.c2}`);
        expect(served(box)).toBe(commit.c2);
        expect(read(join(paths(box.site).current, 'page.txt'))).toBe('second\n');
        // The compiled files of the release it replaced came along.
        expect(readdirSync(join(paths(box.site).current, 'public', 'build', 'assets')).sort()).toEqual(
            [`app-${commit.c1.slice(0, 8)}.js`, `app-${commit.c2.slice(0, 8)}.js`].sort(),
        );

        const undone = ask(box, `rollback ${commit.c2}`, 2);
        expect(undone.stderr).toBe('');
        expect(undone.stdout).toContain(`Rolled back to ${commit.c1}`);
        expect(inService(box.site)).toBe(named(commit.c1, 0));

        // Undone once. Asking again would be walking back through the releases.
        const again = ask(box, `rollback ${commit.c1}`, 3);
        expect(again.status).toBe(1);
        expect(again.stderr).toContain('Refused:');
        expect(served(box)).toBe(commit.c1);

        const forward = ask(box, `deploy ${commit.c3}`, 4);
        expect(forward.status).toBe(0);
        expect(served(box)).toBe(commit.c3);
        expect(releases(box)).toEqual([named(commit.c1, 0), named(commit.c2, 1), named(commit.c3, 4)]);

        expect(lines(paths(box.site).gateJournal).map((line) => line.split(' ').slice(2).join(' '))).toEqual([
            `accepted deploy ${commit.c2}`,
            `accepted rollback ${commit.c2}`,
            `refused nothing-to-undo ${commit.c1}`,
            `accepted deploy ${commit.c3}`,
        ]);
        expect(isLocked(box.site)).toBe(false);
    });

    it('never goes back for the key, and goes forward again from a release a human went back to', () => {
        // With c3 in service the key is refused c2. A human then goes back to c1 by name, and c2 is
        // ahead of what is served again: the key may deploy it. "Older" is always measured against
        // the release in service, and only a human moves that backwards.
        const box = sandbox();
        firstRelease(box, commit.c1, 0);
        ask(box, `deploy ${commit.c3}`, 1);

        const back = ask(box, `deploy ${commit.c2}`, 2);
        expect(back.status).toBe(1);
        expect(back.stderr).toContain('that commit is older than the one being served');
        expect(served(box)).toBe(commit.c3);

        const byHand = run(box, join(paths(box.site).current, 'scripts', 'rollback.sh'), [named(commit.c1, 0)], at(3));
        expect(byHand.status).toBe(0);
        expect(served(box)).toBe(commit.c1);

        expect(ask(box, `deploy ${commit.c2}`, 4).status).toBe(0);
        expect(served(box)).toBe(commit.c2);
    });
    it('survives a deployment killed outright: the lock is free, the site untouched, the next one cleans up', async () => {
        // SIGKILL runs no trap: nothing is tidied, and nothing has to be. The lock goes with the
        // processes that held it, and what they were building was never served.
        const box = sandbox();
        firstRelease(box, commit.c1, 0);
        const hanging = join(box.root, 'hanging');

        const killed = spawn(join(box.bin, 'bash'), [join(paths(box.site).current, 'scripts', 'deploy-gate.sh')], {
            cwd: box.root,
            detached: true,
            stdio: 'ignore',
            env: {
                PATH: box.bin,
                HOME: box.root,
                GIT_CONFIG_GLOBAL: '/dev/null',
                GIT_CONFIG_NOSYSTEM: '1',
                STUB_LOG: join(box.root, 'calls.log'),
                STUB_HANG: 'npm run build',
                STUB_HANGING: hanging,
                SSH_ORIGINAL_COMMAND: `deploy ${commit.c2}`,
                ...at(1),
            },
        });
        const gone = new Promise((resolve) => killed.on('exit', resolve));
        const deadline = Date.now() + 10000;
        while (!existsSync(hanging)) {
            if (Date.now() > deadline) {
                process.kill(-killed.pid, 'SIGKILL');
                throw new Error('The deployment never reached the step it was meant to be killed in.');
            }
            await new Promise((resolve) => setTimeout(resolve, 20));
        }

        // While it runs: the lock is held, and a second deployment is refused as busy.
        expect(isLocked(box.site)).toBe(true);
        const meanwhile = ask(box, `deploy ${commit.c3}`, 2);
        expect(meanwhile.status).toBe(1);
        expect(meanwhile.stderr).toContain('a deployment is already running on this server.');

        process.kill(-killed.pid, 'SIGKILL');
        await gone;

        expect(isLocked(box.site)).toBe(false);
        expect(served(box)).toBe(commit.c1);
        // What it was building is still there, half built, and was never served.
        expect(releases(box)).toEqual([named(commit.c1, 0), named(commit.c2, 1)]);

        const next = ask(box, `deploy ${commit.c3}`, 3);
        expect(next.status).toBe(0);
        expect(served(box)).toBe(commit.c3);
        expect(releases(box)).toEqual([named(commit.c1, 0), named(commit.c3, 3)]);
    });
});
