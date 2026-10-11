// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { BASH, JOURNAL, callsIn, fillPath } from './support/sandbox.js';

// scripts/deploy-gate.sh is what stands between a stolen SSH key and the server. It is run here
// for real, against real git repositories: what it has to get right is which commits belong to
// main, and a stand-in for git would only answer what the test told it to.
//
// The layout is the server's. `origin.git` plays GitHub; `server` is the clone the gate lives in.
//
//   main     cA ── cB ── c1 ── cC ── c2 ── cD ── c5
//   feature               └── c3      a branch of the repository, never merged
//   pull/1                └── c4      the commit of a fork, as GitHub exposes it: fetchable by
//                                     its SHA, on no branch
//
// cA is from before the deployment scripts existed, cB has the script and no gate, cC the gate and
// no script, cD the script and no gate again. The server serves c1. cD and c5 are pushed after it
// cloned: only a fetch brings them.
const GATE = fileURLToPath(new URL('../deploy-gate.sh', import.meta.url));

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

const knows = (repository, object) =>
    spawnSync('git', ['cat-file', '-e', object], { cwd: repository, env: GIT_ENV }).status === 0;

// Stands for scripts/deploy.sh: says which version of itself ran, on which commit, and with how
// many arguments — the real one takes none and refuses any.
const deployScript = (version) =>
    `#!/bin/bash\nprintf 'deploy.sh\\x1f${version}\\x1f%s\\x1f%s\\n' "$(git rev-parse HEAD)" "$#" >> "$STUB_LOG"\n`;

const PHP = `${JOURNAL}
[[ "$1" == '-r' ]] && printf 'PHP 8.5.99\\n'
`;

let template;
const commit = {};
const sandboxes = [];

beforeAll(() => {
    template = mkdtempSync(join(tmpdir(), 'deploy-gate-template-'));
    const work = join(template, 'work');
    const origin = join(template, 'origin.git');
    const server = join(template, 'server');
    const write = (path, content) => {
        mkdirSync(join(work, path, '..'), { recursive: true });
        writeFileSync(join(work, path), content);
    };
    const record = (message) => {
        git(work, 'add', '.');
        git(work, 'commit', '--quiet', '-m', message);
        return git(work, 'rev-parse', 'HEAD');
    };

    git(template, 'init', '--quiet', '-b', 'main', 'work');
    write('storage/logs/.gitignore', '*\n!.gitignore\n');
    write('page.txt', 'before any script\n');
    commit.cA = record('cA');

    write('scripts/deploy.sh', deployScript('v0'));
    commit.cB = record('cB');

    write('scripts/deploy-gate.sh', readFileSync(GATE, 'utf8'));
    write('scripts/deploy.sh', deployScript('v1'));
    write('page.txt', 'first\n');
    commit.c1 = record('c1');

    rmSync(join(work, 'scripts', 'deploy.sh'));
    commit.cC = record('cC');

    write('scripts/deploy.sh', deployScript('v2'));
    write('page.txt', 'second\n');
    commit.c2 = record('c2');

    git(work, 'checkout', '--quiet', '-b', 'feature', commit.c1);
    write('feature.txt', 'unmerged\n');
    commit.c3 = record('c3');

    git(work, 'checkout', '--quiet', '--detach', commit.c1);
    write('fork.txt', 'from a fork\n');
    commit.c4 = record('c4');

    git(work, 'checkout', '--quiet', 'main');
    git(template, 'clone', '--quiet', '--bare', 'work', 'origin.git');
    git(work, 'push', '--quiet', origin, `${commit.c4}:refs/pull/1/head`);
    // --no-local: a plain clone of a local path copies the whole object store, the fork's commit
    // included. Through the transport, the server only receives what the branches reach — which
    // is what a clone from GitHub receives.
    git(template, 'clone', '--quiet', '--no-local', 'origin.git', 'server');

    rmSync(join(work, 'scripts', 'deploy-gate.sh'));
    commit.cD = record('cD');

    write('scripts/deploy-gate.sh', readFileSync(GATE, 'utf8'));
    write('page.txt', 'third\n');
    commit.c5 = record('c5');
    git(work, 'push', '--quiet', origin, 'main');

    commit.blob = git(work, 'rev-parse', `${commit.c1}:page.txt`);
    commit.tree = git(work, 'rev-parse', `${commit.c1}^{tree}`);
    commit.nowhere = 'deadbeef'.repeat(5);

    // The server serves c1, and has not heard from origin since it cloned: it holds c2 and c3, has
    // never seen c4 or c5, and no longer knows where main is. Only a fetch can tell it.
    git(server, 'checkout', '--quiet', '--detach', commit.c1);
    git(server, 'update-ref', '-d', 'refs/remotes/origin/main');

    fillPath(join(template, 'bin'), { real: ['bash', 'git', 'dirname', 'date'], standins: { php: PHP } });
});

