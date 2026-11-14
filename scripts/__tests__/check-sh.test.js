// @vitest-environment node
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// The preflight is only worth something if check.sh actually runs it, before the suite, and stops
// when it refuses. Every test in e2e-preflight.test.js stays green with that wiring deleted.
// Reads the file rather than running it: the full audit takes over a minute and needs a database.
const checkSh = readFileSync(fileURLToPath(new URL('../check.sh', import.meta.url)), 'utf8');

function playwrightStep() {
    const lines = checkSh.split('\n').filter((line) => /^\s*step\s+"Playwright E2E"/.test(line));
    expect(lines, 'check.sh must declare exactly one Playwright step').toHaveLength(1);
    return lines[0];
}

describe('scripts/check.sh — Playwright step', () => {
    it('runs the preflight', () => {
        expect(playwrightStep()).toContain('node scripts/e2e-preflight.js');
    });

    it('runs it before the suite, and only runs the suite if it passed', () => {
        // `&&`, not `;`: with a semicolon the suite would run anyway after a refusal, and its
        // verdict would be the one the step reports.
        expect(playwrightStep()).toMatch(/node scripts\/e2e-preflight\.js\s*&&\s*npm run --silent test:e2e/);
    });

    it('is the only place the E2E suite is started', () => {
        // A second, unguarded invocation elsewhere in the script would bypass the preflight.
        const invocations = checkSh.split('\n').filter((line) => !/^\s*#/.test(line) && line.includes('test:e2e'));
        expect(invocations).toHaveLength(1);
    });
});

// Pint (LUMN-62): installed and run by nobody until then. Its three entry points are read, not run:
// each one is what a dropped line would silently undo.
const ciYml = readFileSync(fileURLToPath(new URL('../../.github/workflows/ci.yml', import.meta.url)), 'utf8');
const lintStaged = JSON.parse(
    readFileSync(fileURLToPath(new URL('../../.lintstagedrc.json', import.meta.url)), 'utf8'),
);

describe('Pint, the formatting of PHP', () => {
    it('is a step of the audit, which checks and never fixes', () => {
        const lines = checkSh.split('\n').filter((line) => /^\s*step\s+"Pint"/.test(line));

        expect(lines).toHaveLength(1);
        expect(lines[0]).toMatch(/\.\/vendor\/bin\/pint --test\s*$/);
    });

    it('runs in the audit before PHPStan', () => {
        expect(checkSh.indexOf('step "Pint"')).toBeLessThan(checkSh.indexOf('step "PHPStan (app)"'));
    });

    it('is a step of the CI, which checks and never fixes, before PHPStan', () => {
        const pint = ciYml.indexOf('run: ./vendor/bin/pint --test');

        expect(pint).toBeGreaterThan(-1);
        expect(pint).toBeLessThan(ciYml.indexOf('run: ./vendor/bin/phpstan analyse\n'));
    });

    it('formats the staged PHP files in the hook, as Prettier does the JavaScript', () => {
        expect(lintStaged['**/*.php']).toEqual(['vendor/bin/pint']);
    });
});
