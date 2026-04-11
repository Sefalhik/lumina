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
        await page.locator('.boot-overlay').click();
        await expect(page.locator('.boot-overlay')).not.toBeVisible({ timeout: 2000 });

        // Navigate away and back — same sessionStorage
        await page.goto('/');
        await expect(page.locator('.boot-overlay')).not.toBeVisible();
    });

    test('[NET] Origin line contains a Gibson zone name', async ({ page }) => {
        await page.goto('/');

        // Wait for the NET section (~4.8s after sequence start + fetch time)
        const originLine = page.locator('.boot-line', { hasText: '[NET ] Origin' });
        await expect(originLine).toBeVisible({ timeout: 10000 });

        // Verify the line follows the "ZONE / NODE" format
        const text = await originLine.textContent();
        expect(text).toMatch(/\w+ .+ \/ .+ (CONURB|NODE|SPRAWL|GRID|RELAY|FREEPORT|SECTOR)/);
    });

    test('skip hint is visible', async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('.boot-skip')).toBeVisible();
        await expect(page.locator('.boot-skip')).toContainText('SKIP');
    });
});
