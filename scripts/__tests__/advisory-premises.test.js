// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadsModule, withoutComments } from '../loads-module.js';

/**
 * The premise of two tolerated advisories (accepted-advisories.json, LUMN-152): the flaws of glob and
 * minimatch sit under js-beautify, and @vue/test-utils, the one package that depends on it, bundles its
 * code and never loads it. Proven by hand on 2026-11-21 — the package made unfindable, the component
 * tests passed. Held here, so that the @vue/test-utils update that breaks it fails, and the two reasons
 * are reviewed, instead of standing on a fact that stopped being true.
 */

const repository = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const testUtils = resolve(repository, 'node_modules/@vue/test-utils/dist');

describe('loadsModule', () => {
    it.each([
        ['require', "const b = require('js-beautify');"],
        ['require with spaces and double quotes', 'const b = require ( "js-beautify" );'],
        ['a file of the package', "const h = require('js-beautify/js/lib/beautify-html.js');"],
        ['import from', "import beautify from 'js-beautify';"],
        ['a bare import', "import 'js-beautify';"],
        ['a dynamic import', "const b = await import('js-beautify');"],
        ['a template literal', 'const b = require(`js-beautify`);'],
    ])('sees %s', (_case, source) => {
        expect(loadsModule(source, 'js-beautify')).toBe(true);
    });

    it.each([
        ['a line comment', "// var b = require('js-beautify');"],
        ['a block comment', "/**\n var beautify_js = require('js-beautify');\n**/"],
        ['another package whose name starts the same', "require('js-beautify-extra');"],
        ['the name in a plain string', "const name = 'js-beautify';"],
        ['quotes that do not match', 'require(\'js-beautify");'],
        ['quotes that do not match, in an import', 'import b from \'js-beautify";'],
        ['quotes that do not match, in a bare import', 'import \'js-beautify";'],
    ])('does not count %s', (_case, source) => {
        expect(loadsModule(source, 'js-beautify')).toBe(false);
    });

    it('reads a name as written, not as a pattern: a dot is a dot', () => {
        expect(loadsModule("require('lodashXget');", 'lodash.get')).toBe(false);
        expect(loadsModule("require('lodash.get');", 'lodash.get')).toBe(true);
    });

    it('keeps a comment marker that sits inside a string', () => {
        expect(withoutComments("const url = 'https://example.test'; require('x');")).toContain("require('x')");
    });
});

describe('the premise, on the installed @vue/test-utils', () => {
    const files = readdirSync(testUtils).filter((name) => /\.(c|m)?js$/.test(name));

    it('reads every built file of the package', () => {
        expect(files).toEqual(expect.arrayContaining(['vue-test-utils.cjs.js', 'vue-test-utils.esm-bundler.mjs']));
    });

    it.each(files)('%s never loads js-beautify', (name) => {
        expect(loadsModule(readFileSync(resolve(testUtils, name), 'utf8'), 'js-beautify')).toBe(false);
    });

    it('does bundle its code, so the check reads the right files', () => {
        expect(readFileSync(resolve(testUtils, 'vue-test-utils.cjs.js'), 'utf8')).toContain('html_beautify');
    });
});
