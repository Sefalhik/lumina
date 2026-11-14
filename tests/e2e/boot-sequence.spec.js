import { existsSync, readFileSync } from 'node:fs';
import { test, expect } from './helpers/test.js';

test.describe('Boot sequence', () => {
    // Each test gets a fresh browser context (fresh sessionStorage) by default.

    test('overlay is visible on first visit', async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('.boot-overlay')).toBeVisible();
    });

    test('overlay dismisses on click', async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('.boot-overlay')).toBeVisible();

        await page.locator('.boot-overlay').click();
        await expect(page.locator('.boot-overlay')).not.toBeVisible({ timeout: 3000 });
    });

    test('overlay dismisses on any keypress', async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('.boot-overlay')).toBeVisible();

        // Click the body (not the overlay) to guarantee page focus without triggering dismiss,
        // then fire the key event. Timeout accounts for the 700ms JS timer + 700ms CSS transition.
        await page.locator('body').focus();
        await page.keyboard.press('Escape');
        await expect(page.locator('.boot-overlay')).not.toBeVisible({ timeout: 3000 });
    });

    test('overlay does not appear on second visit in the same session', async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('.boot-overlay')).toBeVisible();
        await page.locator('.boot-overlay').click();
        // 700ms JS timer (dismiss) + 700ms CSS transition = ~1400ms — align with test 3
        await expect(page.locator('.boot-overlay')).not.toBeVisible({ timeout: 3000 });

        // Navigate away and back — same sessionStorage
        await page.goto('/');
        await expect(page.locator('.boot-overlay')).not.toBeVisible();
    });

    test('[NET] the origin is a fiction, drawn in the browser: no request goes out for it', async ({ page }) => {
        // LUMN-122: the sequence used to send the visitor's address to a third party, through the
        // server. The origin is now drawn from a table, the moment the sequence starts: every
        // request from the first is counted, so that one made at that moment cannot slip by.
        const requests = [];
        page.on('request', (request) => requests.push({ url: request.url(), type: request.resourceType() }));
        await page.goto('/');

        const origin = page.locator('.boot-line', { hasText: '[NET ] Origin' });
        await expect(origin).toBeVisible({ timeout: 10000 });
        // A place of Gibson's world: a zone, then a node.
        await expect(origin).toHaveText(/: \S.* \/ \S.*$/);

        // No data asked of anyone — the page loads its document, scripts, styles, fonts and images.
        expect(requests.filter(({ type }) => type === 'fetch' || type === 'xhr')).toEqual([]);
        // And nothing leaves for another host: the site itself, and Vite's server when it runs.
        const hosts = new Set([new URL(page.url()).host]);
        if (existsSync('public/hot')) hosts.add(new URL(readFileSync('public/hot', 'utf8').trim()).host);
        expect(requests.filter(({ url }) => !hosts.has(new URL(url).host))).toEqual([]);
    });

    test('[NET] the address shown is never the visitor\'s', async ({ page }) => {
        // Every test reaches the site from 127.0.0.1: an address of the table never starts that way.
        await page.goto('/');

        const source = page.locator('.boot-line', { hasText: '[NET ] Source IP' });
        await expect(source).toBeVisible({ timeout: 10000 });
        await expect(source).toHaveText(/: \d{1,3}\.\d{1,3}\.███\.███$/);
        await expect(source).not.toContainText(': 127.');
    });

    test('[NET] two visits do not come from the same place every time', async ({ browser, baseURL }) => {
        // A drawn origin, not a fixed one: over a few fresh visits — fresh contexts, so the
        // sequence plays each time — at least two differ. The line comes 4.5 s in.
        test.setTimeout(60000);
        const seen = new Set();
        for (let visit = 0; visit < 6 && seen.size < 2; visit++) {
            const context = await browser.newContext({ baseURL });
            const page = await context.newPage();
            await page.goto('/');
            const source = page.locator('.boot-line', { hasText: '[NET ] Source IP' });
            await expect(source).toBeVisible({ timeout: 10000 });
            seen.add(await source.textContent());
            await context.close();
        }

        expect(seen.size).toBeGreaterThan(1);
    });

    test('skip hint is visible', async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('.boot-skip')).toBeVisible();
        await expect(page.locator('.boot-skip')).toContainText('SKIP');
    });
});
