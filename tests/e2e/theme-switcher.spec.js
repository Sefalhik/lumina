import { test, expect } from '@playwright/test';

test.describe('ThemeSwitcher', () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
        await page.goto('/fr/');
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

test.describe('ThemeSwitcher — cross-tab sync', () => {
    test('theme change propagates to another open tab', async ({ context }) => {
        await context.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));

        const page1 = await context.newPage();
        const page2 = await context.newPage();

        await page1.goto('/fr/');
        // Start both tabs from the default theme with no stored value.
        await page1.evaluate(() => localStorage.removeItem('theme'));
        await page1.reload();
        await page2.goto('/fr/');

        await expect(page1.locator('html')).toHaveAttribute('data-theme', 'sprawl');
        await expect(page2.locator('html')).toHaveAttribute('data-theme', 'sprawl');

        await page1.getByRole('button', { name: 'Changer de thème' }).click();
        await page1.getByRole('option', { name: /neon/i }).locator('button').click();

        await expect(page1.locator('html')).toHaveAttribute('data-theme', 'neon-noir');
        await expect(page2.locator('html')).toHaveAttribute('data-theme', 'neon-noir');
    });

    test('favicon updates in the other tab when theme changes', async ({ context }) => {
        await context.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));

        const page1 = await context.newPage();
        const page2 = await context.newPage();

        await page1.goto('/fr/');
        await page2.goto('/fr/');

        await page1.getByRole('button', { name: 'Changer de thème' }).click();
        await page1.getByRole('option', { name: /steam/i }).locator('button').click();

        const faviconHref = await page2.locator('#favicon').getAttribute('href');
        expect(faviconHref).toContain('favicon-steampunk.svg');
    });
});
