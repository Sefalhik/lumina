// @vitest-environment node
import { ESLint } from 'eslint';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * eslint.config.js, held where it is decided (LUMN-62): the project's own configuration lints code
 * built to break one rule, or to sit exactly on one limit and then one past it. A rule loosened,
 * removed, or scoped away from the code it guards turns these red.
 *
 * The presets are not tested here — their rules are their maintainers' work. What is tested is that
 * each one is plugged in, through one rule it brings.
 */

const ROOT = fileURLToPath(new URL('../..', import.meta.url));
const eslint = new ESLint({ cwd: ROOT });

/** The rules a piece of code breaks, linted as if it lived at `path`. */
async function broken(code, path) {
    const [result] = await eslint.lintText(code, { filePath: `${ROOT}${path}` });
    expect(result.messages.filter((message) => message.fatal)).toEqual([]);
    return result.messages.map((message) => message.ruleId);
}

/** A function whose cyclomatic complexity is exactly `n`: one path, plus one per `if`. */
function complexFunction(n) {
    const branches = Array.from({ length: n - 1 }, (_, i) => `    if (value === ${i}) return ${i};`);
    return `export function tangled(value) {\n${branches.join('\n')}\n    return -1;\n}\n`;
}

/** A file of `n` lines of code, none blank, none a comment. */
function longFile(n) {
    return Array.from({ length: n }, (_, i) => `export const line${i} = ${i};`).join('\n') + '\n';
}

/** A function of exactly `n` lines of code, its signature and closing brace included. */
function longFunction(n) {
    const body = Array.from({ length: n - 2 }, (_, i) => `    const step${i} = ${i};`);
    return `export function long() {\n${body.join('\n')}\n}\n`;
}

const CODE = 'resources/js/utils/thresholds-probe.js';
const SCRIPT = 'scripts/thresholds-probe.js';
const TEST = 'resources/js/utils/__tests__/thresholds-probe.test.js';
const SCRIPT_TEST = 'scripts/__tests__/thresholds-probe.test.js';
// Not linted by `npm run lint:js` — formatting and linting the end-to-end specs is LUMN-109.
const OUTSIDE = 'tests/e2e/thresholds-probe.spec.js';

describe('the complexity of a function', () => {
    it.each([CODE, SCRIPT, TEST, SCRIPT_TEST])('is allowed up to 20 in %s', async (path) => {
        expect(await broken(complexFunction(20), path)).not.toContain('complexity');
    });

    it.each([CODE, SCRIPT, TEST, SCRIPT_TEST])(
        'is refused at 21 in %s — logic in a test is a smell too',
        async (path) => {
            expect(await broken(complexFunction(21), path)).toContain('complexity');
        },
    );
});

describe('the length of a file', () => {
    it.each([CODE, SCRIPT])('is allowed up to 250 lines of code in %s', async (path) => {
        expect(await broken(longFile(250), path)).not.toContain('max-lines');
    });

    it.each([CODE, SCRIPT])('is refused at 251 lines of code in %s', async (path) => {
        expect(await broken(longFile(251), path)).toContain('max-lines');
    });

    it('does not count comments and blank lines: this code documents itself at length', async () => {
        const documented = longFile(250).replace(/\n/g, '\n\n// a line of explanation\n');

        expect(await broken(documented, CODE)).not.toContain('max-lines');
    });

    it.each([TEST, SCRIPT_TEST])('is not limited in a test file: %s', async (path) => {
        expect(await broken(longFile(1000), path)).not.toContain('max-lines');
    });

    it('is not limited for the table of origins, data whose size its own test pins', async () => {
        expect(await broken(longFile(1000), 'resources/js/utils/boot-origin.js')).not.toContain('max-lines');
    });
});

