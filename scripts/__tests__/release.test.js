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
    readdirSync,
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
    LOCK_STATE,
    STAMP,
    addRelease,
    git,
    holdLock,
    inService,
    isLocked,
    knows,
    layOut,
    lose,
    paths,
    read,
    releaseName,
    serve,
} from './support/site.js';

// scripts/release.sh, run for real in a site laid out as the server is. git and tar are the real
// ones: what the script has to get right is which commit ends up in which directory.
//
//   main      c1 ── c2 ── c3        c2 carries v2 of scripts/deploy.sh, c3 has lost it
//   candidate        └── c4         a branch, as a human releases one before it is merged
//
// The site serves c1. scripts/deploy.sh is a stand-in *inside the commits*: release.sh ends by
// handing over to the one of the commit it has just extracted, and that is what has to be seen.
const RELEASE = fileURLToPath(new URL('../release.sh', import.meta.url));

// Says which version of itself ran, from where, what RELEASE held at that moment, and whether the
// deployment lock was held.
const deployScript = (version) => `#!/bin/bash
${LOCK_STATE}
here=$(cd -P "$(dirname "\${BASH_SOURCE[0]}")/.." && pwd)
printf 'deploy.sh\\x1f${version}\\x1f%s\\x1f%s\\x1f%s\\x1f%s\\x1f%s\\n' \\
  "\${here##*/}" "$(cat "$here/RELEASE")" "$lock_state" "\${DEPLOY_LOCK_FD:-}" "$#" >> "$STUB_LOG"
exit "\${STUB_DEPLOY_EXIT:-0}"
`;

const BROKEN_TAR = `${JOURNAL}
cat > /dev/null
exit 2
`;

// Extracts as tar does, and the script is told to stop while it is at it.
const INTERRUPTED_TAR = `#!/bin/bash
${locate('tar')} "$@"
kill -TERM "$PPID"
`;

let template;
const commit = {};
const sandboxes = [];

beforeAll(() => {
    template = mkdtempSync(join(tmpdir(), 'release-template-'));
    const work = join(template, 'work');
    const site = join(template, 'site');
    const write = (path, content) => {
        mkdirSync(join(work, path, '..'), { recursive: true });
        writeFileSync(join(work, path), content);
    };
    const record = (message) => {
        git(work, 'add', '--all', '.');
        git(work, 'commit', '--quiet', '-m', message);
        return git(work, 'rev-parse', 'HEAD');
    };

    git(template, 'init', '--quiet', '-b', 'main', 'work');
    write('scripts/deploy.sh', deployScript('v1'));
    write('page.txt', 'first\n');
    write('.github/workflows/ci.yml', 'on: push\n');
    write('.gitattributes', '/.github export-ignore\n');
    commit.c1 = record('c1');

    write('scripts/deploy.sh', deployScript('v2'));
    write('page.txt', 'second\n');
    commit.c2 = record('c2');

    git(work, 'checkout', '--quiet', '-b', 'candidate');
    write('page.txt', 'candidate\n');
    // Executable, as git records it: the other way a script is a regular file.
    chmodSync(join(work, 'scripts', 'deploy.sh'), 0o755);
    commit.c4 = record('c4');
    git(work, 'tag', '--annotate', '--message', 'a version', '2026.10.0', commit.c2);

    // A commit that ships a RELEASE file of its own, naming another commit.
    git(work, 'checkout', '--quiet', '-b', 'liar', commit.c2);
    write('RELEASE', `${'f'.repeat(40)}\n`);
    commit.c5 = record('c5');

    // And one where RELEASE is a link towards what the releases share.
    rmSync(join(work, 'RELEASE'));
    symlinkSync('../../shared/.env', join(work, 'RELEASE'));
    commit.c6 = record('c6');

    // Two commits whose scripts/deploy.sh is there, and is not a file that can be run.
    git(work, 'checkout', '--quiet', '-b', 'odd', commit.c2);
    rmSync(join(work, 'scripts', 'deploy.sh'));
    write('scripts/deploy.sh/README', 'a directory by that name\n');
    commit.directory = record('directory');
    rmSync(join(work, 'scripts', 'deploy.sh'), { recursive: true });
    symlinkSync('../page.txt', join(work, 'scripts', 'deploy.sh'));
    commit.link = record('link');
    commit.page = git(work, 'rev-parse', `${commit.c2}:page.txt`);

    git(work, 'checkout', '--quiet', 'main');
    rmSync(join(work, 'scripts', 'deploy.sh'));
    commit.c3 = record('c3');
    commit.blob = git(work, 'rev-parse', `${commit.c1}:page.txt`);

    layOut(site);
    git(template, 'clone', '--quiet', '--bare', 'work', join('site', 'repo'));

    fillPath(join(template, 'bin'), {
        real: ['bash', 'git', 'tar', 'dirname', 'rm', 'cat', 'flock'],
        standins: { date: DATE, mkdir: failingWhenFull('mkdir') },
    });
    fillPath(join(template, 'bin-broken-tar'), {
        real: ['bash', 'git', 'dirname', 'mkdir', 'rm', 'cat', 'flock'],
        standins: { date: DATE, tar: BROKEN_TAR },
    });
    fillPath(join(template, 'bin-interrupted-tar'), {
        real: ['bash', 'git', 'dirname', 'mkdir', 'rm', 'cat', 'flock'],
        standins: { date: DATE, tar: INTERRUPTED_TAR },
    });
});

