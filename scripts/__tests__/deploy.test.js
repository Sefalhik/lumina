// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JOURNAL, callsIn, fillPath } from './support/sandbox.js';

// scripts/deploy.sh, run for real in a throwaway git checkout. composer, npm and php are stand-ins
// that record how they were called; git is the real one, since the release is named after HEAD.
//
// The script is copied into the checkout before each run: it works on the checkout it lives in,
// and run from here it would install, migrate and cache this repository.
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
// The release is named between these two: after everything that can fail on the new code, and
// before the command that freezes the name into the configuration.
const LAST_STEP_BEFORE_RELEASE = 'php artisan db:seed --class=HomepageContentSeeder --force --no-interaction';

// Fails when asked to, by the start of its command line. php also keeps what RELEASE held at the
// moment config:cache ran, which is the only moment that counts.
const STANDIN = `${JOURNAL}
call="\${0##*/} $*"
if [[ "$call" == 'php artisan config:cache' && -f RELEASE ]]; then
  printf '%s' "$(< RELEASE)" > "$STUB_DIR/release-when-cached"
fi
if [[ -n "\${STUB_FAIL:-}" && "$call" == "$STUB_FAIL"* ]]; then
  printf 'stand-in: "%s" failed\\n' "$call" >&2
  exit 1
fi
`;

const GIT_ENV = {
    ...process.env,
    GIT_CONFIG_GLOBAL: '/dev/null',
    GIT_CONFIG_NOSYSTEM: '1',
    GIT_AUTHOR_NAME: 'Test',
    GIT_AUTHOR_EMAIL: 'test@example.org',
    GIT_COMMITTER_NAME: 'Test',
    GIT_COMMITTER_EMAIL: 'test@example.org',
};

function git(cwd, ...args) {
    const result = spawnSync('git', args, { cwd, encoding: 'utf8', env: GIT_ENV });
    if (result.status !== 0) {
        throw new Error(`git ${args.join(' ')} failed: ${result.stderr}`);
    }
    return result.stdout.trim();
}

let template;
let head;
const sandboxes = [];

beforeAll(() => {
    template = mkdtempSync(join(tmpdir(), 'deploy-template-'));
    const project = join(template, 'project');
    mkdirSync(join(project, 'public'), { recursive: true });
    writeFileSync(join(project, 'public', 'index.php'), '<?php\n');
    git(project, 'init', '--quiet', '-b', 'main');
    git(project, 'add', '.');
    git(project, 'commit', '--quiet', '-m', 'A release');
    head = git(project, 'rev-parse', 'HEAD');
    fillPath(join(template, 'bin'), {
        real: ['bash', 'git', 'dirname'],
        standins: { composer: STANDIN, npm: STANDIN, php: STANDIN },
    });
});

afterAll(() => rmSync(template, { recursive: true, force: true }));

afterEach(() => {
    sandboxes.splice(0).forEach((root) => rmSync(root, { recursive: true, force: true }));
});

/**
 * A checkout with the script in it, and what the outside world looks like from there.
 *
 * @param {{previousRelease?: string|null}} [options]
 */
function sandbox({ previousRelease = null } = {}) {
    const root = mkdtempSync(join(tmpdir(), 'deploy-'));
    sandboxes.push(root);
    cpSync(template, root, { recursive: true, verbatimSymlinks: true });

    const box = { root, project: join(root, 'project'), bin: join(root, 'bin'), log: join(root, 'calls.log') };
    mkdirSync(join(box.project, 'scripts'));
    cpSync(inRepository('scripts/deploy.sh'), join(box.project, 'scripts', 'deploy.sh'));
    if (previousRelease !== null) {
        writeFileSync(join(box.project, 'RELEASE'), previousRelease);
    }
    return box;
}

/**
 * @param {object} box
 * @param {{args?: string[], fail?: string, cwd?: string}} [options]
 */
