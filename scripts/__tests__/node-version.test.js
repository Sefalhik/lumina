// @vitest-environment node
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// The Node version is written once, in .nvmrc (LUMN-97). Every setup-node step reads it with
// node-version-file, nvm reads it on the developer machine, Renovate proposes its next LTS, and
// the documentation sends the alwaysdata panel to it. A version written anywhere else is a second
// copy, and the next LTS would move one and leave the other behind.
const inRepository = (path) => fileURLToPath(new URL(`../../${path}`, import.meta.url));
const read = (path) => readFileSync(inRepository(path), 'utf8');

const WORKFLOWS = readdirSync(inRepository('.github/workflows'))
    .filter((name) => /\.ya?ml$/.test(name))
    .map((name) => [name, read(`.github/workflows/${name}`)]);

describe('.nvmrc', () => {
    it('holds a bare major, so that patches and security fixes of that line flow', () => {
        // "26.13.0" would freeze CI on one patch; "lts/*" would move the major without a decision.
        expect(read('.nvmrc')).toMatch(/^[123456789][0123456789]*\n$/);
    });
});

describe('the workflows', () => {
    it('set Node up somewhere, or the checks below prove nothing', () => {
        const steps = WORKFLOWS.flatMap(([, text]) => text.match(/uses:\s*actions\/setup-node@/g) ?? []);

        expect(steps.length).toBeGreaterThan(0);
    });

    it.each(WORKFLOWS)('%s reads the Node version from .nvmrc in every setup-node step', (_, text) => {
        const steps = (text.match(/uses:\s*actions\/setup-node@/g) ?? []).length;
        const fromFile = (text.match(/^\s*node-version-file:\s*\.nvmrc\s*$/gm) ?? []).length;

        expect(fromFile).toBe(steps);
    });

    it.each(WORKFLOWS)('%s writes no Node version of its own', (_, text) => {
        expect(text).not.toMatch(/^\s*node-version:/m);
    });
});

describe('the documentation', () => {
    it('sends the alwaysdata panel to .nvmrc instead of naming a version', () => {
        // The one copy that cannot read the file: a person sets it by hand, from this row.
        const row = read('docs/deployment.md')
            .split('\n')
            .find((line) => line.startsWith('| Node.js |'));

        expect(row).toBeDefined();
        // The value cell: no number at all, bold or not — "26 (see .nvmrc)" is a copy too. The
        // floor of package.json, ">=24", lives in the next cell and is not the version run.
        const value = row.split('|')[2];
        expect(value).toContain('`.nvmrc`');
        expect(value).not.toMatch(/[0123456789]/);
    });
});