afterAll(() => rmSync(template, { recursive: true, force: true }));

afterEach(() => {
    sandboxes.splice(0).forEach((root) => rmSync(root, { recursive: true, force: true }));
});

const SERVED = () => releaseName(commit.c1, '20261011-070000');

function sandbox() {
    const root = mkdtempSync(join(tmpdir(), 'release-'));
    sandboxes.push(root);
    cpSync(template, root, { recursive: true, verbatimSymlinks: true });

    const box = { root, site: join(root, 'site'), log: join(root, 'calls.log') };
    const served = addRelease(box.site, SERVED(), { sha: commit.c1, files: { 'page.txt': 'first\n' } });
    mkdirSync(join(served, 'scripts'));
    cpSync(RELEASE, join(served, 'scripts', 'release.sh'));
    serve(box.site, SERVED());
    return box;
}

/**
 * @param {object} box
 * @param {string[]} args
 * @param {{cwd?: string, lockFd?: string, bin?: string, deployExit?: number, script?: string, full?: string}} [options]
 */
function release(box, args, { cwd = box.root, lockFd, bin = 'bin', deployExit = 0, script, full = '' } = {}) {
    const site = paths(box.site);
    const result = spawnSync(
        join(box.root, bin, 'bash'),
        [script ?? join(site.current, 'scripts', 'release.sh'), ...args],
        {
            cwd,
            encoding: 'utf8',
            env: {
                PATH: join(box.root, bin),
                HOME: box.root,
                GIT_CONFIG_GLOBAL: '/dev/null',
                GIT_CONFIG_NOSYSTEM: '1',
                STUB_LOG: box.log,
                STUB_LOCK: site.lock,
                STUB_STAMP: STAMP,
                STUB_DEPLOY_EXIT: String(deployExit),
                STUB_DIR: box.root,
                STUB_FULL: full,
                ...(lockFd === undefined ? {} : { DEPLOY_LOCK_FD: lockFd }),
            },
        },
    );

    return {
        status: result.status,
        stdout: result.stdout,
        stderr: result.stderr,
        releases: readdirSync(site.releases).sort(),
        handedTo: callsIn(box.log)
            .filter(([tool]) => tool === 'deploy.sh')
            .map(([, version, directory, sha, lock, lockFd, count]) => ({
                version,
                directory,
                sha,
                lock,
                lockFd,
                args: Number(count),
            })),
    };
}

// Nothing was made, nothing was handed over, and the site serves what it served.
function expectNothingReleased(box, result) {
    expect(result.releases).toEqual([SERVED()]);
    expect(result.handedTo).toEqual([]);
    expect(inService(box.site)).toBe(SERVED());
}