afterAll(() => rmSync(template, { recursive: true, force: true }));

afterEach(() => {
    sandboxes.splice(0).forEach((root) => rmSync(root, { recursive: true, force: true }));
});

function sandbox() {
    const root = mkdtempSync(join(tmpdir(), 'deploy-gate-'));
    sandboxes.push(root);
    cpSync(template, root, { recursive: true, verbatimSymlinks: true });

    const box = { root, server: join(root, 'server'), bin: join(root, 'bin'), log: join(root, 'calls.log') };
    git(box.server, 'remote', 'set-url', 'origin', join(root, 'origin.git'));
    return box;
}

/**
 * What the gate does when sshd hands it a request.
 *
 * @param {object} box
 * @param {string|undefined} request  SSH_ORIGINAL_COMMAND; undefined when the caller asked for a shell
 * @param {{args?: string[], cwd?: string, locale?: string}} [options]
 */
function ask(box, request, { args = [], cwd = box.root, locale } = {}) {
    const env = {
        PATH: box.bin,
        HOME: box.root,
        GIT_CONFIG_GLOBAL: '/dev/null',
        GIT_CONFIG_NOSYSTEM: '1',
        SSH_CONNECTION: '203.0.113.7 51234 198.51.100.1 22',
        STUB_LOG: box.log,
        ...(locale ? { LC_ALL: locale, LANG: locale } : {}),
    };
    if (request !== undefined) {
        env.SSH_ORIGINAL_COMMAND = request;
    }
    const result = spawnSync(join(box.bin, 'bash'), [join(box.server, 'scripts', 'deploy-gate.sh'), ...args], {
        cwd,
        encoding: 'utf8',
        env,
    });
    const journal = join(box.server, 'storage', 'logs', 'deploy-gate.log');
    const calls = callsIn(box.log);

    return {
        status: result.status,
        stdout: result.stdout,
        stderr: result.stderr,
        head: git(box.server, 'rev-parse', 'HEAD'),
        deployed: calls
            .filter(([tool]) => tool === 'deploy.sh')
            .map(([, version, sha, args]) => ({ version, sha, args: Number(args) })),
        php: calls.filter(([tool]) => tool === 'php'),
        fetchedMain: knows(box.server, 'refs/remotes/origin/main'),
        journal: existsSync(journal) ? readFileSync(journal, 'utf8').split('\n').filter(Boolean) : [],
    };
}

// Refused: said so, exit 1, and the server still serves what it served.
function expectRefused(result, reason) {
    expect(result.stderr).toContain(`Refused: ${reason}`);
    expect(result.stderr).toContain('This key accepts "php-version" and "deploy <sha>"');
    expect(result.status).toBe(1);
    expect(result.head).toBe(commit.c1);
    expect(result.deployed).toEqual([]);
    expect(result.php).toEqual([]);
}

