// @vitest-environment node
import { describe, it, expect, afterEach } from 'vitest';
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { USAGE, cli, deletions, isCode, mutate, parse, runner } from '../mutate-lines.js';

// The tool that says whether the other tests can be trusted is held to what it asks of them:
// what it must refuse comes first — a command that already fails, a file it would leave broken.
const SCRIPT = fileURLToPath(new URL('../mutate-lines.js', import.meta.url));

const SOURCE = ['#!/usr/bin/env bash', '# a comment', '', 'keep-a', 'idle', '  // another comment', 'keep-b', ''].join(
    '\n',
);

// Succeeds when the file given as its argument still holds both lines that matter.
const CHECK =
    "const text = require('fs').readFileSync(process.argv[1], 'utf8'); process.exit(text.includes('keep-a') && text.includes('keep-b') ? 0 : 1)";

const sandboxes = [];

afterEach(() => {
    sandboxes.splice(0).forEach((root) => rmSync(root, { recursive: true, force: true }));
});

function target(content = SOURCE) {
    const root = mkdtempSync(join(tmpdir(), 'mutate-lines-'));
    sandboxes.push(root);
    const file = join(root, 'target.sh');
    writeFileSync(file, content);
    return { root, file, copy: `${file}.before-mutation` };
}

// What `cli` printed, and what it returned.
async function run(argv, { passes, cwd = tmpdir() } = {}) {
    const out = [];
    const err = [];
    const code = await cli({ argv, cwd, out: (line) => out.push(line), err: (line) => err.push(line), passes });
    return {
        code,
        out: out.join('\n'),
        progress: out.filter((line) => line === '.' || line === 'S').join(''),
        err: err.join('\n'),
    };
}

describe('isCode', () => {
    it.each([
        '',
        '   ',
        '\t',
        '\r',
        '# a comment',
        '   # indented',
        '#!/usr/bin/env bash',
        '// a comment',
        '  // indented',
        '/**',
        '/* a block comment */',
        ' * inside a block comment',
        ' *',
        ' */',
    ])('leaves %j alone', (line) => {
        expect(isCode(line)).toBe(false);
    });

    it.each([
        'exit 1',
        '  fi',
        'x=1 # with a comment after it',
        '}',
        '- name: step',
        'a // b',
        // The last branch of a shell `case`: it starts with an asterisk and is not a comment.
        '  *) refuse "unknown argument" ;;',
        '*)',
        '*.log',
    ])('deletes %j', (line) => {
        expect(isCode(line)).toBe(true);
    });
});

describe('deletions', () => {
    it('lists each line of code once, with its number and the file without it', () => {
        expect(deletions('one\n# two\n\nfour\n')).toEqual([
            { number: 1, text: 'one', mutant: '# two\n\nfour\n' },
            { number: 4, text: 'four', mutant: 'one\n# two\n\n' },
        ]);
    });

    it('changes nothing else: a missing final newline stays missing', () => {
        expect(deletions('one\ntwo')).toEqual([
            { number: 1, text: 'one', mutant: 'two' },
            { number: 2, text: 'two', mutant: 'one' },
        ]);
    });

    it('changes nothing else: Windows line endings stay where they are', () => {
        expect(deletions('one\r\ntwo\r\n').map(({ mutant }) => mutant)).toEqual(['two\r\n', 'one\r\n']);
    });

    it.each(['', '\n\n', '# only\n// comments\n'])('finds nothing to delete in %j', (source) => {
        expect(deletions(source)).toEqual([]);
    });
});

describe('parse', () => {
    it('reads the file, then the command after --', () => {
        expect(parse(['a.sh', '--', 'npx', 'vitest', 'run'])).toEqual({
            file: 'a.sh',
            command: ['npx', 'vitest', 'run'],
        });
    });

    it('keeps a second -- for the command it belongs to', () => {
        expect(parse(['a.sh', '--', 'npm', 'test', '--', '--bail'])).toEqual({
            file: 'a.sh',
            command: ['npm', 'test', '--', '--bail'],
        });
    });

    it.each([
        ['nothing', []],
        ['a file alone', ['a.sh']],
        ['a file and no command', ['a.sh', '--']],
        ['a command and no file', ['--', 'true']],
        ['two files', ['a.sh', 'b.sh', '--', 'true']],
        ['a command without its --', ['a.sh', 'true']],
    ])('refuses %s', (_, argv) => {
        expect(parse(argv)).toBeNull();
    });
});