describe('release.sh — the directory it makes', () => {
    it('extracts the commit into a directory named after the moment and the commit', () => {
        const box = sandbox();
        const result = release(box, [commit.c2]);
        const name = releaseName(commit.c2);

        expect(result.stderr).toBe('');
        expect(result.status).toBe(0);
        expect(result.releases).toEqual([SERVED(), name]);
        expect(read(join(box.site, 'releases', name, 'page.txt'))).toBe('second\n');
        expect(result.stdout).toContain(`Release ${name}`);
    });

    it('names the release before handing it over: RELEASE is there when the build starts', () => {
        const result = release(sandbox(), [commit.c2]);

        expect(result.handedTo).toEqual([
            { version: 'v2', directory: releaseName(commit.c2), sha: commit.c2, lock: 'held', lockFd: '9', args: 0 },
        ]);
    });

    it('hands over to the deployment script of the commit it extracted, not of the one in service', () => {
        // The site serves c1, whose script is v1: how c2 is built is written in c2.
        expect(release(sandbox(), [commit.c2]).handedTo[0].version).toBe('v2');
        expect(release(sandbox(), [commit.c1]).handedTo[0].version).toBe('v1');
    });

    it('makes a new directory for the commit already in service, and leaves that one alone', () => {
        const box = sandbox();
        const result = release(box, [commit.c1]);

        expect(result.releases).toEqual([SERVED(), releaseName(commit.c1)]);
        expect(inService(box.site)).toBe(SERVED());
    });

    it('does not put anything in service itself', () => {
        const box = sandbox();
        release(box, [commit.c2]);

        expect(inService(box.site)).toBe(SERVED());
    });

    it('leaves out what the repository marks as not to be exported', () => {
        const box = sandbox();
        release(box, [commit.c2]);

        expect(existsSync(join(box.site, 'releases', releaseName(commit.c2), '.github'))).toBe(false);
        expect(existsSync(join(box.site, 'releases', releaseName(commit.c2), '.git'))).toBe(false);
    });

    it('answers with what the deployment script answered', () => {
        expect(release(sandbox(), [commit.c2], { deployExit: 7 }).status).toBe(7);
    });

    it('works from any directory: the site is the one the script lives in', () => {
        const box = sandbox();
        const result = release(box, [commit.c2], { cwd: tmpdir() });

        expect(result.releases).toEqual([SERVED(), releaseName(commit.c2)]);
    });
});

describe('release.sh — the commit it is given', () => {
    it.each([
        ['a branch', () => 'candidate', () => commit.c4],
        ['an abbreviated SHA', () => commit.c2.slice(0, 12), () => commit.c2],
        ['a ref', () => 'refs/heads/main~1', () => commit.c2],
        ['the tag of a version', () => '2026.10.0', () => commit.c2],
    ])('takes %s from a human, and names the release after the full SHA', (_, given, expected) => {
        const result = release(sandbox(), [given()]);

        expect(result.status).toBe(0);
        expect(result.releases).toContain(releaseName(expected()));
        expect(result.handedTo[0].sha).toBe(expected());
    });

    it.each([
        ['a name the repository does not know', () => 'no-such-branch'],
        ['a SHA the repository does not hold', () => 'deadbeef'.repeat(5)],
        ['a file of the repository', () => commit.blob],
        ['an empty word', () => ''],
        ['an option for git', () => '--upload-pack=touch intruder'],
        ['an option that would print the tree', () => '--all'],
    ])('refuses %s, and makes nothing', (_, given) => {
        const box = sandbox();
        const result = release(box, [given()]);

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('is not a commit');
        expect(result.stderr).toContain('Fetch it first.');
        // And it stops there: nothing is looked up in a commit that does not exist.
        expect(result.stderr).not.toContain('scripts/deploy.sh');
        expectNothingReleased(box, result);
        expect(existsSync(join(box.root, 'intruder'))).toBe(false);
    });

    it('names the release itself, whatever RELEASE file the commit ships', () => {
        // What a release announces is what the gate measures every later request against.
        const result = release(sandbox(), [commit.c5]);

        expect(result.handedTo[0].sha).toBe(commit.c5);
    });

    it('writes its name as a file of the release, never through a link the commit shipped under that name', () => {
        // Followed, that link would have the SHA written over the configuration every release shares.
        const box = sandbox();
        const result = release(box, [commit.c6]);
        const written = join(box.site, 'releases', releaseName(commit.c6), 'RELEASE');

        expect(result.handedTo[0].sha).toBe(commit.c6);
        expect(lstatSync(written).isFile()).toBe(true);
        expect(read(join(box.site, 'shared', '.env'))).toBe('APP_ENV=production\n');
    });

    it.each([
        ['a directory', 'directory'],
        ['a link', 'link'],
    ])('refuses a commit whose deployment script is %s: it is there, and cannot be run', (_, name) => {
        const box = sandbox();
        const result = release(box, [commit[name]]);

        expect(result.status).toBe(1);
        expect(result.stderr).toContain(`commit ${commit[name]} has no scripts/deploy.sh`);
        expectNothingReleased(box, result);
    });

    it('refuses a commit with no deployment script: nothing could build it', () => {
        const box = sandbox();
        const result = release(box, [commit.c3]);

        expect(result.status).toBe(1);
        expect(result.stderr).toContain(`commit ${commit.c3} has no scripts/deploy.sh`);
        expectNothingReleased(box, result);
    });

    it.each([
        ['no commit', []],
        ['two words', ['main', 'candidate']],
    ])('explains itself and makes nothing when given %s', (_, args) => {
        const box = sandbox();
        const result = release(box, args);

        expect(result.status).toBe(2);
        expect(result.stderr).toContain('Usage: bash scripts/release.sh <commit>');
        expectNothingReleased(box, result);
        expect(isLocked(box.site)).toBe(false);
    });
});