describe('the length of a function', () => {
    it.each([CODE, SCRIPT])('is allowed up to 105 lines of code in %s', async (path) => {
        expect(await broken(longFunction(105), path)).not.toContain('max-lines-per-function');
    });

    it.each([CODE, SCRIPT])('is refused at 106 lines of code in %s', async (path) => {
        expect(await broken(longFunction(106), path)).toContain('max-lines-per-function');
    });

    it.each([TEST, SCRIPT_TEST])(
        'is not limited in a test file, where a describe() is long by nature: %s',
        async (path) => {
            expect(await broken(longFunction(300), path)).not.toContain('max-lines-per-function');
        },
    );
});

describe('outside the code `npm run lint:js` covers', () => {
    it('no threshold applies: an end-to-end spec is not held to them yet', async () => {
        const rules = [
            ...(await broken(complexFunction(30), OUTSIDE)),
            ...(await broken(longFile(400), OUTSIDE)),
            ...(await broken(longFunction(200), OUTSIDE)),
        ];

        expect(rules).not.toContain('complexity');
        expect(rules).not.toContain('max-lines');
        expect(rules).not.toContain('max-lines-per-function');
    });
});

/** A Vue single-file component around a script and a template. */
const sfc = ({ script = '', template = '<div />' } = {}) =>
    `<script setup>\n${script}\n</script>\n\n<template>\n    ${template}\n</template>\n`;

const VUE = 'resources/js/components/ConfigProbe.vue';

describe('the presets, each plugged in', () => {
    it('eslint:recommended', async () => {
        expect(await broken('const unused = 1;\n', CODE)).toContain('no-unused-vars');
    });

    it("Vue's recommended rules", async () => {
        const template = '<ul><li v-for="item in [1, 2]">{{ item }}</li></ul>';

        expect(await broken(sfc({ template }), VUE)).toContain('vue/require-v-for-key');
    });

    it('the accessibility rules on templates', async () => {
        expect(await broken(sfc({ template: '<img src="/x.png" />' }), VUE)).toContain('vuejs-accessibility/alt-text');
    });

    it("Prettier's, which turns off the rules that would fight the formatter", async () => {
        const template = '<div id="a" class="b" title="c" role="note">text</div>';
        const rules = await broken(sfc({ template }), VUE);

        expect(rules).not.toContain('vue/max-attributes-per-line');
        expect(rules).not.toContain('vue/html-indent');
    });
});

describe('the rules of the project', () => {
    it('refuses the Options API in a component: <script setup> only', async () => {
        const options =
            '<script>\nexport default { data() { return { a: 1 }; } };\n</script>\n\n<template>\n    <div />\n</template>\n';

        expect(await broken(options, VUE)).toContain('vue/component-api-style');
    });

    it.each([CODE, SCRIPT])('refuses var, and a let never reassigned, in %s', async (path) => {
        const rules = await broken('var old = 1;\nlet once = 2;\nexport { old, once };\n', path);

        expect(rules).toContain('no-var');
        expect(rules).toContain('prefer-const');
    });
});

describe('the globals of each side', () => {
    it("knows the browser's globals in the front end, and not Node's", async () => {
        const rules = await broken(
            'export const here = window.location.href;\nexport const env = process.env;\n',
            CODE,
        );

        expect(rules.filter((rule) => rule === 'no-undef')).toHaveLength(1);
    });

    it("knows Node's globals in the tooling, and not the browser's", async () => {
        const rules = await broken(
            'export const env = process.env;\nexport const here = window.location.href;\n',
            SCRIPT,
        );

        expect(rules.filter((rule) => rule === 'no-undef')).toHaveLength(1);
    });

    it("knows Ziggy's route(), and refuses to overwrite it", async () => {
        expect(await broken("export const home = route('home');\n", CODE)).not.toContain('no-undef');
        expect(await broken('route = null;\n', CODE)).toContain('no-global-assign');
    });
});

describe('what is never linted', () => {
    it.each(['vendor', 'node_modules', 'public', 'storage', 'bootstrap/cache'])('%s/', async (directory) => {
        const [result] = await eslint.lintText('var x = 1\n', { filePath: `${ROOT}${directory}/probe.js` });

        expect(result.messages.map((message) => message.message)).toEqual([expect.stringMatching(/ignored/i)]);
    });
});
