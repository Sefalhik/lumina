// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { spawnSync } from 'node:child_process';
import {
    chmodSync,
    cpSync,
    existsSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    rmSync,
    symlinkSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { BASH, JOURNAL, callsIn, fillPath, locate } from './support/sandbox.js';
import {
    DATE,
    EPOCH,
    LOCK_STATE,
    addRelease,
    git,
    holdLock,
    inService,
    knows,
    layOut,
    lines,
    loosen,
    lose,
    paths,
    releaseName,
    serve,
} from './support/site.js';

// scripts/deploy-gate.sh is what stands between a stolen SSH key and the server. It is run here
// for real, against real git repositories: what it has to get right is which commits belong to
// main, and a stand-in for git would only answer what the test told it to.
//
// `origin.git` plays GitHub; `site` is laid out as the server is, and the gate is reached through
// its `current` link, as sshd reaches it.
//
//   main     cA ── cB ── c1 ── cC ── c2 ── cD ── cE ── cF ── cG ── cH ── c5
//   feature               └── c3      a branch of the repository, never merged
//   pull/1                └── c4      the commit of a fork, as GitHub exposes it: fetchable by
//                                     its SHA, on no branch
//
// cA is from before the deployment scripts existed and cB has only the first of them. cC, cD, cE
// and cF each lack one of the four; cG has a directory where one should be, and cH a link. The
// site serves c1. Everything from cD on is pushed after the
// site cloned: only a fetch brings it.
//
// The gate decides and hands over: scripts/release.sh and scripts/rollback.sh are stand-ins here,
// which say that they were called, with what, and whether the deployment lock was held.
const GATE = fileURLToPath(new URL('../deploy-gate.sh', import.meta.url));
const SCRIPTS = ['deploy.sh', 'deploy-gate.sh', 'release.sh', 'rollback.sh'];

const HANDED_OVER = `#!/bin/bash
${LOCK_STATE}
{ printf '%s' "\${0##*/}"; printf '\\x1f%s' "$#" "$lock_state" "\${DEPLOY_LOCK_FD:-}" "$@"; printf '\\n'; } >> "$STUB_LOG"
`;

const PHP = `${JOURNAL}
[[ "$1" == '-r' ]] && printf 'PHP 8.5.99\\n'
`;

// The real git, except for one comparison the test chooses — STUB_GIT_LOST counts the calls to
// merge-base — which fails the way git fails on a history it cannot read. What a lost object does
// to that command depends on the version of git: before 2.45 it answers "not an ancestor" and
// says nothing, from 2.45 on it stops with an error. The gate's answer to "git could not tell"
// must not depend on which git the test machine has.
const GIT = `#!${BASH}
if [[ -n "\${STUB_GIT_LOST:-}" && " $* " == *' merge-base '* ]]; then
  count=0
  [[ -f "$STUB_DIR/merge-base.calls" ]] && read -r count < "$STUB_DIR/merge-base.calls"
  count=$((count + 1))
  printf '%s\\n' "$count" > "$STUB_DIR/merge-base.calls"
  if [[ "$count" == "$STUB_GIT_LOST" ]]; then
    printf 'fatal: bad object\\n' >&2
    exit 128
  fi
fi
exec ${locate('git')} "$@"
`;

let template;
const commit = {};
const sandboxes = [];

beforeAll(() => {
    template = mkdtempSync(join(tmpdir(), 'deploy-gate-template-'));
    const work = join(template, 'work');
    const origin = join(template, 'origin.git');
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
    // The four scripts, then one of them taken away for a commit.
    const without = (missing, message) => {
        SCRIPTS.forEach((script) => write(`scripts/${script}`, `# ${script}\n`));
        if (missing) {
            rmSync(join(work, 'scripts', missing));
        }
        return record(message);
    };

    git(template, 'init', '--quiet', '-b', 'main', 'work');
    write('page.txt', 'before any script\n');
    commit.cA = record('cA');

    write('scripts/deploy.sh', '# deploy.sh\n');
    commit.cB = record('cB');

    write('page.txt', 'first\n');
    commit.c1 = without(null, 'c1');
    commit.cC = without('deploy.sh', 'cC');
    write('page.txt', 'second\n');
    commit.c2 = without(null, 'c2');

    git(work, 'checkout', '--quiet', '-b', 'feature', commit.c1);
    write('feature.txt', 'unmerged\n');
    commit.c3 = record('c3');

    git(work, 'checkout', '--quiet', '--detach', commit.c1);
    write('fork.txt', 'from a fork\n');
    commit.c4 = record('c4');

    git(work, 'checkout', '--quiet', 'main');
    git(template, 'clone', '--quiet', '--bare', 'work', 'origin.git');
    git(work, 'push', '--quiet', origin, `${commit.c4}:refs/pull/1/head`);

    layOut(site);
    // --no-local: a plain clone of a local path copies the whole object store, the fork's commit
    // included. Through the transport, the site only receives what the branches reach — which is
    // what a clone from GitHub receives.
    git(template, 'clone', '--quiet', '--bare', '--no-local', 'origin.git', join('site', 'repo'));

    commit.cD = without('deploy-gate.sh', 'cD');
    commit.cE = without('release.sh', 'cE');
    commit.cF = without('rollback.sh', 'cF');
    // The four names are there, and one of them is not a file that can be run.
    without('release.sh', 'cG, before its directory');
    write('scripts/release.sh/README', 'a directory by that name\n');
    commit.cG = record('cG');
    rmSync(join(work, 'scripts', 'release.sh'), { recursive: true });
    without('deploy-gate.sh', 'cH, before its link');
    symlinkSync('deploy.sh', join(work, 'scripts', 'deploy-gate.sh'));
    commit.cH = record('cH');
    rmSync(join(work, 'scripts', 'deploy-gate.sh'));
    write('page.txt', 'third\n');
    // Executable, as git records it: the other way a script is a regular file.
    SCRIPTS.forEach((script) => write(`scripts/${script}`, `# ${script}\n`));
    chmodSync(join(work, 'scripts', 'release.sh'), 0o755);
    commit.c5 = without(null, 'c5');
    git(work, 'push', '--quiet', origin, 'main');

    commit.blob = git(work, 'rev-parse', `${commit.c1}:page.txt`);
    commit.tree = git(work, 'rev-parse', `${commit.c1}^{tree}`);
    commit.nowhere = 'deadbeef'.repeat(5);

    // The site has not heard from origin since it cloned: it holds c2 and c3, has never seen c4
    // or anything after c2, and no longer knows where main is. Only a fetch can tell it.
    git(join(site, 'repo'), 'update-ref', '-d', 'refs/heads/main');

    fillPath(join(template, 'bin'), {
        real: ['bash', 'dirname', 'basename', 'cat', 'flock'],
        standins: { php: PHP, date: DATE, git: GIT },
    });
});

afterAll(() => rmSync(template, { recursive: true, force: true }));

afterEach(() => {
    sandboxes.splice(0).forEach((root) => rmSync(root, { recursive: true, force: true }));
});

/**
 * A site serving a commit, with the gate under test in the release in service.
 *
 * @param {{serving?: string|null}} [options]  the commit served; null leaves the site without `current`
 */
function sandbox({ serving = commit.c1 } = {}) {
    const root = mkdtempSync(join(tmpdir(), 'deploy-gate-'));
    sandboxes.push(root);
    cpSync(template, root, { recursive: true, verbatimSymlinks: true });

    const box = { root, site: join(root, 'site'), bin: join(root, 'bin'), log: join(root, 'calls.log') };
    git(paths(box.site).repo, 'remote', 'set-url', 'origin', join(root, 'origin.git'));
    release(box, serving);
    return box;
}

// Puts a commit in service: a release directory holding the gate under test, and `current` on it.
function release(box, sha, stamp = '20261011-070000') {
    const name = releaseName(sha, stamp);
    addRelease(box.site, name, {
        sha,
        files: {
            'scripts/deploy-gate.sh': readFileSync(GATE, 'utf8'),
            'scripts/release.sh': HANDED_OVER,
            'scripts/rollback.sh': HANDED_OVER,
        },
    });
    serve(box.site, name);
    return name;
}

/**
 * What the gate does when sshd hands it a request.
 *
 * @param {object} box
 * @param {string|undefined} request  SSH_ORIGINAL_COMMAND; undefined when the caller asked for a shell
 * @param {{args?: string[], cwd?: string, locale?: string, now?: number, gitLost?: number}} [options]
 */
function ask(box, request, { args = [], cwd = box.root, locale, now = EPOCH, gitLost } = {}) {
    const site = paths(box.site);
    const env = {
        PATH: box.bin,
        HOME: box.root,
        GIT_CONFIG_GLOBAL: '/dev/null',
        GIT_CONFIG_NOSYSTEM: '1',
        SSH_CONNECTION: '203.0.113.7 51234 198.51.100.1 22',
        STUB_LOG: box.log,
        STUB_LOCK: site.lock,
        STUB_EPOCH: String(now),
        STUB_DIR: box.root,
        ...(gitLost ? { STUB_GIT_LOST: String(gitLost) } : {}),
        ...(locale ? { LC_ALL: locale, LANG: locale } : {}),
    };
    if (request !== undefined) {
        env.SSH_ORIGINAL_COMMAND = request;
    }
    const served = inService(box.site);
    // Through the link, as the forced command of authorized_keys names it.
    const result = spawnSync(join(box.bin, 'bash'), [join(site.current, 'scripts', 'deploy-gate.sh'), ...args], {
        cwd,
        encoding: 'utf8',
        env,
    });
    const calls = callsIn(box.log);
    const handedTo = (script) =>
        calls
            .filter(([tool]) => tool === script)
            .map(([, count, lock, lockFd, ...given]) => ({ args: given, count: Number(count), lock, lockFd }));

    return {
        status: result.status,
        stdout: result.stdout,
        stderr: result.stderr,
        // The gate never puts a release in service itself: whatever it answers, this must not move.
        stillServing: inService(box.site) === served,
        released: handedTo('release.sh'),
        rolledBack: handedTo('rollback.sh'),
        php: calls.filter(([tool]) => tool === 'php'),
        fetchedMain: knows(site.repo, 'refs/remotes/origin/main'),
        journal: lines(site.gateJournal),
    };
}

const handed = (sha) => [{ args: [sha], count: 1, lock: 'held', lockFd: '9' }];

// Refused: said so, exit 1, and nothing was handed to the scripts that change the site.
function expectRefused(result, reason) {
    expect(result.stderr).toContain(`Refused: ${reason}`);
    expect(result.stderr).toContain('This key accepts "php-version", "deploy <sha>" and "rollback <sha>"');
    expect(result.status).toBe(1);
    expect(result.stillServing).toBe(true);
    expect(result.released).toEqual([]);
    expect(result.rolledBack).toEqual([]);
    expect(result.php).toEqual([]);
}

describe('deploy-gate.sh — what the key may ask', () => {
    it('reports the PHP version, and changes nothing', () => {
        const result = ask(sandbox(), 'php-version');

        expect(result.status).toBe(0);
        expect(result.stdout).toBe('PHP 8.5.99\n');
        expect(result.stillServing).toBe(true);
        expect(result.released).toEqual([]);
        expect(result.fetchedMain).toBe(false);
    });

    it('hands over a commit of main it had never seen: it fetches before it decides', () => {
        const result = ask(sandbox(), `deploy ${commit.c5}`);

        expect(result.stderr).toBe('');
        expect(result.status).toBe(0);
        expect(result.released).toEqual(handed(commit.c5));
    });

    it('hands the commit to the release script, with the lock held, and builds nothing itself', () => {
        const box = sandbox();
        const result = ask(box, `deploy ${commit.c2}`);

        expect(result.released).toEqual(handed(commit.c2));
        expect(result.rolledBack).toEqual([]);
        expect(result.stillServing).toBe(true);
    });

    it('hands over again the commit it is serving: a deployment that failed halfway can be replayed', () => {
        const result = ask(sandbox(), `deploy ${commit.c1}`);

        expect(result.status).toBe(0);
        expect(result.released).toEqual(handed(commit.c1));
    });

    it('works whatever the directory sshd started it in', () => {
        const box = sandbox();

        expect(ask(box, `deploy ${commit.c2}`, { cwd: tmpdir() }).released).toEqual(handed(commit.c2));
    });
});

describe('deploy-gate.sh — going back', () => {
    it('refuses a commit of main older than the one it serves', () => {
        // c1 is a commit of main, it carries the scripts, and it was being served a moment ago.
        // It is also what main was before c2 — the flaw c2 may have fixed.
        const box = sandbox({ serving: commit.c2 });
        const result = ask(box, `deploy ${commit.c1}`);

        expectRefused(result, 'that commit is older than the one being served');
        expect(result.journal.at(-1)).toContain(`refused older ${commit.c1}`);
    });

    it('measures "older" against what is served, not against where main is', () => {
        // c2 is far behind the head of main and ahead of the site: that is going forward.
        const result = ask(sandbox(), `deploy ${commit.c2}`);

        expect(result.status).toBe(0);
        expect(result.released).toEqual(handed(commit.c2));
    });

    it('refuses the base of a candidate being served, and lets main take the site back', () => {
        // A human put the unmerged branch in service, as docs/deployment.md allows. c1, which the
        // branch starts from, is behind it; the head of main is not.
        const box = sandbox({ serving: commit.c3 });

        expectRefused(ask(box, `deploy ${commit.c1}`), 'that commit is older than the one being served');
        expect(ask(box, `deploy ${commit.c5}`).released).toEqual(handed(commit.c5));
    });
});

describe('deploy-gate.sh — commits that are not main', () => {
    it('refuses a commit of another branch, even one the server already holds', () => {
        const box = sandbox();
        expect(knows(paths(box.site).repo, commit.c3)).toBe(true);

        expectRefused(ask(box, `deploy ${commit.c3}`), 'that SHA is not a commit of main.');
    });

    it('refuses the commit of a fork, and does not even fetch it', () => {
        // GitHub answers for this SHA from the repository's own address. Fetching "that commit"
        // instead of "main" would bring it in, and it would then deploy.
        const box = sandbox();
        expect(knows(join(box.root, 'origin.git'), commit.c4)).toBe(true);
        expect(knows(paths(box.site).repo, commit.c4)).toBe(false);

        expectRefused(ask(box, `deploy ${commit.c4}`), 'that SHA is not a commit of main.');
        expect(knows(paths(box.site).repo, commit.c4)).toBe(false);
    });

    it.each([
        ['a file', 'blob'],
        ['a directory', 'tree'],
        ['nothing at all', 'nowhere'],
    ])('refuses a SHA that names %s', (_, object) => {
        expectRefused(ask(sandbox(), `deploy ${commit[object]}`), 'that SHA is not a commit of main.');
    });

    it.each([
        ['from before the deployment scripts existed', 'cA'],
        ['that only has the deployment script', 'cB'],
        ['that has lost its deployment script', 'cC'],
        ['that has lost its gate', 'cD'],
        ['that has lost its release script', 'cE'],
        ['that has lost its rollback script', 'cF'],
        ['whose release script is a directory', 'cG'],
        ['whose gate is a link to another script', 'cH'],
    ])('refuses a commit of main %s', (_, name) => {
        // In service, such a commit would leave the key with nothing to answer it: no gate, or a
        // gate with nothing to hand the work to.
        const result = ask(sandbox(), `deploy ${commit[name]}`);

        expectRefused(result, 'that commit does not carry the deployment scripts');
        expect(result.journal.at(-1)).toContain(`refused no-scripts ${commit[name]}`);
    });

    it('refuses everything when origin cannot be reached, rather than trust what it last saw', () => {
        const box = sandbox();
        git(paths(box.site).repo, 'remote', 'set-url', 'origin', join(box.root, 'gone.git'));

        // c2 is a commit of main, and the site holds it: without a fetch that succeeded, that is
        // not something it can know.
        expectRefused(ask(box, `deploy ${commit.c2}`), 'origin could not be fetched');
    });

    it("refuses the same way after a request that went through: yesterday's main is not today's", () => {
        // The first request leaves the site knowing where main was. A second one must not be
        // decided on that memory when origin has stopped answering.
        const box = sandbox();
        expect(ask(box, `deploy ${commit.c5}`).released).toHaveLength(1);
        git(paths(box.site).repo, 'remote', 'set-url', 'origin', join(box.root, 'gone.git'));
        const result = ask(box, `deploy ${commit.c2}`);

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('Refused: origin could not be fetched');
        expect(result.released).toHaveLength(1);
    });
});

describe('deploy-gate.sh — requests it does not know', () => {
    const sha = () => commit.c2;

    it.each([
        ['a shell', () => undefined],
        ['an empty request', () => ''],
        ['a command', () => 'bash -i'],
        ['a file transfer', () => 'scp -t /home'],
        ['what git itself sends', () => "git-upload-pack 'preprod'"],
        ['the other request, misspelt', () => 'PHP-VERSION'],
        ['a request followed by another', () => 'php-version; id'],
        ['a request with an argument', () => 'php-version --all'],
        ['a request with a space before it', () => ' php-version'],
        ['a request and a line break', () => 'php-version\n'],
        ['a request and a carriage return', () => 'php-version\r'],
        ['deploy and a tab', () => `deploy\t${sha()}`],
        ['deploy and a non-breaking space', () => `deploy\u00a0${sha()}`],
        ['a hundred thousand characters', () => 'x'.repeat(100000)],
        ['deploy, alone', () => 'deploy'],
        ['deploy in capitals', () => `DEPLOY ${sha()}`],
        ['a deployment after something else', () => `true && deploy ${sha()}`],
        ['rollback, alone', () => 'rollback'],
        ['rollback in capitals', () => `ROLLBACK ${sha()}`],
        ['the two verbs glued together', () => `deploy-rollback ${sha()}`],
        ['a verb it never had', () => `undo ${sha()}`],
    ])('refuses %s', (_, request) => {
        const result = ask(sandbox(), request());

        expectRefused(result, 'this is not a request the key accepts.');
        expect(result.fetchedMain).toBe(false);
    });

    it.each([
        ['nothing', () => 'deploy '],
        ['a branch name', () => 'deploy main'],
        ['a ref', () => 'deploy refs/heads/main'],
        ['HEAD', () => 'deploy HEAD'],
        ['an abbreviated SHA', () => `deploy ${sha().slice(0, 12)}`],
        ['a SHA one character short', () => `deploy ${sha().slice(1)}`],
        ['a SHA one character long', () => `deploy ${sha()}0`],
        ['a SHA in capitals', () => `deploy ${sha().toUpperCase()}`],
        ['a SHA and a second word', () => `deploy ${sha()} --force`],
        ['two spaces before the SHA', () => `deploy  ${sha()}`],
        ['a SHA followed by a command', () => `deploy ${sha()}; id`],
        ['a SHA and a line break', () => `deploy ${sha()}\n`],
        ['a SHA and a carriage return', () => `deploy ${sha()}\r`],
        ['a SHA and a space', () => `deploy ${sha()} `],
        ['a SHA cut in two by a space', () => `deploy ${sha().slice(0, 20)} ${sha().slice(20)}`],
        [
            'a SHA in full-width digits',
            () => `deploy ${sha().replace(/[0-9]/g, (digit) => String.fromCharCode(0xff10 + Number(digit)))}`,
        ],
        ['a hundred thousand hexadecimal characters', () => `deploy ${'a'.repeat(100000)}`],
        ['a SHA followed by a second line', () => `deploy ${sha()}\nid`],
        ['a second line that is a SHA', () => `deploy main\n${sha()}`],
        ['an option for git', () => 'deploy --upload-pack=id'],
        ['a command substitution', () => 'deploy $(id)'],
    ])('refuses %s where a full SHA is expected', (_, request) => {
        const result = ask(sandbox(), request());

        expectRefused(result, 'what follows "deploy" is not the full SHA of a commit.');
        // Refused on its shape alone: git was never asked about it.
        expect(result.fetchedMain).toBe(false);
    });

    it('reads the request sshd hands over, never its own arguments', () => {
        // The forced command is fixed in authorized_keys; what the caller typed only ever arrives
        // in SSH_ORIGINAL_COMMAND.
        expectRefused(ask(sandbox(), undefined, { args: ['deploy', commit.c2] }), 'this is not a request');
        expectRefused(ask(sandbox(), 'bash', { args: ['php-version'] }), 'this is not a request');
        expectRefused(ask(sandbox(), 'bash', { args: ['rollback', commit.c1] }), 'this is not a request');
    });

    it('does not repeat a refused request, to the caller or in its journal', () => {
        const box = sandbox();
        const result = ask(box, 'deploy $(touch intruder); echo INTRUDER');

        expect(result.status).toBe(1);
        expect(result.stderr).not.toContain('INTRUDER');
        expect(result.journal.join('\n')).not.toContain('INTRUDER');
        expect(existsSync(join(paths(box.site).current, 'intruder'))).toBe(false);
        expect(existsSync(join(box.root, 'intruder'))).toBe(false);
    });
});

describe('deploy-gate.sh — a caller who chooses the locale', () => {
    // sshd commonly accepts LANG and LC_* from the client: the locale is the one part of the gate's
    // environment a caller gets to choose. In a UTF-8 locale, bash reads the range `a-f` by
    // collation rather than by code: `[0-9a-f]` then matches é, and digits of other scripts.
    const LOCALE = 'en_US.UTF-8';
    const widens = (range, character) =>
        spawnSync(BASH, ['-c', `pattern='^[${range}]$'; [[ "$1" =~ $pattern ]]`, 'bash', character], {
            env: { LC_ALL: LOCALE },
        }).status === 0;
    const withinSha = (character) => `${character}${commit.c2.slice(1)}`;

    it('is tested on a machine where that locale does widen a range, or the tests below prove nothing', () => {
        expect(widens('a-f', 'é')).toBe(true);
        expect(widens('0-9', '３')).toBe(true);
    });

    it.each([
        ['an accented letter', 'é'],
        ['a full-width letter', 'ｅ'],
        ['a full-width digit', '３'],
        ['an Arabic-Indic digit', '٣'],
    ])('still refuses %s in a SHA, on its shape', (_, character) => {
        const result = ask(sandbox(), `deploy ${withinSha(character)}`, { locale: LOCALE });

        expectRefused(result, 'what follows "deploy" is not the full SHA of a commit.');
        expect(result.fetchedMain).toBe(false);
    });

    it('still hands over a commit of main', () => {
        expect(ask(sandbox(), `deploy ${commit.c5}`, { locale: LOCALE }).released).toEqual(handed(commit.c5));
    });
});

describe('deploy-gate.sh — a site that cannot say what it serves', () => {
    // Everything is measured against the commit being served. Read as "nothing", it would make
    // every commit of main "not older", and "the one being served" whatever the caller says.
    it.each([
        ['has no RELEASE file', (directory) => rmSync(join(directory, 'RELEASE'))],
        ['has an empty RELEASE file', (directory) => writeFileSync(join(directory, 'RELEASE'), '')],
        ['names something that is not a SHA', (directory) => writeFileSync(join(directory, 'RELEASE'), 'main\n')],
        // A name git would resolve to a commit: only the shape says it is not what a release holds.
        ['names a branch its repository knows', (directory) => writeFileSync(join(directory, 'RELEASE'), 'feature\n')],
        [
            'holds its SHA with a carriage return',
            (directory) => writeFileSync(join(directory, 'RELEASE'), `${commit.c1}\r\n`),
        ],
        ['holds two lines', (directory) => writeFileSync(join(directory, 'RELEASE'), `${commit.c1}\n${commit.c1}\n`)],
    ])('refuses to deploy and to roll back when the release in service %s', (_, damage) => {
        const box = sandbox();
        damage(paths(box.site).current);

        const deploying = ask(box, `deploy ${commit.c5}`);
        expectRefused(deploying, 'this server cannot say which commit it is serving.');
        expect(deploying.journal.at(-1)).toContain(`refused not-ready ${commit.c5}`);
        // Decided before anything is asked of origin.
        expect(deploying.fetchedMain).toBe(false);

        expectRefused(ask(box, `rollback ${commit.c1}`), 'this server cannot say which commit it is serving.');
    });

    it.each([
        ['a commit its repository does not hold', () => 'deadbeef'.repeat(5)],
        ['a file of the repository, not a commit', () => commit.blob],
    ])('refuses to deploy when the release in service names %s', (_, served) => {
        // The shape is right, and there is nothing behind it to compare a commit with.
        const box = sandbox();
        writeFileSync(join(paths(box.site).current, 'RELEASE'), `${served()}\n`);
        const result = ask(box, `deploy ${commit.c5}`);

        expectRefused(result, 'this server cannot say which commit it is serving.');
        expect(result.fetchedMain).toBe(false);
    });

    it('still answers php-version: that request is measured against nothing', () => {
        const box = sandbox();
        rmSync(join(paths(box.site).current, 'RELEASE'));

        expect(ask(box, 'php-version').stdout).toBe('PHP 8.5.99\n');
    });
});

describe('deploy-gate.sh — a repository that has lost something', () => {
    // A disk that filled up during a fetch, a file system that lied: git then answers neither yes
    // nor no. Whatever it cannot tell is refused.
    const broken = (options) => {
        const box = sandbox(options);
        loosen(paths(box.site).repo);
        return box;
    };

    it('refuses to deploy when the repository is gone altogether', () => {
        const box = sandbox();
        rmSync(paths(box.site).repo, { recursive: true });
        const result = ask(box, `deploy ${commit.c5}`);

        expectRefused(result, 'this server cannot say which commit it is serving.');
    });

    it('refuses to deploy when the commit being served can no longer be read', () => {
        const box = broken();
        lose(paths(box.site).repo, commit.c1);

        expectRefused(ask(box, `deploy ${commit.c5}`), 'this server cannot say which commit it is serving.');
    });

    it('refuses a commit it cannot compare with the one being served, rather than call it "not older"', () => {
        // The second question the gate asks git: is this commit older than the one being served?
        // git answers yes, no, or fails — and "it failed" used to read as "no".
        const result = ask(sandbox(), `deploy ${commit.c5}`, { gitLost: 2 });

        expectRefused(result, 'the repository of this server cannot compare that commit with the one being served.');
        expect(result.journal.at(-1)).toContain(`refused unreadable ${commit.c5}`);
    });

    it('refuses a commit when git cannot tell whether it belongs to main', () => {
        const result = ask(sandbox(), `deploy ${commit.c5}`, { gitLost: 1 });

        expectRefused(result, 'that SHA is not a commit of main.');
    });

    it('asks git both questions for a deployment it accepts, and neither for the commit it serves', () => {
        // What the two tests above count on: the comparison is the second call, and it is made.
        const box = sandbox();
        ask(box, `deploy ${commit.c5}`, { gitLost: 99 });
        expect(readFileSync(join(box.root, 'merge-base.calls'), 'utf8').trim()).toBe('2');

        const replay = sandbox();
        ask(replay, `deploy ${commit.c1}`, { gitLost: 99 });
        expect(readFileSync(join(replay.root, 'merge-base.calls'), 'utf8').trim()).toBe('1');
    });

    it('decides, and writes what it decided, on a repository that has really lost a commit', () => {
        // The site serves the candidate c3, whose parent is gone. What git makes of that depends
        // on its version — an error while fetching, an error while comparing, or no error at all.
        // The gate has one of two answers either way: a refusal it names, or c5, which is a commit
        // of main and is not older than the candidate. It never ends without having decided.
        const box = broken({ serving: commit.c3 });
        lose(paths(box.site).repo, commit.c1);
        const result = ask(box, `deploy ${commit.c5}`);

        expect(result.journal).toHaveLength(1);
        expect(result.journal[0]).toMatch(
            new RegExp(`(refused (fetch|unreadable|not-on-main)|accepted deploy) ${commit.c5}$`),
        );
        expect(result.status === 0 ? result.released : []).toEqual(result.status === 0 ? handed(commit.c5) : []);
        expect(result.stillServing).toBe(true);
    });

    it('never takes a commit that is not main for one, whatever the state of its record of main', () => {
        const box = sandbox();
        const repo = paths(box.site).repo;
        mkdirSync(join(repo, 'refs', 'remotes', 'origin'), { recursive: true });
        writeFileSync(join(repo, 'refs', 'remotes', 'origin', 'main'), 'not a SHA at all\n');
        const result = ask(box, `deploy ${commit.c3}`);

        expect(result.status).toBe(1);
        expect(result.released).toEqual([]);
        expect(result.stderr).toContain('Refused:');
    });

    it('still undoes a deployment: going back asks nothing of the repository', () => {
        // The day a deployment went wrong because the repository did is the day the way back
        // must not depend on it.
        const box = sandbox({ serving: commit.c1 });
        const previous = releaseName(commit.c1, '20261011-070000');
        const name = release(box, commit.c2, '20261011-075000');
        writeFileSync(paths(box.site).lastDeployment, `${EPOCH - 60} ${name} ${previous}\n`);
        rmSync(paths(box.site).repo, { recursive: true });
        const result = ask(box, `rollback ${commit.c2}`);

        expect(result.stderr).toBe('');
        expect(result.rolledBack).toHaveLength(1);
    });
});

describe('deploy-gate.sh — one deployment at a time', () => {
    it('refuses a deployment while another one holds the lock, and says why', () => {
        const box = sandbox();
        const letGo = holdLock(box.site);
        try {
            const result = ask(box, `deploy ${commit.c5}`);

            expectRefused(result, 'a deployment is already running on this server.');
            expect(result.journal).toEqual([expect.stringContaining(`refused busy ${commit.c5}`)]);
        } finally {
            letGo();
        }
    });

    it('refuses a rollback while a deployment holds the lock', () => {
        const box = sandbox({ serving: commit.c1 });
        const previous = releaseName(commit.c1, '20261011-070000');
        const name = release(box, commit.c2, '20261011-075000');
        writeFileSync(paths(box.site).lastDeployment, `${EPOCH - 60} ${name} ${previous}\n`);
        const letGo = holdLock(box.site);
        try {
            const result = ask(box, `rollback ${commit.c2}`);

            expectRefused(result, 'a deployment is already running on this server.');
            expect(result.journal).toEqual([expect.stringContaining(`refused busy ${commit.c2}`)]);
        } finally {
            letGo();
        }
    });

    it('takes the lock last: a request it would refuse anyway is refused for what it is', () => {
        // Whether the server is busy is not something a request that will not be honoured gets to
        // learn, and the reason written in the journal is the one that would stand on a quiet day.
        const box = sandbox();
        const letGo = holdLock(box.site);
        try {
            const result = ask(box, `deploy ${commit.c3}`);

            expectRefused(result, 'that SHA is not a commit of main.');
            expect(result.stderr).not.toContain('already running');
        } finally {
            letGo();
        }
    });

    it('refuses, without naming a path, when the lock cannot be opened at all', () => {
        const box = sandbox();
        mkdirSync(paths(box.site).lock);
        const result = ask(box, `deploy ${commit.c5}`);

        expectRefused(result, 'this server cannot take its deployment lock.');
        expect(result.stderr).not.toContain(box.site);
        expect(result.journal).toEqual([expect.stringContaining(`refused no-lock ${commit.c5}`)]);
    });

    it('lets the lock go with the deployment: the next request is not refused', () => {
        const box = sandbox();
        expect(ask(box, `deploy ${commit.c2}`).status).toBe(0);

        expect(ask(box, `deploy ${commit.c5}`).released).toHaveLength(2);
    });
});

describe('deploy-gate.sh — undoing the deployment just made', () => {
    const PREVIOUS = () => releaseName(commit.c1, '20261011-070000');

    /**
     * A site where c2 has just replaced c1.
     *
     * @param {{ago?: number, record?: (name: string) => string|null}} [options]
     *        how long ago, in seconds, and what shared/last-deployment holds (null: no file)
     */
    function justDeployed({ ago = 60, record = (name) => `${EPOCH - ago} ${name} ${PREVIOUS()}\n` } = {}) {
        const box = sandbox({ serving: commit.c1 });
        const name = release(box, commit.c2, '20261011-075000');
        const content = record(name);
        if (content !== null) {
            writeFileSync(paths(box.site).lastDeployment, content);
        }
        return box;
    }

    it('hands the rollback over, with the lock held and without choosing a release', () => {
        const result = ask(justDeployed(), `rollback ${commit.c2}`);

        expect(result.stderr).toBe('');
        expect(result.status).toBe(0);
        // No argument: scripts/rollback.sh undoes the last deployment, and the gate never names
        // a release to it.
        expect(result.rolledBack).toEqual([{ args: [], count: 0, lock: 'held', lockFd: '9' }]);
        expect(result.released).toEqual([]);
        expect(result.journal).toEqual([expect.stringContaining(`accepted rollback ${commit.c2}`)]);
        // Nothing is asked of origin: going back needs no network.
        expect(result.fetchedMain).toBe(false);
    });

    it('accepts it up to half an hour after the deployment, to the second', () => {
        expect(ask(justDeployed({ ago: 1800 }), `rollback ${commit.c2}`).rolledBack).toHaveLength(1);
        expect(ask(justDeployed({ ago: 0 }), `rollback ${commit.c2}`).rolledBack).toHaveLength(1);
    });

    it.each([
        ['one second past half an hour', 1801],
        ['a day later', 86400],
        ['dated in the future', -1],
    ])('refuses a deployment %s: going back is then a human decision', (_, ago) => {
        const result = ask(justDeployed({ ago }), `rollback ${commit.c2}`);

        expectRefused(result, 'that deployment is more than half an hour old');
        expect(result.journal.at(-1)).toContain(`refused too-late ${commit.c2}`);
    });

    it('reads a time written with leading zeros as the same number of seconds', () => {
        // Bash arithmetic takes a leading zero for octal, and stops on an 8 or a 9.
        const box = justDeployed({ record: (name) => `00${EPOCH - 60} ${name} ${PREVIOUS()}\n` });
        const result = ask(box, `rollback ${commit.c2}`);

        expect(result.stderr).toBe('');
        expect(result.rolledBack).toHaveLength(1);
    });

    it('still refuses a day-old deployment whose time is written with a leading zero', () => {
        // Read as octal, the 8s and 9s of that number are an error — and an arithmetic error in a
        // condition is a condition that is false: "not too late". It has to be read, and refused.
        const box = justDeployed({ record: (name) => `0${EPOCH - 86400} ${name} ${PREVIOUS()}\n` });
        expect(String(EPOCH - 86400)).toMatch(/[89]/);

        expectRefused(ask(box, `rollback ${commit.c2}`), 'that deployment is more than half an hour old');
    });

    it('refuses a commit that is not the one being served', () => {
        // c1 is the release a rollback would go to, and c5 a commit of main: neither is "the
        // deployment just made".
        const box = justDeployed();

        expectRefused(ask(box, `rollback ${commit.c1}`), 'that commit is not the one being served');
        expectRefused(ask(box, `rollback ${commit.c5}`), 'that commit is not the one being served');
        expect(lines(paths(box.site).gateJournal).at(-1)).toContain(`refused not-served ${commit.c5}`);
    });

    it.each([
        ['no deployment is recorded', () => null],
        ['the record is empty', () => ''],
        ['the last deployment was of another release', () => `${EPOCH - 60} ${PREVIOUS()} ${PREVIOUS()}\n`],
        ['the record has no date', (name) => `yesterday ${name} ${PREVIOUS()}\n`],
        ['the date is followed by something', (name) => `${EPOCH - 60}; ${name} ${PREVIOUS()}\n`],
        ['the date is negative', (name) => `-${EPOCH} ${name} ${PREVIOUS()}\n`],
        // 2^64 plus a minute ago: counted on 64 bits, it comes back round to a minute ago.
        ['the date is too long to be one', (name) => `${2n ** 64n + BigInt(EPOCH - 60)} ${name} ${PREVIOUS()}\n`],
    ])('refuses when %s: what is served was not just put there by a deployment', (_, record) => {
        // After a rollback, or once a human has put another release in service, the record is gone
        // or names something else: asking again would walk further back, one release at a time.
        const result = ask(justDeployed({ record }), `rollback ${commit.c2}`);

        expectRefused(result, 'the release being served is not one a deployment has just put there.');
        expect(result.journal.at(-1)).toContain(`refused nothing-to-undo ${commit.c2}`);
    });

    it.each([
        ['that deployment was the first of the site', (name) => `${EPOCH - 60} ${name}\n`],
        ['the release before it has been removed', (name) => `${EPOCH - 60} ${name} ${releaseName(commit.c5)}\n`],
        ['the record names a path instead of a release', (name) => `${EPOCH - 60} ${name} ../../outside\n`],
        ['the record names the releases directory itself', (name) => `${EPOCH - 60} ${name} .\n`],
        ['the record says more than it should', (name) => `${EPOCH - 60} ${name} ${PREVIOUS()} and-more\n`],
    ])('refuses when %s', (_, record) => {
        // Both places are given what marks a served release: only its name can tell that neither
        // is one.
        const box = justDeployed({ record });
        mkdirSync(join(box.root, 'outside'));
        writeFileSync(join(box.root, 'outside', '.served'), '');
        writeFileSync(join(paths(box.site).releases, '.served'), '');
        const result = ask(box, `rollback ${commit.c2}`);

        expectRefused(result, 'the release that was served before that deployment is no longer there.');
        expect(result.journal.at(-1)).toContain(`refused no-previous ${commit.c2}`);
    });

    it('refuses when the release before it was never served: it may be half built', () => {
        const box = justDeployed();
        rmSync(join(paths(box.site).releases, PREVIOUS(), '.served'));

        expectRefused(
            ask(box, `rollback ${commit.c2}`),
            'the release that was served before that deployment is no longer there.',
        );
    });

    it.each([
        ['nothing', () => 'rollback '],
        ['a release name', () => `rollback ${PREVIOUS()}`],
        ['an abbreviated SHA', () => `rollback ${commit.c2.slice(0, 12)}`],
        ['a SHA and a release name', () => `rollback ${commit.c2} ${PREVIOUS()}`],
        ['a SHA followed by a command', () => `rollback ${commit.c2}; id`],
        ['a SHA and a line break', () => `rollback ${commit.c2}\n`],
    ])('refuses %s where the full SHA of the commit to undo is expected', (_, request) => {
        expectRefused(ask(justDeployed(), request()), 'what follows "rollback" is not the full SHA of a commit.');
    });
});

describe('deploy-gate.sh — the journal', () => {
    const entry = (decision) =>
        new RegExp(`^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z 203\\.0\\.113\\.7 ${decision}$`);

    it('keeps one line per request: when, from where, and what was decided', () => {
        const box = sandbox();
        ask(box, 'php-version');
        ask(box, 'rm -rf /');
        ask(box, 'deploy main');
        ask(box, `deploy ${commit.c3}`);
        const { journal } = ask(box, `deploy ${commit.c5}`);

        expect(journal).toHaveLength(5);
        expect(journal[0]).toMatch(entry('accepted php-version'));
        expect(journal[1]).toMatch(entry('refused request'));
        expect(journal[2]).toMatch(entry('refused sha-shape'));
        expect(journal[3]).toMatch(entry(`refused not-on-main ${commit.c3}`));
        expect(journal[4]).toMatch(entry(`accepted deploy ${commit.c5}`));
    });

    it('still keeps its line when sshd says nothing of where the request came from', () => {
        const box = sandbox();
        const result = spawnSync(join(box.bin, 'bash'), [join(paths(box.site).current, 'scripts', 'deploy-gate.sh')], {
            encoding: 'utf8',
            env: { PATH: box.bin, SSH_ORIGINAL_COMMAND: 'id' },
        });

        expect(result.status).toBe(1);
        expect(lines(paths(box.site).gateJournal)).toEqual([
            expect.stringMatching(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z {2}refused request$/),
        ]);
    });

    it('keeps it where every release finds it: in what the releases share', () => {
        const box = sandbox();
        ask(box, 'php-version');

        expect(existsSync(join(box.site, 'shared', 'storage', 'logs', 'deploy-gate.log'))).toBe(true);
        expect(existsSync(join(paths(box.site).current, 'storage'))).toBe(false);
    });

    it('records that origin could not be fetched', () => {
        const box = sandbox();
        git(paths(box.site).repo, 'remote', 'set-url', 'origin', join(box.root, 'gone.git'));

        expect(ask(box, `deploy ${commit.c2}`).journal).toEqual([
            expect.stringMatching(entry(`refused fetch ${commit.c2}`)),
        ]);
    });

    it('still decides when the disk is too full to keep its journal, and says so', () => {
        // /dev/full is the device the kernel answers "no space left" from.
        const box = sandbox();
        rmSync(paths(box.site).gateJournal, { force: true });
        symlinkSync('/dev/full', paths(box.site).gateJournal);

        const refused = ask(box, 'bash');
        expect(refused.status).toBe(1);
        expect(refused.stderr).toContain('deploy-gate.log could not be written.');
        expect(refused.stderr).toContain('Refused: this is not a request the key accepts.');

        const accepted = ask(box, `deploy ${commit.c2}`);
        expect(accepted.stderr).toContain('deploy-gate.log could not be written.');
        expect(accepted.released).toEqual(handed(commit.c2));
    });

    it('still decides when the journal cannot be written, and says so', () => {
        const refusing = sandbox();
        rmSync(join(refusing.site, 'shared', 'storage'), { recursive: true });
        const refused = ask(refusing, 'bash');

        expect(refused.status).toBe(1);
        expect(refused.stderr).toContain('shared/storage/logs/deploy-gate.log could not be written.');
        expect(refused.stderr).toContain('Refused: this is not a request the key accepts.');

        const accepting = sandbox();
        rmSync(join(accepting.site, 'shared', 'storage'), { recursive: true });
        const accepted = ask(accepting, `deploy ${commit.c2}`);

        expect(accepted.stderr).toContain('shared/storage/logs/deploy-gate.log could not be written.');
        expect(accepted.released).toEqual(handed(commit.c2));
    });
});