describe('release.sh — one deployment at a time', () => {
    it('takes the lock when a human runs it, and holds it while the release is built', () => {
        const result = release(sandbox(), [commit.c2]);

        expect(result.handedTo[0]).toMatchObject({ lock: 'held', lockFd: '9' });
    });

    it('lets the lock go when the deployment ends', () => {
        const box = sandbox();
        release(box, [commit.c2]);

        expect(isLocked(box.site)).toBe(false);
    });

    it('refuses while another deployment holds the lock, with the exit code that says "busy"', () => {
        const box = sandbox();
        const letGo = holdLock(box.site);
        try {
            const result = release(box, [commit.c2]);

            expect(result.status).toBe(75);
            expect(result.stderr).toContain('a deployment is already running on this server.');
            expectNothingReleased(box, result);
        } finally {
            letGo();
        }
    });

    it('refuses a lock that is announced and held by nobody', () => {
        const box = sandbox();
        const result = release(box, [commit.c2], { lockFd: '9' });

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('the deployment lock is announced as held and is not.');
        expectNothingReleased(box, result);
    });

    it('says so when the lock cannot be opened at all, and makes nothing', () => {
        const box = sandbox();
        mkdirSync(paths(box.site).lock);
        const result = release(box, [commit.c2]);

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('deploy.lock cannot be opened.');
        expectNothingReleased(box, result);
    });

    it('does not take a second lock when the gate already holds one', () => {
        // The gate locks before it comes here and says so. A second lock on the same file would be
        // refused by the first, and no request of the pipeline would ever get through.
        const box = sandbox();
        const letGo = holdLock(box.site);
        try {
            const result = release(box, [commit.c2], { lockFd: '9' });

            expect(result.status).toBe(0);
            expect(result.handedTo[0]).toMatchObject({ sha: commit.c2, lockFd: '9' });
        } finally {
            letGo();
        }
    });
});

describe('release.sh — the commits it keeps in the repository', () => {
    const anchors = (box) =>
        git(paths(box.site).repo, 'for-each-ref', '--format=%(refname)', 'refs/releases/').split('\n').filter(Boolean);

    it('keeps the commit of a release when nothing else refers to it any more', () => {
        // A candidate is fetched without a branch to hold it. git removes in time what nothing
        // refers to — and the gate, unable to read the commit it serves, would refuse everything.
        const box = sandbox();
        release(box, [commit.c4]);
        git(paths(box.site).repo, 'update-ref', '-d', 'refs/heads/candidate');
        git(paths(box.site).repo, 'gc', '--quiet', '--prune=now');

        expect(knows(paths(box.site).repo, `${commit.c4}^{commit}`)).toBe(true);
        expect(anchors(box)).toEqual([`refs/releases/${releaseName(commit.c4)}`]);
    });

    // A second release, a moment later: the stand-in for `date` answers one name per run.
    const releaseLater = (box, sha) =>
        spawnSync(join(box.root, 'bin', 'bash'), [join(paths(box.site).current, 'scripts', 'release.sh'), sha], {
            encoding: 'utf8',
            env: {
                PATH: join(box.root, 'bin'),
                GIT_CONFIG_GLOBAL: '/dev/null',
                GIT_CONFIG_NOSYSTEM: '1',
                STUB_LOG: box.log,
                STUB_LOCK: paths(box.site).lock,
                STUB_DIR: box.root,
                STUB_STAMP: '20261011-090000',
            },
        });
    const LATER = () => `refs/releases/${releaseName(commit.c2, '20261011-090000')}`;

    it('keeps the commits of the releases that are still there, one release after the other', () => {
        const box = sandbox();
        release(box, [commit.c4]);

        expect(releaseLater(box, commit.c2).status).toBe(0);
        expect(anchors(box)).toEqual([`refs/releases/${releaseName(commit.c4)}`, LATER()]);
    });

    it('lets go of the commits of releases that are no longer there', () => {
        // Removed by a deployment that kept its five, or left by a release that failed.
        const box = sandbox();
        release(box, [commit.c4]);
        rmSync(join(box.site, 'releases', releaseName(commit.c4)), { recursive: true });

        expect(releaseLater(box, commit.c2).status).toBe(0);
        expect(anchors(box)).toEqual([LATER()]);
    });
});

