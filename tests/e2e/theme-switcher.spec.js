import { test, expect } from '@playwright/test';

test.describe('ThemeSwitcher', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/');
        await page.evaluate(() => localStorage.removeItem('theme'));
        await page.reload();
    });

    test('default theme is sprawl', async ({ page }) => {
        await expect(page.locator('html')).toHaveAttribute('data-theme', 'sprawl');
    });

    test('switches to steampunk and updates data-theme', async ({ page }) => {
        await page.getByRole('button', { name: 'Changer de thème' }).click();
        await page.getByRole('option', { name: /steam/i }).locator('button').click();

        await expect(page.locator('html')).toHaveAttribute('data-theme', 'steampunk');
    });

    test('switches to neon-noir and updates favicon href', async ({ page }) => {
        await page.getByRole('button', { name: 'Changer de thème' }).click();
        await page.getByRole('option', { name: /neon/i }).locator('button').click();

        await expect(page.locator('html')).toHaveAttribute('data-theme', 'neon-noir');
        const faviconHref = await page.locator('#favicon').getAttribute('href');
        expect(faviconHref).toContain('favicon-neon-noir.svg');
    });

    test('persists theme across page reload', async ({ page }) => {
        await page.getByRole('button', { name: 'Changer de thème' }).click();
        await page.getByRole('option', { name: /neon/i }).locator('button').click();
        await page.reload();

        await expect(page.locator('html')).toHaveAttribute('data-theme', 'neon-noir');
    });
});
