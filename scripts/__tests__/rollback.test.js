// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readdirSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JOURNAL, failingWhenFull, fillPath } from './support/sandbox.js';
import {
    EPOCH,
    LOCK_STATE,
    addRelease,
    holdLock,
    inService,
    isLocked,
    layOut,
    lines,
    paths,
    read,
    releaseName,
    serve,
} from './support/site.js';

// scripts/rollback.sh, run for real in a throwaway site holding three releases that were served:
//
//   A  ──  B  ──  C        C is in service; B is the one it replaced; A is older
//
// php is a stand-in that notes where it was run, which release was in service at that moment, and
// whether the deployment lock was held.
const ROLLBACK = fileURLToPath(new URL('../rollback.sh', import.meta.url));

const sha = (letter) => letter.repeat(40);
const A = releaseName(sha('a'), '20261011-060000');
const B = releaseName(sha('b'), '20261011-070000');
const C = releaseName(sha('c'), '20261011-080000');

const PHP = `${JOURNAL}
${LOCK_STATE}
printf '%s\\x1f%s\\x1f%s\\x1f%s\\n' "$*" "\${PWD##*/}" "$(readlink "$STUB_SITE/current")" "$lock_state" >> "$STUB_DIR/state.log"
[[ -z "\${STUB_FAIL:-}" ]]
`;

let template;
const sandboxes = [];

beforeAll(() => {
    template = mkdtempSync(join(tmpdir(), 'rollback-template-'));
    const site = join(template, 'site');
    layOut(site);
    [A, B, C].forEach((name) => {
        const release = addRelease(site, name, { sha: name.slice(-40), files: { 'page.txt': `${name}\n` } });
        mkdirSync(join(release, 'scripts'));
        cpSync(ROLLBACK, join(release, 'scripts', 'rollback.sh'));
    });
    serve(site, C);
    fillPath(join(template, 'bin'), {
        real: ['bash', 'dirname', 'basename', 'cat', 'rm', 'readlink', 'flock'],
        standins: { php: PHP, ln: failingWhenFull('ln'), mv: failingWhenFull('mv') },
    });
});

afterAll(() => rmSync(template, { recursive: true, force: true }));

afterEach(() => {
    sandboxes.splice(0).forEach((root) => rmSync(root, { recursive: true, force: true }));
});

/**
 * @param {{record?: string|null}} [options]  what shared/last-deployment holds; null for no file
 */
function sandbox({ record = `${EPOCH} ${C} ${B}\n` } = {}) {
    const root = mkdtempSync(join(tmpdir(), 'rollback-'));
    sandboxes.push(root);
    cpSync(template, root, { recursive: true, verbatimSymlinks: true });

    const box = { root, site: join(root, 'site'), bin: join(root, 'bin') };
    if (record !== null) {
        writeFileSync(paths(box.site).lastDeployment, record);
    }
    return box;
}

/**
 * @param {object} box
 * @param {string[]} [args]
 * @param {{fail?: boolean, lockFd?: string, cwd?: string, full?: string}} [options]
 */
function rollback(box, args = [], { fail = false, lockFd, cwd = box.root, full = '' } = {}) {
    const site = paths(box.site);
    // Through the link, as a human types it: <site>/current/scripts/rollback.sh.
    const result = spawnSync(join(box.bin, 'bash'), [join(site.current, 'scripts', 'rollback.sh'), ...args], {
        cwd,
        encoding: 'utf8',
        env: {
            PATH: box.bin,
            HOME: box.root,
            STUB_LOG: join(box.root, 'calls.log'),
            STUB_DIR: box.root,
            STUB_SITE: box.site,
            STUB_LOCK: site.lock,
            STUB_FAIL: fail ? '1' : '',
            STUB_FULL: full,
            ...(lockFd === undefined ? {} : { DEPLOY_LOCK_FD: lockFd }),
        },
    });

    return {
        status: result.status,
        stdout: result.stdout,
        stderr: result.stderr,
        inService: inService(box.site),
        cached: lines(join(box.root, 'state.log')).map((line) => {
            const [command, directory, current, lock] = line.split('\x1f');
            return { command, directory, current, lock };
        }),
        lastDeployment: read(site.lastDeployment),
        releases: readdirSync(site.releases).sort(),
    };
}

