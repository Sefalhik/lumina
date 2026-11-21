// @vitest-environment node
import { afterEach, describe, expect, it } from 'vitest';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * A test that checks nothing has to fail the suite, not pass it — Vitest's side of what `failOnRisky`
 * does for PHPUnit (LUMN-149).
 *
 * Vitest passes a test that makes no assertion. Delete the one `expect` of a test and it stays green:
 * the mechanical pass of LUMN-55 met it on the assertions of a new test. `vitest.config.js` now sets
 * `expect.requireAssertions`, and this fails the day it is removed — which nothing else would notice,
 * since a suite that stops failing on empty tests stays green.
 *
 * The effect is asserted, not the setting: a nested Vitest runs, under this project's own
 * configuration, a test that checks nothing, and has to fail on it. A twin that checks something has to
 * pass, so the failure cannot come from a run that breaks for another reason.
 */

const repository = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

let directory = null;

afterEach(() => {
    if (directory !== null) {
        rmSync(directory, { recursive: true, force: true });
        directory = null;
    }
});

/** @param {string} body */
function runAlone(body) {
    directory = mkdtempSync(join(tmpdir(), 'require-assertions-'));
    writeFileSync(join(directory, 'alone.test.js'), `it('runs', () => { ${body} });\n`);
    // The project's own configuration, pointed at this one file.
    writeFileSync(
        join(directory, 'vitest.config.mjs'),
        `import config from ${JSON.stringify(join(repository, 'vitest.config.js'))};\n` +
            `export default { ...config, test: { ...config.test, include: ['alone.test.js'], coverage: { enabled: false } } };\n`,
    );
    return spawnSync('npx', ['vitest', 'run', '--config', join(directory, 'vitest.config.mjs'), '--root', directory], {
        cwd: repository,
        encoding: 'utf8',
    });
}

describe('under this project’s configuration', () => {
    it('a test that checks nothing fails the suite', () => {
        const run = runAlone('/* nothing is checked */');

        expect(run.status).toBe(1);
        expect(run.stdout + run.stderr).toContain('expected any number of assertion, but got none');
    }, 60_000);

    it('a test that checks something passes', () => {
        const run = runAlone('expect(1).toBe(1);');

        expect(run.status, run.stdout + run.stderr).toBe(0);
    }, 60_000);
});
