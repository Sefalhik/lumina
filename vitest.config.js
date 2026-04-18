import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'happy-dom',
        globals: true,
        include: ['resources/js/**/*.test.js'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'html'],
            // Scope: pure utility modules only.
            // app.js is an entry point (Vue island mounting), not unit-testable.
            // Vue components are covered by Playwright E2E — component unit tests
            // are a separate category requiring Vue Test Utils.
            include: ['resources/js/utils/**/*.js'],
            thresholds: {
                statements: 80,
                branches: 80,
                functions: 80,
                lines: 80,
            },
        },
    },
});
