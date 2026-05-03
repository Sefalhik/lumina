import { test, expect } from '@playwright/test';

test.describe('LanguageSwitcher', () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
        await page.goto('/fr/');
    });

    const langOptions = (page) =>
        page.getByRole('listbox', { name: 'Changer de langue' }).getByRole('option');

    test('displays the current locale flag and code in the trigger button', async ({ page }) => {
        const button = page.getByRole('button', { name: 'Changer de langue' });
        await expect(button).toContainText('fr');
        await expect(button).toContainText('🇫🇷');
    });

    test('filter reduces the visible options', async ({ page }) => {
        await page.getByRole('button', { name: 'Changer de langue' }).click();

        await expect(langOptions(page)).toHaveCount(24);

        await page.getByPlaceholder('Filtrer…').fill('deu');

        await expect(langOptions(page)).toHaveCount(1);
        await expect(langOptions(page)).toContainText('Deutsch');
    });

    test('shows a no-results message when the filter matches nothing', async ({ page }) => {
        await page.getByRole('button', { name: 'Changer de langue' }).click();
        await page.getByPlaceholder('Filtrer…').fill('zzz');

        await expect(langOptions(page)).toHaveCount(0);
        await expect(page.getByRole('listbox', { name: 'Changer de langue' })).toContainText('Aucun résultat');
    });

    test('switching locale reloads the page with the correct html[lang]', async ({ page }) => {
        await page.getByRole('button', { name: 'Changer de langue' }).click();
        await page.getByRole('option', { name: /Deutsch/ }).locator('a').click();

        await expect(page.locator('html')).toHaveAttribute('lang', 'de');
        await expect(page).toHaveURL(/\/de\//);
    });

    test('switching locale preserves the current sub-path', async ({ page }) => {
        await page.goto('/fr/cv');
        await page.getByRole('button', { name: 'Changer de langue' }).click();
        await page.getByRole('option', { name: /English/ }).locator('a').click();

        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        await expect(page).toHaveURL(/\/en\/cv/);
    });
});
