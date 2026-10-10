// Mechanical mutation pass — deletes each line of a file in turn, and names the deletions that no
// test notices.
// Usage: node scripts/mutate-lines.js <file> -- <command that fails when the file is broken>
//
//   node scripts/mutate-lines.js scripts/update-frankenphp.sh -- \
//       npx vitest run scripts/__tests__/update-frankenphp.test.js --bail=1
//
// docs/testing-conventions.md asks that a test be trusted only once breaking the code has turned
// it red. Mutations chosen by hand are chosen by whoever wrote the tests, and share their blind
// spots: on 2026-10-10, 97 of them left update-frankenphp.sh "fully tested" while 36 of its lines
// could be deleted without a test failing. This pass chooses nothing. It is a floor, not a method:
// it finds the line nobody tests, never the rule that was coded wrong.
//
// Exit code: 0 when every deletion is noticed, 1 when at least one is not, 2 when the pass could
// not be run — a command that already fails would make every deletion look noticed.
//
// The file is rewritten in place for each run and restored afterwards, whatever happens. A copy of
// the original sits next to it in the meantime, in case the process is killed outright.

import { spawn } from 'node:child_process';
import { existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

export const USAGE = 'Usage: node scripts/mutate-lines.js <file> -- <command that fails when the file is broken>';

// A line is left alone when it is blank or a comment: deleting it changes nothing a test could see.
// `* ` and `*/` are the inside and the end of a block comment. `*)` is not: it is the last branch
// of a shell `case`, and very much code.
export const COMMENT_PREFIXES = ['#', '//', '/*', '* ', '*/'];

// Long enough for a suite, short enough that a deletion which makes the code loop ends the run.
export const RUN_TIMEOUT_MS = 300_000;

/**
 * Tells whether deleting a line could change what the file does.
 *
 * @param {string} line
 * @returns {boolean}
 */
export function isCode(line) {
    const text = line.trim();
    return text !== '' && text !== '*' && !COMMENT_PREFIXES.some((prefix) => text.startsWith(prefix));
}

/**
 * Lists every deletion to try: the line, its number, and the file without it.
 *
 * Line endings and a missing final newline are kept as they are: the mutant differs from the
 * original by one line and nothing else.
 *
 * @param {string} source
 * @returns {{number: number, text: string, mutant: string}[]}
 */
export function deletions(source) {
    const lines = source.split('\n');
    return lines
        .map((text, index) => ({
            number: index + 1,
            text,
            mutant: [...lines.slice(0, index), ...lines.slice(index + 1)].join('\n'),
        }))
        .filter(({ text }) => isCode(text));
}

/**
 * Splits the command line into the file and the command, or returns null when it is not one.
 *
 * @param {string[]} argv
 * @returns {{file: string, command: string[]}|null}
 */
export function parse(argv) {
    const separator = argv.indexOf('--');
    if (separator !== 1 || argv.length < 3) {
        return null;
    }
    return { file: argv[0], command: argv.slice(2) };
}

/**
 * Runs the pass. Returns what it found, or the reason it could not run.
 *
 * `passes` runs the command and resolves to true when it succeeded. `write` rewrites the file.
 * The original is written back before this function returns or throws, without exception.
 *
 * @param {{
 *   source: string,
 *   write: (content: string) => void,
 *   passes: () => Promise<boolean>,
 *   progress?: (noticed: boolean) => void,
 * }} input
 * @returns {Promise<{tried: number, unnoticed: {number: number, text: string}[], problem: string|null}>}
 */
export async function mutate({ source, write, passes, progress = () => {} }) {
    if (!(await passes())) {
        return {
            tried: 0,
            unnoticed: [],
            problem:
                'The command fails before anything is deleted.\n' +
                '  Every deletion would then look noticed, and the pass would prove nothing.\n' +
                '  → make it pass on the file as it is, then run this again.',
        };
    }

    const unnoticed = [];
    const candidates = deletions(source);
    try {
        for (const { number, text, mutant } of candidates) {
            write(mutant);
            const noticed = !(await passes());
            if (!noticed) {
                unnoticed.push({ number, text });
            }
            progress(noticed);
        }
    } finally {
        write(source);
    }
    return { tried: candidates.length, unnoticed, problem: null };
}

/**
 * Builds the function that runs a command and tells whether it succeeded.
 *
 * A command that cannot be started, or that outlives the timeout, has not succeeded.
 *
 * @param {string[]} command
 * @param {{cwd?: string, timeout?: number}} [options]  cwd defaults to the directory this was started from
 * @returns {() => Promise<boolean>}
 */
export function runner(command, { cwd, timeout = RUN_TIMEOUT_MS } = {}) {
    return () =>
        new Promise((resolve) => {
            const child = spawn(command[0], command.slice(1), { cwd, stdio: 'ignore', timeout });
            child.on('error', () => resolve(false));
            child.on('close', (code) => resolve(code === 0));
        });
}

/**
 * The command line: runs the pass on one file, prints the verdict, returns the exit code.
 *
 * @param {{
 *   argv: string[],
 *   cwd?: string,
 *   out: (line: string) => void,
 *   err: (line: string) => void,
 *   passes?: () => Promise<boolean>,
 *   interruption?: {restore: (() => void)|null},
 * }} options
 * @returns {Promise<number>}
 */
export async function cli({ argv, cwd, out, err, passes, interruption = { restore: null } }) {
    const parsed = parse(argv);
    if (parsed === null) {
        err(USAGE);
        return 2;
    }

    const { file, command } = parsed;
    let source;
    try {
        source = readFileSync(file, 'utf8');
    } catch (error) {
        err(`${file} could not be read: ${error.message}`);
        return 2;
    }

    // Written before the first deletion and removed after the last restoration: if it is still
    // there, the process died in between and this is the file to put back.
    const copy = `${file}.before-mutation`;
    const restore = () => {
        writeFileSync(file, source);
        rmSync(copy, { force: true });
    };
    if (existsSync(copy)) {
        err(`${copy} exists: a previous pass was killed before it could restore ${file}.`);
        err(`  → compare the two, put the right one back as ${file}, delete the other, then run this again.`);
        return 2;
    }
    writeFileSync(copy, source);
    interruption.restore = restore;

    let result;
    try {
        result = await mutate({
            source,
            write: (content) => writeFileSync(file, content),
            passes: passes ?? runner(command, { cwd }),
            progress: (noticed) => out(noticed ? '.' : 'S'),
        });
    } finally {
        restore();
        interruption.restore = null;
    }

    if (result.problem !== null) {
        err(result.problem);
        return 2;
    }

    out(
        `\n${file}: ${result.tried} line(s) deleted one at a time, ${result.unnoticed.length} deletion(s) no test noticed.`,
    );
    result.unnoticed.forEach(({ number, text }) => out(`  ${file}:${number}: ${text}`));
    if (result.unnoticed.length > 0) {
        out('Each one is a line to test, a line to delete, or a line whose absence changes nothing — say which.');
        return 1;
    }
    return 0;
}

// Run as a child process by the test suite, which is the only way to prove that the exit code
// leaves the script and that an interruption puts the file back: coverage, collected in the test
// process, cannot see this block.
/* v8 ignore next 12 */
if (process.argv[1] === fileURLToPath(import.meta.url)) {
    const interruption = { restore: null };
    ['SIGINT', 'SIGTERM'].forEach((signal) =>
        process.on(signal, () => {
            interruption.restore?.();
            process.exit(130);
        }),
    );
    const write = (line) => process.stdout.write(line === '.' || line === 'S' ? line : `${line}\n`);
    process.exitCode = await cli({
        argv: process.argv.slice(2),
        out: write,
        err: console.error,
        interruption,
    });
}
