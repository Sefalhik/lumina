import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: 'html',

    use: {
        baseURL: 'https://dev.cardascia-it.org',
        ignoreHTTPSErrors: true, // mkcert self-signed cert
        trace: 'on-first-retry',
    },

    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        // Uncomment to test additional browsers:
        // { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
        // { name: 'webkit',  use: { ...devices['Desktop Safari']  } },
    ],

    // Dev server must be running before E2E tests: npm start
});