// Refused: said why, and the site serves what it served, with its record untouched.
function expectUnchanged(result, record = `${EPOCH} ${C} ${B}\n`) {
    expect(result.status).toBe(1);
    expect(result.inService).toBe(C);
    expect(result.cached).toEqual([]);
    expect(result.lastDeployment).toBe(record);
    expect(result.releases).toEqual([A, B, C]);
}

describe('rollback.sh — undoing the last deployment', () => {
    it('puts back in service the release the last deployment replaced', () => {
        const box = sandbox();
        const result = rollback(box);

        expect(result.stderr).toBe('');
        expect(result.status).toBe(0);
        expect(result.inService).toBe(B);
        expect(result.stdout).toContain(`Rolled back to ${sha('b')}`);
        expect(existsSync(join(box.site, 'current.next'))).toBe(false);
    });

    it('says that the database did not go back with the code', () => {
        expect(rollback(sandbox()).stdout).toContain('The database was not rolled back: migrations only go forward.');
    });

    it('caches the configuration again, in the release it goes back to, before it is in service', () => {
        // That release cached its configuration when it was built, and .env may have changed
        // since. Cached after the switch, it would serve the old values for a moment.
        const result = rollback(sandbox());

        expect(result.cached).toEqual([
            { command: 'artisan config:cache', directory: B, current: join('releases', C), lock: 'held' },
        ]);
        expect(result.stdout).toContain('── php artisan config:cache\n');
    });

    it('removes nothing: the release it leaves can be put back, by name', () => {
        const box = sandbox();
        const result = rollback(box);

        expect(result.releases).toEqual([A, B, C]);
        expect(read(join(box.site, 'releases', C, 'page.txt'))).toBe(`${C}\n`);
    });

    it('forgets the deployment it has undone: a second one is not "the last deployment" any more', () => {
        const box = sandbox();
        expect(rollback(box).lastDeployment).toBeNull();

        const again = rollback(box);
        expect(again.status).toBe(1);
        expect(again.stderr).toContain('is not one a deployment has just put there');
        expect(again.inService).toBe(B);
    });

    it('works from any directory', () => {
        const box = sandbox();

        expect(rollback(box, [], { cwd: tmpdir() }).inService).toBe(B);
    });

    it.each([
        ['there is no record of a deployment', null, 'name the release to go back to'],
        ['the record is empty', '', 'name the release to go back to'],
        ['the last deployment was of another release', `${EPOCH} ${B} ${A}\n`, 'name the release to go back to'],
        ['that deployment was the first of the site', `${EPOCH} ${C}\n`, 'there is no release before it'],
    ])('refuses, without a name, when %s', (_, record, reason) => {
        const result = rollback(sandbox({ record }));

        expect(result.stderr).toContain(reason);
        expectUnchanged(result, record);
    });
});