describe('release.sh — a repository that has lost something', () => {
    it('leaves nothing behind when a file of the commit can no longer be read', () => {
        // The commit is there, its tree is there, and one of its files is gone: git finds out
        // half-way through the extraction.
        const box = sandbox();
        lose(paths(box.site).repo, commit.page);
        const result = release(box, [commit.c2]);

        expect(result.status).not.toBe(0);
        expectNothingReleased(box, result);
        expect(isLocked(box.site)).toBe(false);
    });

    it('refuses a commit the repository has lost, as one it never had', () => {
        const box = sandbox();
        lose(paths(box.site).repo, commit.c2);
        const result = release(box, [commit.c2]);

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('is not a commit');
        expectNothingReleased(box, result);
    });
});

describe('release.sh — when it cannot go through', () => {
    it('makes nothing when the disk is too full for the directory itself', () => {
        const box = sandbox();
        const result = release(box, [commit.c2], { full: 'mkdir#1' });

        expect(result.stderr).toContain('No space left on device');
        expect(result.status).not.toBe(0);
        expectNothingReleased(box, result);
    });

    it('leaves no directory behind when the commit cannot be extracted', () => {
        const box = sandbox();
        const result = release(box, [commit.c2], { bin: 'bin-broken-tar' });

        expect(result.status).not.toBe(0);
        expectNothingReleased(box, result);
    });

    it('leaves no directory behind when it is interrupted while extracting', () => {
        const box = sandbox();
        const result = release(box, [commit.c2], { bin: 'bin-interrupted-tar' });

        expect(result.status).not.toBe(0);
        expectNothingReleased(box, result);
        expect(isLocked(box.site)).toBe(false);
    });

    it('refuses to make the same release twice in the same second, and keeps the first', () => {
        const box = sandbox();
        const first = addRelease(box.site, releaseName(commit.c2), { sha: commit.c2, files: { 'page.txt': 'kept\n' } });
        const result = release(box, [commit.c2]);

        expect(result.status).not.toBe(0);
        expect(result.handedTo).toEqual([]);
        expect(read(join(first, 'page.txt'))).toBe('kept\n');
    });

    it.each([
        ['outside a site', (box) => join(box.root, 'elsewhere', 'scripts'), []],
        ['in a site with no repository', (box) => join(box.root, 'half', 'releases', 'one', 'scripts'), ['shared']],
        ['in a site with nothing shared', (box) => join(box.root, 'half', 'releases', 'one', 'scripts'), ['repo']],
    ])('refuses to run %s, where there is nothing to release from', (_, where, present) => {
        const box = sandbox();
        const directory = where(box);
        mkdirSync(directory, { recursive: true });
        present.forEach((part) => mkdirSync(join(box.root, 'half', part)));
        cpSync(RELEASE, join(directory, 'release.sh'));
        const result = release(box, [commit.c2], { script: join(directory, 'release.sh') });

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('is not a release of a site');
        expectNothingReleased(box, result);
    });

    it('refuses to run from a directory that is not one of the releases, even next to a repository', () => {
        // <site>/tools/scripts/release.sh: repo/ and shared/ are two levels up, and it still is
        // not a release.
        const box = sandbox();
        mkdirSync(join(box.site, 'tools', 'x', 'scripts'), { recursive: true });
        mkdirSync(join(box.site, 'tools', 'repo'));
        mkdirSync(join(box.site, 'tools', 'shared'));
        cpSync(RELEASE, join(box.site, 'tools', 'x', 'scripts', 'release.sh'));
        const result = release(box, [commit.c2], { script: join(box.site, 'tools', 'x', 'scripts', 'release.sh') });

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('is not a release of a site');
    });
});
