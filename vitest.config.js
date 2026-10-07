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
            // scripts/audit-advisories.js is the one tooling script in scope, and held to 100%:
            // it is a watch, and a line of it nobody exercises is a way for it to go quiet.
            // scripts/e2e-preflight.js is not measured yet — 88% of lines, 70% of branches.
            include: ['resources/js/utils/**/*.js', 'scripts/audit-advisories.js'],
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
            },
        },
    },
});
