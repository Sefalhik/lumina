// @vitest-environment node
import { describe, it, expect } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { ACCEPTED_FILE } from '../audit-advisories.js';

// The seams between three files that no unit test of the script can see: the workflow names the
// script it runs and the files that trigger it, by path. Rename one side only and nothing fails —
// the workflow just stops running, or stops being triggered. See LUMN-73.

const inRepository = (path) => fileURLToPath(new URL(`../../${path}`, import.meta.url));
const read = (path) => readFileSync(inRepository(path), 'utf8');

const WORKFLOW_PATH = '.github/workflows/security-audit.yml';
const SCRIPT_PATH = 'scripts/audit-advisories.js';
const WORKFLOW = read(WORKFLOW_PATH);

// The entries of the `paths:` list under `pull_request:`, as written.
const triggerPaths = () => {
    const block = WORKFLOW.match(/^ {4}paths:\n((?: {6}- .+\n)+)/m);
    return block ? [...block[1].matchAll(/^ {6}- (.+)$/gm)].map((match) => match[1].trim()) : [];
};

const actionsOf = (workflow) => [...workflow.matchAll(/^\s*(?:- )?uses:\s*(\S+)/gm)].map((match) => match[1]);

describe('security-audit.yml', () => {
    it('runs the audit script, which exists', () => {
        expect(WORKFLOW).toMatch(new RegExp(`^\\s*run: node ${SCRIPT_PATH}$`, 'm'));
        expect(existsSync(inRepository(SCRIPT_PATH))).toBe(true);
    });

    it('is triggered by a pull request on everything the audit reads and on how it runs', () => {
        expect(triggerPaths().sort()).toEqual(
            [WORKFLOW_PATH, ACCEPTED_FILE, 'composer.lock', 'package-lock.json', SCRIPT_PATH].sort(),
        );
    });

    it('only names trigger paths that exist', () => {
        const paths = triggerPaths();

        expect(paths).not.toHaveLength(0);
        expect(paths.filter((path) => !existsSync(inRepository(path)))).toEqual([]);
    });

    it('runs every day and can be started by hand', () => {
        expect(WORKFLOW).toMatch(/^ {2}schedule:\n {4}- cron: '\d+ \d+ \* \* \*'$/m);
        expect(WORKFLOW).toMatch(/^ {2}workflow_dispatch:$/m);
    });

    it('fails when the script fails: nothing lets an error through', () => {
        expect(WORKFLOW).not.toMatch(/continue-on-error/);
        expect(WORKFLOW).not.toMatch(/\|\|\s*true/);
    });

    it('is bounded in time and asks for no more than read access', () => {
        expect(WORKFLOW).toMatch(/^ {4}timeout-minutes: \d+$/m);
        expect(WORKFLOW).toMatch(/^permissions:\n {2}contents: read\n\n/m);
    });

    // renovate.json5 merges action updates without approval "only while every action runs on pull
    // requests". An action used here alone, at a version ci.yml does not run, would break that.
    it('uses only actions that ci.yml runs on every pull request, at the same commit', () => {
        const here = actionsOf(WORKFLOW);
        const exercised = actionsOf(read('.github/workflows/ci.yml'));

        expect(here).not.toHaveLength(0);
        expect(here.filter((action) => !exercised.includes(action))).toEqual([]);
        expect(here.filter((action) => !/@[0-9a-f]{40}$/.test(action))).toEqual([]);
    });
});