function deploy(box, { args = [], fail = '', cwd = box.project } = {}) {
    const result = spawnSync(join(box.bin, 'bash'), [join(box.project, 'scripts', 'deploy.sh'), ...args], {
        cwd,
        encoding: 'utf8',
        env: {
            PATH: box.bin,
            HOME: box.root,
            GIT_CONFIG_GLOBAL: '/dev/null',
            GIT_CONFIG_NOSYSTEM: '1',
            STUB_LOG: box.log,
            STUB_DIR: box.root,
            STUB_FAIL: fail,
        },
    });
    const read = (path) => (existsSync(path) && statSync(path).isFile() ? readFileSync(path, 'utf8') : null);

    return {
        status: result.status,
        stdout: result.stdout,
        stderr: result.stderr,
        ran: callsIn(box.log).map((call) => call.join(' ')),
        release: read(join(box.project, 'RELEASE')),
        releaseWhenCached: read(join(box.root, 'release-when-cached')),
    };
}

describe('deploy.sh — the sequence', () => {
    it('runs the whole sequence, in order, and nothing else', () => {
        const result = deploy(sandbox());

        expect(result.stderr).toBe('');
        expect(result.status).toBe(0);
        expect(result.ran).toEqual(SEQUENCE);
        expect(result.stdout).toContain(`Deployed ${head}`);
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

    it('works from any directory: the checkout is the one the script lives in', () => {
        const box = sandbox();
        const result = deploy(box, { cwd: box.root });

        expect(result.status).toBe(0);
        expect(result.ran).toEqual(SEQUENCE);
        expect(result.release).toBe(`${head}\n`);
        expect(existsSync(join(box.root, 'RELEASE'))).toBe(false);
    });

    it.each(SEQUENCE)('stops at "%s" when it fails, and runs nothing after it', (step) => {
        const result = deploy(sandbox(), { fail: step });

        expect(result.status).not.toBe(0);
        expect(result.ran).toEqual(SEQUENCE.slice(0, SEQUENCE.indexOf(step) + 1));
        expect(result.stdout).not.toContain('Deployed');
    });

    it.each(['--force', 'main', '0123456789abcdef0123456789abcdef01234567', ''])(
        'refuses the argument %j: it deploys what is checked out, and nothing it is told to',
        (argument) => {
            const result = deploy(sandbox(), { args: [argument] });

            expect(result.status).toBe(1);
            expect(result.stderr).toContain('takes no argument');
            expect(result.ran).toEqual([]);
            expect(result.release).toBeNull();
        },
    );

    it('refuses to deploy what is not a git checkout, before installing anything', () => {
        const box = sandbox();
        rmSync(join(box.project, '.git'), { recursive: true });
        const result = deploy(box);

        expect(result.status).not.toBe(0);
        expect(result.ran).toEqual([]);
        expect(result.release).toBeNull();
    });
});

describe('deploy.sh — the release it names', () => {
    it('writes the SHA of the checked-out commit into RELEASE', () => {
        expect(deploy(sandbox({ previousRelease: 'the previous release\n' })).release).toBe(`${head}\n`);
    });

    it('has written it by the time config:cache freezes the configuration', () => {
        // config/app.php reads RELEASE when the configuration is cached. Written a line later, the
        // file would be right and every response would still carry the previous release.
        expect(deploy(sandbox({ previousRelease: 'the previous release\n' })).releaseWhenCached).toBe(head);
    });

    it.each(SEQUENCE.slice(0, SEQUENCE.indexOf(LAST_STEP_BEFORE_RELEASE) + 1))(
        'keeps the previous release when "%s" fails',
        (step) => {
            // The code on disk is the new one, half installed. What the environment announces must
            // stay what it was, so that a smoke test expecting the new SHA fails.
            const result = deploy(sandbox({ previousRelease: 'the previous release\n' }), { fail: step });

            expect(result.status).not.toBe(0);
            expect(result.release).toBe('the previous release\n');
        },
    );

    it('caches nothing when the release cannot be named', () => {
        // Going on would freeze the previous name into the configuration of the new code: every
        // response would then announce a release that is no longer the one serving it.
        const box = sandbox();
        mkdirSync(join(box.project, 'RELEASE'));
        const result = deploy(box);

        expect(result.status).not.toBe(0);
        expect(result.ran).toEqual(SEQUENCE.slice(0, SEQUENCE.indexOf(LAST_STEP_BEFORE_RELEASE) + 1));
    });

    it('creates no RELEASE on a first deployment that fails early', () => {
        expect(deploy(sandbox(), { fail: 'npm run build' }).release).toBeNull();
    });
});
