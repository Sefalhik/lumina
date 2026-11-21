import { test, expect } from './helpers/test.js';
import { e2eMode } from './helpers/workers.js';

/**
 * The suite tests what a visitor receives (LUMN-106): the compiled build, served by an application
 * that knows it is under test.
 *
 * Until then the servers ran in `local`, and wherever `npm start` kept a Vite dev server running they
 * served its modules instead of the build. On 2026-11-21 a module lost on the network left every Vue
 * island unmounted: two specs failed at random, for no fault of the code they test, and CI — no dev
 * server there — never saw it. The unit side is proven in scripts/__tests__/e2e-workers.test.js and
 * tests/Feature/Testing/ViteDevServerIsIgnoredInTestingTest.php; this proves it from the browser,
 * on the servers this run actually started.
 */
test.describe('what the suite tests', () => {
    test.skip(e2eMode() !== 'build', 'E2E_DEV_SERVER=1 tests the Vite dev server on purpose, and says so');

    test('the server runs as a test environment, serving the build, and Telescope records nothing', async ({
        page,
        request,
    }) => {
        // A page first: Telescope records requests, so an empty table after one proves it is off —
        // the setting alone would not. It recorded about 3,400 entries per slot in a run before LUMN-106.
        await page.goto('/fr/');

        const server = await (await request.get('/e2e/environment')).json();

        expect(server).toEqual({ environment: 'testing', telescope_entries: 0, vite_dev_server: false });
    });

    test('a page loads its scripts and styles from the build, and nothing from a dev server', async ({ page }) => {
        const assets = [];
        const failed = [];
        page.on('request', (request) => {
            if (['script', 'stylesheet'].includes(request.resourceType())) {
                assets.push(new URL(request.url()));
            }
        });
        page.on('requestfailed', (request) => failed.push(request.url()));

        await page.goto('/fr/');
        // An island mounted: the scripts that were loaded actually ran.
        await expect(page.locator('[data-motion-toggle]')).toBeVisible();

        expect(assets.length).toBeGreaterThan(0);
        const origin = new URL(page.url()).origin;
        for (const asset of assets) {
            expect(asset.origin, asset.href).toBe(origin);
            expect(asset.pathname, asset.href).toMatch(/^\/build\//);
        }
        expect(failed).toEqual([]);
    });
});
