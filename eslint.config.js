import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import vueA11y from 'eslint-plugin-vuejs-accessibility';
import prettier from 'eslint-config-prettier';
import globals from 'globals';

export default [
    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    // Its base config sets the browser's globals on every file, not only on components: Node's
    // tooling in scripts/ was linted with window and document defined (found on LUMN-62). Scoped to
    // the components it is about.
    ...vueA11y.configs['flat/recommended'].map((config) => ({ ...config, files: config.files ?? ['**/*.vue'] })),
    prettier,
    {
        files: ['resources/js/**/*.{js,vue}'],
        languageOptions: {
            globals: {
                ...globals.browser,
                route: 'readonly', // Ziggy — injected via @routes Blade directive
            },
        },
        rules: {
            // Vue 3 — Composition API only
            'vue/component-api-style': ['error', ['script-setup']],

            // Prefer const / let over var
            'no-var': 'error',
            'prefer-const': 'error',
        },
    },
    {
        // Dev tooling run by Node, not by the browser — scripts/check.sh and its helpers.
        files: ['scripts/**/*.js'],
        languageOptions: {
            globals: globals.node,
        },
        rules: {
            'no-var': 'error',
            'prefer-const': 'error',
        },
    },
    {
        // Complexity (LUMN-62), measured on 2026-11-14 before any threshold was chosen: 20 is the
        // most complex function of the day, in the code (npmAdvisories() in
        // scripts/audit-advisories.js, a parser of npm's report) as in the tests
        // (update-frankenphp.test.js). A ratchet, not a figure from an article. Logic in a test is
        // a smell too, so the tests are held to it. Measures and reasoning: docs/quality-tooling.md.
        files: ['resources/js/**/*.{js,vue}', 'scripts/**/*.js'],
        rules: {
            complexity: ['error', 20],
        },
    },
    {
        // Length (LUMN-62), measured the same day, comments and blank lines aside — this code
        // documents itself at length, and a count of comments would push to delete them. Not in the
        // tests: a test file grows with the cases of what it tests, and a describe() is a long
        // function by nature.
        files: ['resources/js/**/*.{js,vue}', 'scripts/**/*.js'],
        ignores: ['**/__tests__/**'],
        rules: {
            // 244 for the longest file of logic: scripts/audit-advisories.js.
            'max-lines': ['error', { max: 250, skipBlankLines: true, skipComments: true }],
            // 104: createDeadChannel(), a controller written as one closure, by design.
            'max-lines-per-function': ['error', { max: 105, skipBlankLines: true, skipComments: true }],
        },
    },
    {
        // Data, not logic: the table of the boot sequence's origins, one verified block per line
        // (LUMN-122). Its length is pinned by its own test — 334 lines — so that it changes on
        // purpose, with a new RIPEstat check, never by drift.
        files: ['resources/js/utils/boot-origin.js'],
        rules: {
            'max-lines': 'off',
        },
    },
    {
        ignores: ['vendor/**', 'node_modules/**', 'public/**', 'storage/**', 'bootstrap/cache/**'],
    },
];