describe('mutate', () => {
    // A file and a command made of plain functions: the command passes while `needed` are there.
    function harness(source, needed, { failsFirst = false, breaksAt = null } = {}) {
        const state = { content: source, writes: [], runs: 0 };
        return {
            state,
            input: {
                source,
                write: (content) => {
                    state.content = content;
                    state.writes.push(content);
                },
                passes: async () => {
                    state.runs += 1;
                    if (breaksAt === state.runs) {
                        throw new Error('the command could not be awaited');
                    }
                    return !(failsFirst && state.runs === 1) && needed.every((line) => state.content.includes(line));
                },
            },
        };
    }

    it('names the lines whose deletion the command does not notice', async () => {
        const { input } = harness('alpha\nidle\nbeta\nidle too\n', ['alpha', 'beta']);

        expect(await mutate(input)).toEqual({
            tried: 4,
            unnoticed: [
                { number: 2, text: 'idle' },
                { number: 4, text: 'idle too' },
            ],
            problem: null,
        });
    });

    it('finds nothing when every line is needed', async () => {
        const { input } = harness('one\ntwo\n', ['one', 'two']);

        expect(await mutate(input)).toEqual({ tried: 2, unnoticed: [], problem: null });
    });

    it('refuses to start when the command fails on the file as it is, and touches nothing', async () => {
        // Run anyway, the pass would report every deletion as noticed: a perfect score, from a
        // command that cannot pass at all.
        const { input, state } = harness('one\ntwo\n', ['one', 'two'], { failsFirst: true });
        const result = await mutate(input);

        expect(result.problem).toContain('The command fails before anything is deleted.');
        expect(result).toMatchObject({ tried: 0, unnoticed: [] });
        expect(state.writes).toEqual([]);
        expect(state.runs).toBe(1);
    });

    it('runs the command once on the file as it is, then once per deletion', async () => {
        const { input, state } = harness('one\n# comment\ntwo\n', ['one']);
        await mutate(input);

        expect(state.runs).toBe(3);
        expect(state.writes).toEqual(['# comment\ntwo\n', 'one\n# comment\n', 'one\n# comment\ntwo\n']);
    });

    it('puts the file back as it was, last of all', async () => {
        const { input, state } = harness(SOURCE, ['keep-a']);
        await mutate(input);

        expect(state.content).toBe(SOURCE);
    });

    it('puts the file back even when the command blows up halfway', async () => {
        const { input, state } = harness('one\ntwo\nthree\n', ['one'], { breaksAt: 3 });

        await expect(mutate(input)).rejects.toThrow('the command could not be awaited');
        expect(state.content).toBe('one\ntwo\nthree\n');
    });

    it('reports each deletion as it is judged', async () => {
        const { input } = harness('needed\nidle\n', ['needed']);
        const seen = [];
        await mutate({ ...input, progress: (noticed) => seen.push(noticed) });

        expect(seen).toEqual([true, false]);
    });

    it('has nothing to try in a file that holds no code, and says so with a zero', async () => {
        const { input } = harness('# only a comment\n', []);

        expect(await mutate(input)).toEqual({ tried: 0, unnoticed: [], problem: null });
    });
});