describe('deploy-gate.sh — what the key may ask', () => {
    it('reports the PHP version, and changes nothing', () => {
        const result = ask(sandbox(), 'php-version');

        expect(result.status).toBe(0);
        expect(result.stdout).toBe('PHP 8.5.99\n');
        expect(result.head).toBe(commit.c1);
        expect(result.deployed).toEqual([]);
        expect(result.fetchedMain).toBe(false);
    });

    it('deploys a commit of main it had never seen: it fetches before it decides', () => {
        const result = ask(sandbox(), `deploy ${commit.c5}`);

        expect(result.stderr).toBe('');
        expect(result.status).toBe(0);
        expect(result.head).toBe(commit.c5);
        expect(result.deployed).toEqual([{ version: 'v2', sha: commit.c5, args: 0 }]);
    });

    it('runs the deployment script of the commit it deploys, not the one it was serving', () => {
        const box = sandbox();

        // The server serves c1, whose script is v1.
        expect(ask(box, `deploy ${commit.c2}`).deployed).toEqual([{ version: 'v2', sha: commit.c2, args: 0 }]);
    });

    it('deploys again the commit it is serving: a deployment that failed halfway can be replayed', () => {
        const result = ask(sandbox(), `deploy ${commit.c1}`);

        expect(result.status).toBe(0);
        expect(result.deployed).toEqual([{ version: 'v1', sha: commit.c1, args: 0 }]);
    });

    it('works whatever the directory sshd started it in', () => {
        const box = sandbox();

        expect(ask(box, `deploy ${commit.c2}`, { cwd: tmpdir() }).head).toBe(commit.c2);
    });
});

describe('deploy-gate.sh — going back', () => {
    it('refuses a commit of main older than the one it serves', () => {
        // c1 is a commit of main, it carries both scripts, and it was being served a moment ago.
        // It is also what main was before c2 — the flaw c2 may have fixed.
        const box = sandbox();
        expect(ask(box, `deploy ${commit.c2}`).head).toBe(commit.c2);
        const result = ask(box, `deploy ${commit.c1}`);

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('Refused: that commit is older than the one being served');
        expect(result.head).toBe(commit.c2);
        expect(result.deployed).toHaveLength(1);
        expect(result.journal.at(-1)).toContain(`refused older ${commit.c1}`);
    });

    it('measures "older" against what is served, not against where main is', () => {
        // c2 is far behind the head of main and ahead of the server: that is going forward.
        const result = ask(sandbox(), `deploy ${commit.c2}`);

        expect(result.status).toBe(0);
        expect(result.head).toBe(commit.c2);
    });

    it('refuses the base of a candidate being served, and lets main take the server back', () => {
        // A human put the unmerged branch on the server, as docs/deployment.md allows. c1, which
        // the branch starts from, is behind it; the head of main is not.
        const box = sandbox();
        git(box.server, 'checkout', '--quiet', '--detach', commit.c3);

        const back = ask(box, `deploy ${commit.c1}`);
        expect(back.status).toBe(1);
        expect(back.stderr).toContain('Refused: that commit is older than the one being served');
        expect(back.head).toBe(commit.c3);

        expect(ask(box, `deploy ${commit.c5}`).head).toBe(commit.c5);
    });
});

