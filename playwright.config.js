import { defineConfig, devices } from '@playwright/test';
import { e2eEnv } from './tests/e2e/helpers/e2e-env.js';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: 'html',

    globalSetup: './tests/e2e/global-setup.js',

    // Dedicated E2E server on port 8001 pointing at cardascia_it_e2e database.
    // Prerequisite (once): createdb -h 127.0.0.1 -p 5433 -U sefalhik cardascia_it_e2e
    webServer: {
        command: 'php artisan serve --port=8001 --no-interaction',
        url: 'http://localhost:8001/up',
        env: e2eEnv,
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
    },

    use: {
        baseURL: 'http://localhost:8001',
        trace: 'on-first-retry',
    },

    projects: [
        // Authenticates once and saves session state to tests/e2e/.auth/admin.json
        {
            name: 'setup',
            testMatch: /auth\.setup\.js/,
        },
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
            dependencies: ['setup'],
        },
        // Uncomment to test additional browsers:
        // { name: 'firefox', use: { ...devices['Desktop Firefox'] }, dependencies: ['setup'] },
        // { name: 'webkit',  use: { ...devices['Desktop Safari']  }, dependencies: ['setup'] },
    ],
});