describe('runner', () => {
    const node = (code, options = {}) => runner([process.execPath, '-e', code], { cwd: tmpdir(), ...options });

    it('says a command that exits 0 passed', async () => {
        expect(await node('process.exit(0)')()).toBe(true);
    });

    it.each([1, 2, 130])('says a command that exits %i did not', async (code) => {
        expect(await node(`process.exit(${code})`)()).toBe(false);
    });

    it('says a command that cannot be started did not pass', async () => {
        expect(await runner(['/nonexistent/command'], { cwd: tmpdir() })()).toBe(false);
    });

    it('says a command that outlives the timeout did not pass', async () => {
        expect(await node('setTimeout(() => {}, 30000)', { timeout: 150 })()).toBe(false);
    });

    it('runs the command where it is told to', async () => {
        const { root } = target();
        const passes = runner([process.execPath, '-e', "process.exit(require('fs').existsSync('target.sh') ? 0 : 1)"], {
            cwd: root,
        });

        expect(await passes()).toBe(true);
    });
});

describe('cli', () => {
    it('lists the deletions no test noticed, by file and line, and exits 1', async () => {
        const { file, copy } = target();
        const result = await run([file, '--', process.execPath, '-e', CHECK, file]);

        expect(result.code).toBe(1);
        expect(result.progress).toBe('.S.');
        expect(result.out).toContain(`${file}: 3 line(s) deleted one at a time, 1 deletion(s) no test noticed.`);
        expect(result.out).toContain(`  ${file}:5: idle`);
        expect(result.out).toContain('a line to test, a line to delete, or a line whose absence changes nothing');
        expect(result.err).toBe('');
        expect(readFileSync(file, 'utf8')).toBe(SOURCE);
        expect(existsSync(copy)).toBe(false);
    });

    it('exits 0 when every deletion is noticed', async () => {
        const { file, copy } = target('keep-a\nkeep-b\n');
        const result = await run([file, '--', process.execPath, '-e', CHECK, file]);

        expect(result.code).toBe(0);
        expect(result.out).toContain('2 line(s) deleted one at a time, 0 deletion(s) no test noticed.');
        expect(result.out).not.toContain('a line to test');
        expect(readFileSync(file, 'utf8')).toBe('keep-a\nkeep-b\n');
        expect(existsSync(copy)).toBe(false);
    });

    it('keeps a copy of the original beside the file while it works', async () => {
        const { file, copy } = target();
        const seen = [];
        await run([file, '--', 'unused'], {
            passes: async () => {
                seen.push(existsSync(copy) ? readFileSync(copy, 'utf8') : null);
                return true;
            },
        });

        expect(seen).toHaveLength(4);
        expect(new Set(seen)).toEqual(new Set([SOURCE]));
    });

    it('can be interrupted while it works, and no longer once it is done', async () => {
        // The signal handler calls whatever `restore` holds. Left in place after the pass, it would
        // rewrite the file with what it was — over anything saved to it since.
        const { file } = target();
        const interruption = { restore: null };
        const during = [];
        await cli({
            argv: [file, '--', 'unused'],
            out: () => {},
            err: () => {},
            interruption,
            passes: async () => {
                during.push(typeof interruption.restore);
                return true;
            },
        });

        expect(new Set(during)).toEqual(new Set(['function']));
        expect(interruption.restore).toBeNull();
    });

    it('refuses a command that already fails, exits 2, and leaves nothing behind', async () => {
        const { file, copy } = target();
        const result = await run([file, '--', process.execPath, '-e', 'process.exit(1)']);

        expect(result.code).toBe(2);
        expect(result.err).toContain('The command fails before anything is deleted.');
        expect(result.err).toContain('Every deletion would then look noticed, and the pass would prove nothing.');
        expect(result.err).toContain('make it pass on the file as it is');
        expect(result.out).toBe('');
        expect(readFileSync(file, 'utf8')).toBe(SOURCE);
        expect(existsSync(copy)).toBe(false);
    });

    it('refuses a command that cannot be started, the same way', async () => {
        const { file } = target();
        const result = await run([file, '--', '/nonexistent/command']);

        expect(result.code).toBe(2);
        expect(result.err).toContain('The command fails before anything is deleted.');
        expect(readFileSync(file, 'utf8')).toBe(SOURCE);
    });

    it('puts the file back and removes its copy when the command blows up halfway', async () => {
        const { file, copy } = target();
        let runs = 0;
        const passes = async () => {
            runs += 1;
            if (runs === 3) {
                throw new Error('the command could not be awaited');
            }
            return true;
        };

        await expect(run([file, '--', 'unused'], { passes })).rejects.toThrow('the command could not be awaited');
        expect(readFileSync(file, 'utf8')).toBe(SOURCE);
        expect(existsSync(copy)).toBe(false);
    });

    it('refuses to start over a copy a killed pass left behind, and touches neither file', async () => {
        // The file may be the mutant that pass was running, and the copy the only original left:
        // starting again would overwrite the copy with the mutant.
        const { file, copy } = target('a mutant\n');
        writeFileSync(copy, SOURCE);
        const result = await run([file, '--', process.execPath, '-e', CHECK, file]);

        expect(result.code).toBe(2);
        expect(result.err).toContain(`${copy} exists: a previous pass was killed`);
        expect(result.err).toContain(`put the right one back as ${file}, delete the other`);
        expect(readFileSync(file, 'utf8')).toBe('a mutant\n');
        expect(readFileSync(copy, 'utf8')).toBe(SOURCE);
    });

    it('says which file it could not read', async () => {
        const result = await run(['/nonexistent/target.sh', '--', 'true']);

        expect(result.code).toBe(2);
        expect(result.err).toContain('/nonexistent/target.sh could not be read');
    });

    it.each([[[]], [['a.sh']], [['a.sh', '--']], [['a.sh', 'true']]])(
        'explains how it is called for %j',
        async (argv) => {
            const result = await run(argv);

            expect(result.code).toBe(2);
            expect(result.err).toBe(USAGE);
        },
    );
});

