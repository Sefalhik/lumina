import { defineConfig, devices } from '@playwright/test';
import { E2E_WORKERS, portFor, serverEnv, slots, urlFor } from './tests/e2e/helpers/workers.js';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: 'html',

    // One worker per server, never more: a worker without a server of its own would have no
    // environment to talk to (LUMN-111, tests/e2e/helpers/workers.js).
    workers: E2E_WORKERS,

    globalSetup: './tests/e2e/global-setup.js',

    // One server per worker slot, each on its own port and its own database.
    // Readiness is read on a static file, not /up: Playwright starts these servers before
    // globalSetup has created their databases, and /up reads the database.
    webServer: slots().map((slot) => ({
        command: `php artisan serve --port=${portFor(slot)} --no-interaction`,
        url: `${urlFor(slot)}/favicons/favicon-sprawl.svg`,
        env: serverEnv(slot),
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
    })),

    // No baseURL here: tests/e2e/helpers/test.js gives each test its slot's, and auth.setup.js
    // names the server of each slot it prepares.
    use: {
        trace: 'on-first-retry',
    },

    projects: [
        // Creates the admin sessions, on every slot's server — tests/e2e/auth.setup.js
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
