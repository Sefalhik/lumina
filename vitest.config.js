import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'happy-dom',
        globals: true,
        include: ['resources/js/**/*.test.js', 'scripts/**/*.test.js'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'html'],
            // Scope: pure utility modules only.
            // app.js is an entry point (Vue island mounting), not unit-testable.
            // Vue components are covered by Playwright E2E — component unit tests
            // are a separate category requiring Vue Test Utils.
            //
            // Two tooling scripts are in scope, and held to 100%. scripts/audit-advisories.js is a
            // watch, and a line of it nobody exercises is a way for it to go quiet.
            // scripts/mutate-lines.js is what says whether the other tests can be trusted: it has
            // to be held to what it asks of them.
            // scripts/e2e-preflight.js is not measured yet — 88% of lines, 70% of branches.
            //
            // tests/e2e/helpers/workers.js maps each Playwright worker to its server and database
            // (LUMN-111): a wrong line sends a test to another worker's data. Held to 100% too.
            //
            // scripts/palette-contrast.js is what checks a theme's contrast promises (LUMN-103), where
            // the axe scan cannot see: held to 100% as well.
            include: [
                'resources/js/utils/**/*.js',
                'scripts/audit-advisories.js',
                'scripts/mutate-lines.js',
                'tests/e2e/helpers/workers.js',
                'scripts/palette-contrast.js',
            ],
            thresholds: {
                statements: 80,
                branches: 80,
                functions: 80,
                lines: 80,
                'scripts/audit-advisories.js': {
                    statements: 100,
                    branches: 100,
                    functions: 100,
                    lines: 100,
                },
                'scripts/mutate-lines.js': {
                    statements: 100,
                    branches: 100,
                    functions: 100,
                    lines: 100,
                },
                'tests/e2e/helpers/workers.js': {
                    statements: 100,
                    branches: 100,
                    functions: 100,
                    lines: 100,
                },
                'scripts/palette-contrast.js': {
                    statements: 100,
                    branches: 100,
                    functions: 100,
                    lines: 100,
                },
            },
        },
    },
});
