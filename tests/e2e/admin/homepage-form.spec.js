import { test, expect } from '@playwright/test';
import { ADMIN_AUTH_FILE } from '../helpers/auth.js';

test.describe('Admin — Homepage form', () => {
    // Serial mode: form submissions modify server-side session (flash messages),
    // which would interfere across parallel tests sharing the same session state.
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: ADMIN_AUTH_FILE });

    let originalContent;

    test.beforeAll(async ({ request }) => {
        const res = await request.get('/e2e/homepage-content');
        originalContent = await res.json();
    });

    test.afterAll(async ({ request }) => {
        await request.post('/e2e/homepage-content', { data: originalContent });
    });

    test.beforeEach(async ({ page }) => {
        await page.goto('/fr/admin/homepage');
    });

    test('shows the edit form', async ({ page }) => {
        await expect(page.locator('#tagline_fr')).toBeVisible();
        await expect(page.locator('#subtitle_fr')).toBeVisible();
        await expect(page.locator('#bio_fr')).toBeVisible();
    });

    test('displays success flash after valid save', async ({ page }) => {
        await page.locator('#tagline_fr').fill('Mon accroche');
        await page.locator('#subtitle_fr').fill('Mon sous-titre');
        await page.locator('#bio_fr').fill('Ma bio en français.');

        await page.getByRole('button', { name: /enregistrer/i }).click();
        await page.waitForURL('**/fr/admin/homepage');

        await expect(page.locator('[class*="border-primary/40"]').filter({ hasText: /sauvegardé/i })).toBeVisible();
    });

    test('shows error summary when tagline is empty', async ({ page }) => {
        await page.locator('#tagline_fr').fill('');
        await page.locator('#subtitle_fr').fill('Sous-titre valide');
        await page.locator('#bio_fr').fill('Bio valide');

        await page.getByRole('button', { name: /enregistrer/i }).click();

        await expect(page.locator('[class*="border-error/40"]')).toBeVisible();
    });

    test('applies border-error class to invalid field', async ({ page }) => {
        await page.locator('#tagline_fr').fill('');
        await page.locator('#subtitle_fr').fill('Sous-titre valide');
        await page.locator('#bio_fr').fill('Bio valide');

        await page.getByRole('button', { name: /enregistrer/i }).click();

        await expect(page.locator('#tagline_fr')).toHaveClass(/border-error/);
    });

    test('does not apply border-error to valid fields after partial failure', async ({ page }) => {
        await page.locator('#tagline_fr').fill('');
        await page.locator('#subtitle_fr').fill('Sous-titre valide');
        await page.locator('#bio_fr').fill('Bio valide');

        await page.getByRole('button', { name: /enregistrer/i }).click();

        await expect(page.locator('#subtitle_fr')).not.toHaveClass(/border-error/);
        await expect(page.locator('#bio_fr')).not.toHaveClass(/border-error/);
    });

    test('preserves valid field values after failed validation', async ({ page }) => {
        await page.locator('#tagline_fr').fill('');
        await page.locator('#subtitle_fr').fill('Sous-titre conservé');
        await page.locator('#bio_fr').fill('Bio conservée');

        await page.getByRole('button', { name: /enregistrer/i }).click();

        await expect(page.locator('#subtitle_fr')).toHaveValue('Sous-titre conservé');
        await expect(page.locator('#bio_fr')).toHaveValue('Bio conservée');
    });

    test('shows error when bio exceeds 1000 characters', async ({ page }) => {
        await page.locator('#tagline_fr').fill('Accroche valide');
        await page.locator('#subtitle_fr').fill('Sous-titre valide');
        // fill() respects the HTML maxlength attribute and silently truncates —
        // use evaluate() to set the value directly and bypass it.
        await page.locator('#bio_fr').evaluate((el) => { el.value = 'x'.repeat(1001); });

        await page.getByRole('button', { name: /enregistrer/i }).click();

        await expect(page.locator('#bio_fr')).toHaveClass(/border-error/);
    });
});