describe('scripts/mutate-lines.js, as a process', () => {
    it('exits with what it found, and prints it', () => {
        const { root, file } = target();
        const result = spawnSync(process.execPath, [SCRIPT, file, '--', process.execPath, '-e', CHECK, file], {
            cwd: root,
            encoding: 'utf8',
        });

        expect(result.status).toBe(1);
        expect(result.stdout).toContain('.S.\n');
        expect(result.stdout).toContain(`${file}:5: idle`);
        expect(readFileSync(file, 'utf8')).toBe(SOURCE);
    });

    it('runs the command from the directory it was started in', () => {
        const { root, file } = target('keep-a\nkeep-b\n');
        const relative =
            "const text = require('fs').readFileSync('target.sh', 'utf8'); process.exit(text.includes('keep-a') && text.includes('keep-b') ? 0 : 1)";
        const result = spawnSync(process.execPath, [SCRIPT, file, '--', process.execPath, '-e', relative], {
            cwd: root,
            encoding: 'utf8',
        });

        expect(result.status).toBe(0);
        expect(result.stdout).toContain('2 line(s) deleted one at a time, 0 deletion(s) no test noticed.');
    });

    it('exits 2 and explains itself when it is called with nothing', () => {
        const result = spawnSync(process.execPath, [SCRIPT], { encoding: 'utf8' });

        expect(result.status).toBe(2);
        expect(result.stderr).toContain(USAGE);
    });

    it.each(['SIGINT', 'SIGTERM'])('puts the file back when it is interrupted by %s', async (signal) => {
        // A command slow enough for the pass to be caught with a line deleted.
        const { root, file, copy } = target();
        const slow = 'setTimeout(() => process.exit(0), 150)';
        const child = spawn(process.execPath, [SCRIPT, file, '--', process.execPath, '-e', slow], {
            cwd: root,
            stdio: 'ignore',
        });
        const exited = new Promise((resolve) => child.on('close', (code) => resolve(code)));

        // Wait for the moment that matters, rather than for a duration: the file is a mutant.
        const deadline = Date.now() + 10_000;
        while (readFileSync(file, 'utf8') === SOURCE) {
            expect(Date.now(), 'the pass never deleted a line').toBeLessThan(deadline);
            await new Promise((resolve) => setTimeout(resolve, 10));
        }
        expect(existsSync(copy)).toBe(true);
        child.kill(signal);

        expect(await exited).toBe(130);
        expect(readFileSync(file, 'utf8')).toBe(SOURCE);
        expect(existsSync(copy)).toBe(false);
    });
});
