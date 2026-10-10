// Shared by the tests that run a real shell script: a PATH that contains nothing but what the test
// put there, and stand-ins that keep a journal of how they were called.
//
// A script under test can then only reach the real tools it was given and the stand-ins: nothing
// leaves the machine, nothing is installed, and a tool the script starts calling without it being
// listed fails the suite instead of quietly running the developer's own.
import {
    accessSync,
    chmodSync,
    constants,
    existsSync,
    mkdirSync,
    readFileSync,
    symlinkSync,
    writeFileSync,
} from 'node:fs';
import { delimiter, join } from 'node:path';

export function isExecutable(path) {
    try {
        accessSync(path, constants.X_OK);
        return true;
    } catch {
        return false;
    }
}

/**
 * The absolute path of a real tool, looked up on the PATH of the test process.
 *
 * @param {string} tool
 * @returns {string}
 */
export function locate(tool) {
    const found = process.env.PATH.split(delimiter)
        .map((directory) => join(directory, tool))
        .find(isExecutable);
    if (!found) {
        throw new Error(`${tool} is needed by a script under test and was not found on PATH.`);
    }
    return found;
}

export const BASH = locate('bash');

// First lines of every stand-in: its name and arguments, appended to the journal named by
// STUB_LOG, one call per line.
export const JOURNAL = `#!${BASH}
{ printf '%s' "\${0##*/}"; printf '\\x1f%s' "$@"; printf '\\n'; } >> "$STUB_LOG"`;

/**
 * Fills a directory meant to be the whole PATH of a script under test.
 *
 * @param {string} directory
 * @param {{real?: string[], standins?: Record<string, string>}} tools  real tools are linked, stand-ins are written
 */
export function fillPath(directory, { real = [], standins = {} }) {
    mkdirSync(directory, { recursive: true });
    real.forEach((tool) => symlinkSync(locate(tool), join(directory, tool)));
    Object.entries(standins).forEach(([tool, source]) => {
        writeFileSync(join(directory, tool), source);
        chmodSync(join(directory, tool), 0o755);
    });
}

/**
 * The calls the stand-ins recorded, in order, each as [tool, ...arguments].
 *
 * @param {string} journal
 * @returns {string[][]}
 */
export function callsIn(journal) {
    if (!existsSync(journal)) {
        return [];
    }
    return readFileSync(journal, 'utf8')
        .split('\n')
        .filter((line) => line !== '')
        .map((line) => line.split('\x1f'));
}