describe('rollback.sh — going back to a release that is named', () => {
    it('goes to any release that was served, whatever the last deployment was', () => {
        const result = rollback(sandbox({ record: null }), [A]);

        expect(result.status).toBe(0);
        expect(result.inService).toBe(A);
        expect(result.cached[0]).toMatchObject({ directory: A, current: join('releases', C) });
        expect(result.stdout).toContain(`Rolled back to ${sha('a')}`);
    });

    it('goes forward again the same way: the release it left is one that was served', () => {
        const box = sandbox();
        rollback(box);

        expect(rollback(box, [C]).inService).toBe(C);
    });

    it('forgets the last deployment then too: what is served is no longer what it put there', () => {
        expect(rollback(sandbox(), [A]).lastDeployment).toBeNull();
    });

    it.each([
        ['a path', () => `../releases/${A}`],
        ['an absolute path', (box) => join(box.site, 'releases', A)],
        ['a path that leaves the site', () => '../../outside'],
        ['a name with a slash at the end', () => `${A}/`],
        ['a SHA instead of a release name', () => sha('a')],
        ['an empty word', () => ''],
        ['a release that does not exist', () => releaseName(sha('d'), '20261011-090000')],
    ])('refuses %s: a release is named, never pointed at', (_, given) => {
        const box = sandbox();
        mkdirSync(join(box.root, 'outside'));
        writeFileSync(join(box.root, 'outside', '.served'), '');
        const result = rollback(box, [given(box)]);

        expect(result.stderr).toContain('is not a release that was served on this site.');
        expectUnchanged(result);
    });

    it('refuses a release that was never served: it may be half built', () => {
        const box = sandbox();
        const unserved = releaseName(sha('d'), '20261011-090000');
        addRelease(box.site, unserved, { sha: sha('d'), served: false });
        const result = rollback(box, [unserved]);

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('is not a release that was served on this site.');
        expect(result.inService).toBe(C);
    });

    it('refuses the release already in service', () => {
        const result = rollback(sandbox(), [C]);

        expect(result.stderr).toContain(`${C} is the release being served.`);
        expectUnchanged(result);
    });

    it('explains itself when given two names', () => {
        const result = rollback(sandbox(), [A, B]);

        expect(result.status).toBe(2);
        expect(result.stderr).toContain('Usage: bash scripts/rollback.sh [<release name>]');
        expect(result.inService).toBe(C);
    });
});

describe('rollback.sh — when it cannot go through', () => {
    it('does not switch when the configuration cannot be cached, and keeps the record', () => {
        const result = rollback(sandbox(), [], { fail: true });

        expect(result.status).not.toBe(0);
        expect(result.inService).toBe(C);
        expect(result.lastDeployment).toBe(`${EPOCH} ${C} ${B}\n`);
        expect(result.stdout).not.toContain('Rolled back');
    });

    it.each([
        ['preparing the new link', 'ln#1'],
        ['moving the link', 'mv#1'],
    ])('leaves the site on the release it was serving when the disk is full while %s', (_, full) => {
        const result = rollback(sandbox(), [], { full });

        expect(result.stderr).toContain('No space left on device');
        expect(result.status).not.toBe(0);
        expect(result.inService).toBe(C);
        expect(result.stdout).not.toContain('Rolled back');
        // Not undone: the deployment is still the last one, and can be undone once there is room.
        expect(result.lastDeployment).toBe(`${EPOCH} ${C} ${B}\n`);
    });

    it('goes through once there is room again, whatever the failed attempt left', () => {
        const box = sandbox();
        rollback(box, [], { full: 'mv#1' });

        expect(rollback(box).inService).toBe(B);
        expect(existsSync(join(box.site, 'current.next'))).toBe(false);
    });

    it('is not stopped by the link a deployment that was killed left half-way', () => {
        const box = sandbox();
        symlinkSync(join('releases', 'gone'), join(box.site, 'current.next'));

        expect(rollback(box).inService).toBe(B);
        expect(existsSync(join(box.site, 'current.next'))).toBe(false);
    });

    it('refuses a release that no longer says which commit it holds', () => {
        const box = sandbox();
        rmSync(join(box.site, 'releases', B, 'RELEASE'));
        const result = rollback(box);

        expect(result.stderr).toContain('is not a release that was served on this site.');
        expect(result.status).toBe(1);
        expect(result.inService).toBe(C);
        expect(result.cached).toEqual([]);
    });

    it('still succeeds when the record cannot be removed, and says what to do about it', () => {
        // The release is back in service: reporting a failure now would be reporting something false.
        const box = sandbox({ record: null });
        mkdirSync(paths(box.site).lastDeployment);
        const result = rollback(box, [A]);

        expect(result.status).toBe(0);
        expect(result.inService).toBe(A);
        expect(result.stderr).toContain('last-deployment could not be removed');
        expect(result.stdout).toContain(`Rolled back to ${sha('a')}`);
    });

    it('says so when the lock cannot be opened at all, and changes nothing', () => {
        const box = sandbox();
        mkdirSync(paths(box.site).lock);
        const result = rollback(box);

        expect(result.stderr).toContain('deploy.lock cannot be opened.');
        expectUnchanged(result);
    });

    it.each([
        ['outside a site', (box) => join(box.root, 'elsewhere', 'scripts'), []],
        ['among releases that share nothing', (box) => join(box.root, 'half', 'releases', 'one', 'scripts'), []],
        ['beside the releases, not among them', (box) => join(box.site, 'tools', 'one', 'scripts'), ['tools/shared']],
    ])('refuses to run %s', (_, where, present) => {
        const box = sandbox();
        const directory = where(box);
        mkdirSync(directory, { recursive: true });
        present.forEach((part) => mkdirSync(join(box.site, part), { recursive: true }));
        cpSync(ROLLBACK, join(directory, 'rollback.sh'));
        const result = spawnSync(join(box.bin, 'bash'), [join(directory, 'rollback.sh'), A], {
            encoding: 'utf8',
            env: { PATH: box.bin },
        });

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('is not a release of a site.');
        expect(inService(box.site)).toBe(C);
    });
});

