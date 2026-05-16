import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import prettier from 'eslint-config-prettier';
import globals from 'globals';

export default [
    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
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
        ignores: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'storage/**',
            'bootstrap/cache/**',
        ],
    },
];
