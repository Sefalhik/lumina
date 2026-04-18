import { test, expect } from '@playwright/test';

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
        await expect(page.locator('.boot-overlay')).not.toBeVisible({ timeout: 2000 });
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

    test('[NET] Origin line contains a Gibson zone name', async ({ page }) => {
        // Mock geo API for determinism — avoids flakiness from rate-limiting or CI network restrictions.
        // page.route() must be registered before goto().
        await page.route('**/api/geo**', route =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    status: 'success',
                    query: '1.2.3.4',
                    city: 'Paris',
                    countryCode: 'FR',
                    region: 'IDF',
                    as: 'AS3215 Orange SA',
                }),
            })
        );

        await page.goto('/');

        // Wait for the NET section (~4.8s after sequence start)
        const originLine = page.locator('.boot-line', { hasText: '[NET ] Origin' });
        await expect(originLine).toBeVisible({ timeout: 10000 });

        // FR maps to 'EUROPEAN SPRAWL / PARIS CONURB' — verify the "ZONE / NODE" format
        const text = await originLine.textContent();
        expect(text).toMatch(/\w+ .+ \/ .+ (CONURB|NODE|SPRAWL|GRID|RELAY|FREEPORT|SECTOR)/);
    });

    test('[NET] Origin line shows fallback when geo fetch fails', async ({ page }) => {
        // Simulate a geo API failure — toGibsonLocation(null) returns the hardcoded fallback.
        await page.route('**/api/geo**', route => route.abort());

        await page.goto('/');

        const originLine = page.locator('.boot-line', { hasText: '[NET ] Origin' });
        await expect(originLine).toBeVisible({ timeout: 10000 });

        const text = await originLine.textContent();
        expect(text).toContain('UNCHARTED GRID / ORIGIN MASKED');
    });

    test('skip hint is visible', async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('.boot-skip')).toBeVisible();
        await expect(page.locator('.boot-skip')).toContainText('SKIP');
    });
});