describe('rollback.sh — a site whose link leads nowhere', () => {
    // The directory of the release in service was removed by mistake: every page answers an error,
    // and `current/scripts/rollback.sh` itself is gone. This is the day the script is needed most.
    const from = (box, release) => join(box.site, 'releases', release, 'scripts', 'rollback.sh');
    const run = (box, script, args) => {
        const result = spawnSync(join(box.bin, 'bash'), [script, ...args], {
            encoding: 'utf8',
            env: {
                PATH: box.bin,
                STUB_LOG: join(box.root, 'calls.log'),
                STUB_DIR: box.root,
                STUB_SITE: box.site,
                STUB_LOCK: paths(box.site).lock,
                STUB_FAIL: '',
            },
        });
        return { status: result.status, stderr: result.stderr, inService: inService(box.site) };
    };

    it.each([
        ['points at a release that was removed', (box) => rmSync(join(box.site, 'releases', C), { recursive: true })],
        ['is gone', (box) => rmSync(paths(box.site).current)],
    ])('puts a named release back in service, from any release left, when the link %s', (_, damage) => {
        const box = sandbox();
        damage(box);
        const result = run(box, from(box, A), [B]);

        expect(result.stderr).toBe('');
        expect(result.status).toBe(0);
        expect(result.inService).toBe(B);
    });

    it.each([
        ['a deployment is still recorded', `${EPOCH} ${C} ${B}\n`],
        ['nothing is recorded either', null],
    ])('asks for a name when %s: with nothing served, there is no deployment to undo', (_, record) => {
        const box = sandbox({ record });
        rmSync(join(box.site, 'releases', C), { recursive: true });
        const result = run(box, from(box, A), []);

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('name the release to go back to');
        expect(result.inService).toBe(C);
    });
});

describe('rollback.sh — one deployment at a time', () => {
    it('holds the lock while it works, and lets it go', () => {
        const box = sandbox();
        const result = rollback(box);

        expect(result.cached[0].lock).toBe('held');
        expect(isLocked(box.site)).toBe(false);
    });

    it('refuses while a deployment holds the lock, with the exit code that says "busy"', () => {
        const box = sandbox();
        const letGo = holdLock(box.site);
        try {
            const result = rollback(box);

            expect(result.status).toBe(75);
            expect(result.stderr).toContain('a deployment is already running on this server.');
            expect(result.inService).toBe(C);
            expect(result.cached).toEqual([]);
        } finally {
            letGo();
        }
    });

    it('does not take a second lock when the gate already holds one', () => {
        const box = sandbox();
        const letGo = holdLock(box.site);
        try {
            expect(rollback(box, [], { lockFd: '9' }).inService).toBe(B);
        } finally {
            letGo();
        }
    });

    it('refuses a lock that is announced and held by nobody', () => {
        const result = rollback(sandbox(), [], { lockFd: '9' });

        expect(result.stderr).toContain('the deployment lock is announced as held and is not.');
        expectUnchanged(result);
    });
});
