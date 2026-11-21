// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import stylelint from 'stylelint';
import * as prettier from 'prettier';
import config from '../../stylelint.config.mjs';

/**
 * What stylelint.config.mjs claims, held on fixed SCSS (LUMN-152): each quality rule refuses its
 * defect; the formatting rules are on, except the one that contradicts Prettier, and that contradiction
 * is shown, not asserted; and SCSS formatted by Prettier passes the whole configuration.
 *
 * Hand probes proved it once. Without this, a rule set back to null, or a later
 * stylelint-config-standard-scss that changes what it enables, would pass unnoticed.
 */

const repository = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const file = resolve(repository, 'resources/css/scss/probe.scss');

/**
 * The rules a piece of SCSS breaks under a configuration — the project's by default.
 *
 * @param {string} code
 * @param {object} [withConfig]
 * @returns {Promise<string[]>}
 */
async function broken(code, withConfig = config) {
    const { results } = await stylelint.lint({
        code,
        config: withConfig,
        configBasedir: repository,
        codeFilename: file,
    });
    return [...new Set(results[0].warnings.map((warning) => warning.rule))];
}

/** @param {string} code */
async function formatted(code) {
    return prettier.format(code, { parser: 'scss', ...(await prettier.resolveConfig(file)) });
}

describe('each quality rule refuses its defect', () => {
    it.each([
        ['an invalid colour', 'color-no-invalid-hex', '.a {\n    color: #ff;\n}\n'],
        [
            'a descending specificity',
            'no-descending-specificity',
            '.a .b {\n    color: red;\n}\n\n.b {\n    color: blue;\n}\n',
        ],
        ['a global Sass function', 'scss/no-global-function-names', '.a {\n    width: map-get($m, k);\n}\n'],
        ['an unknown at-rule', 'scss/at-rule-no-unknown', '@includ frame;\n'],
        [
            'properties out of order',
            'order/properties-alphabetical-order',
            '.a {\n    z-index: 1;\n    color: red;\n}\n',
        ],
    ])('%s', async (_case, rule, code) => {
        expect(await broken(code)).toContain(rule);
    });
});

describe('the at-rules of Sass modules and Tailwind 4 are known', () => {
    // The ones the repository uses (resources/css), and the Tailwind 4 at-rules the configuration names.
    it.each([
        ["@use 'sass:map';\n"],
        ["@forward 'tokens';\n"],
        ['@plugin "daisyui";\n'],
        ['@source "../views";\n'],
        ['@tailwind utilities;\n'],
        ['@layer components {\n    .a {\n        color: red;\n    }\n}\n'],
        ['.a {\n    @apply text-sm;\n}\n'],
    ])('%s', async (code) => {
        expect(await broken(code)).not.toContain('scss/at-rule-no-unknown');
    });
});

describe('the formatting rules are on', () => {
    it.each([
        // What Prettier leaves as written: these check what nothing else does.
        ['at-rule-empty-line-before', '.a {\n    color: red;\n}\n@include frame;\n'],
        [
            'scss/at-else-if-parentheses-space-before',
            '@if $a {\n    .b {\n        color: red;\n    }\n} @else if($b) {\n    .b {\n        color: blue;\n    }\n}\n',
        ],
        ['scss/operator-no-unspaced', '$a: 1+2;\n'],
        // What Prettier writes anyway: they guard what reaches the repository without the hook.
        [
            'scss/at-else-closing-brace-newline-after',
            '@if $a { .b { color: red; } } @else { .b { color: blue; } } .x { color: red; }\n',
        ],
        [
            'scss/at-else-closing-brace-space-after',
            '@if $a { .b { color: red; } } @else if $b { .b { color: red; } }@else { .b { color: blue; } }\n',
        ],
        [
            'scss/at-else-empty-line-before',
            '@if $a {\n    .b {\n        color: red;\n    }\n}\n\n@else {\n    .b {\n        color: blue;\n    }\n}\n',
        ],
        ['scss/at-function-parentheses-space-before', '@function f ($x) {\n    @return $x;\n}\n'],
        ['scss/at-if-closing-brace-newline-after', '@if $a { .b { color: red; } } .x { color: red; }\n'],
        ['scss/at-if-closing-brace-space-after', '@if $a { .b { color: red; } }@else { .b { color: blue; } }\n'],
        ['scss/at-mixin-parentheses-space-before', '@mixin m ($x) {\n    width: $x;\n}\n'],
        ['scss/dollar-variable-colon-space-after', '$a:1;\n'],
        ['scss/dollar-variable-colon-space-before', '$a : 1;\n'],
        ['scss/operator-no-newline-before', '$a: 1\n    + 2;\n'],
    ])('%s', async (rule, code) => {
        expect(await broken(code)).toContain(rule);
    });
});

describe('the one formatting rule that is off', () => {
    const long =
        '$total: $a-very-long-variable-name-number-one + $a-very-long-variable-name-number-two + $a-very-long-variable-name-number-three;\n';

    it('contradicts Prettier: it refuses how Prettier wraps a long expression', async () => {
        const base = await stylelint.resolveConfig(file, {
            config: { extends: ['stylelint-config-standard-scss'] },
            configBasedir: repository,
        });
        const onlyThatRule = {
            extends: ['stylelint-config-standard-scss'],
            rules: {
                ...Object.fromEntries(Object.keys(base.rules).map((rule) => [rule, null])),
                'scss/operator-no-newline-after': base.rules['scss/operator-no-newline-after'],
            },
        };
        const wrapped = await formatted(long);

        // The day Prettier wraps differently, this fails, and the rule has no reason to be off.
        expect(wrapped).toMatch(/\+\n/);
        expect(await broken(wrapped, onlyThatRule)).toEqual(['scss/operator-no-newline-after']);
    });

    it('is the only one: the same wrapped expression passes the project configuration', async () => {
        expect(await broken(await formatted(long))).toEqual([]);
    });
});

describe('SCSS formatted by Prettier passes the whole configuration', () => {
    it('on long expressions, at-rule chains, mixins, functions and loops', async () => {
        const scss = [
            "@use 'sass:map';",
            '',
            '$total: $a-very-long-variable-name-number-one + $a-very-long-variable-name-number-two + $a-very-long-variable-name-number-three;',
            '',
            '@function double($x) {',
            '    @return $x * 2;',
            '}',
            '',
            '@mixin frame($width, $colour: red) {',
            '    border: $width solid $colour;',
            '}',
            '',
            '.card {',
            '    @include frame(1px);',
            '',
            '    width: calc($a-very-long-variable-name-number-one + $a-very-long-variable-name-number-two + $a-very-long-variable-name-number-three);',
            '',
            '    @if $theme == dark {',
            '        color: white;',
            '    } @else if $theme == light {',
            '        color: black;',
            '    } @else {',
            '        color: grey;',
            '    }',
            '',
            '    @each $name, $value in $map {',
            '        .card--#{$name} {',
            '            margin: map.get($spacing, $value) * -1;',
            '        }',
            '    }',
            '}',
            '',
        ].join('\n');

        expect(await broken(await formatted(scss))).toEqual([]);
    });

    it('keeps the blank line between the palette and the typography of a theme', async () => {
        const theme =
            "[data-theme='sprawl'] {\n    color-scheme: dark;\n    --color-base-100: #000;\n\n    --font-display: serif;\n}\n";

        expect(await broken(await formatted(theme))).toEqual([]);
    });
});