describe('deploy-gate.sh — commits that are not main', () => {
    it('refuses a commit of another branch, even one the server already holds', () => {
        const box = sandbox();
        expect(knows(box.server, commit.c3)).toBe(true);

        expectRefused(ask(box, `deploy ${commit.c3}`), 'that SHA is not a commit of main.');
    });

    it('refuses the commit of a fork, and does not even fetch it', () => {
        // GitHub answers for this SHA from the repository's own address. Fetching "that commit"
        // instead of "main" would bring it in, and it would then deploy.
        const box = sandbox();
        expect(knows(join(box.root, 'origin.git'), commit.c4)).toBe(true);
        expect(knows(box.server, commit.c4)).toBe(false);

        expectRefused(ask(box, `deploy ${commit.c4}`), 'that SHA is not a commit of main.');
        expect(knows(box.server, commit.c4)).toBe(false);
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
        ['that has the deployment script and no gate', 'cB'],
        ['that has the gate and no deployment script', 'cC'],
        ['that is ahead of the server and has lost its gate', 'cD'],
    ])('refuses a commit of main %s, without leaving the commit it serves', (_, name) => {
        // Checked out, such a commit has nothing to deploy it, and the first two take the gate
        // away with them: the key would then answer nothing at all until someone logs in by hand.
        const box = sandbox();
        const result = ask(box, `deploy ${commit[name]}`);

        expectRefused(result, 'that commit does not carry the deployment scripts');
        expect(existsSync(join(box.server, 'scripts', 'deploy-gate.sh'))).toBe(true);
        expect(readFileSync(join(box.server, 'page.txt'), 'utf8')).toBe('first\n');
        expect(result.journal.at(-1)).toContain(`refused no-scripts ${commit[name]}`);
    });

    it('refuses everything when origin cannot be reached, rather than trust what it last saw', () => {
        const box = sandbox();
        git(box.server, 'remote', 'set-url', 'origin', join(box.root, 'gone.git'));

        // c2 is a commit of main, and the server holds it: without a fetch that succeeded, that
        // is not something it can know.
        expectRefused(ask(box, `deploy ${commit.c2}`), 'origin could not be fetched');
    });

    it("refuses the same way after a deployment that went through: yesterday's main is not today's", () => {
        // The first request leaves the server knowing where main was. A second one must not be
        // decided on that memory when origin has stopped answering.
        const box = sandbox();
        expect(ask(box, `deploy ${commit.c5}`).head).toBe(commit.c5);
        git(box.server, 'remote', 'set-url', 'origin', join(box.root, 'gone.git'));
        const result = ask(box, `deploy ${commit.c2}`);

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('Refused: origin could not be fetched');
        expect(result.head).toBe(commit.c5);
        expect(result.deployed).toHaveLength(1);
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
    });

    it('does not repeat a refused request, to the caller or in its journal', () => {
        const box = sandbox();
        const result = ask(box, 'deploy $(touch intruder); echo INTRUDER');

        expect(result.status).toBe(1);
        expect(result.stderr).not.toContain('INTRUDER');
        expect(result.journal.join('\n')).not.toContain('INTRUDER');
        expect(existsSync(join(box.server, 'intruder'))).toBe(false);
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

    it('still deploys a commit of main', () => {
        expect(ask(sandbox(), `deploy ${commit.c5}`, { locale: LOCALE }).head).toBe(commit.c5);
    });
});

describe('deploy-gate.sh — a server that is not ready', () => {
    it('stops when the checkout would lose a change made on the server, and deploys nothing', () => {
        const box = sandbox();
        writeFileSync(join(box.server, 'page.txt'), 'edited by hand on the server\n');
        const result = ask(box, `deploy ${commit.c5}`);

        expect(result.status).not.toBe(0);
        expect(result.head).toBe(commit.c1);
        expect(result.deployed).toEqual([]);
        expect(readFileSync(join(box.server, 'page.txt'), 'utf8')).toBe('edited by hand on the server\n');
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

    it('records that origin could not be fetched', () => {
        const box = sandbox();
        git(box.server, 'remote', 'set-url', 'origin', join(box.root, 'gone.git'));

        expect(ask(box, `deploy ${commit.c2}`).journal).toEqual([
            expect.stringMatching(entry(`refused fetch ${commit.c2}`)),
        ]);
    });

    it('still decides when the journal cannot be written, and says so', () => {
        const refusing = sandbox();
        rmSync(join(refusing.server, 'storage'), { recursive: true });
        const refused = ask(refusing, 'bash');

        expect(refused.status).toBe(1);
        expect(refused.stderr).toContain('Warning: storage/logs/deploy-gate.log could not be written.');
        expect(refused.stderr).toContain('Refused: this is not a request the key accepts.');

        const accepting = sandbox();
        rmSync(join(accepting.server, 'storage'), { recursive: true });
        const accepted = ask(accepting, `deploy ${commit.c2}`);

        expect(accepted.stderr).toContain('Warning: storage/logs/deploy-gate.log could not be written.');
        expect(accepted.head).toBe(commit.c2);
    });
});
