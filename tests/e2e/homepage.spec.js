import { test, expect } from '@playwright/test';
import { ADMIN_AUTH_FILE } from './helpers/auth.js';

/**
 * The public homepage and the admin form that feeds it, in one serial file.
 *
 * They cannot live apart. Under `fullyParallel: true` Playwright runs spec
 * files concurrently, and this admin form rewrites the French homepage content
 * for the length of its run — so a public spec in another file reads whatever
 * half-written state the form happens to be in. Measured: the About section
 * vanished because the form had replaced the bio with a single sentence.
 *
 * `site-identity.spec.js` keeps the footer and its admin form together for the
 * same reason. Restoring in afterAll is not enough: the window is the whole run,
 * not its end.
 */
test.describe.configure({ mode: 'serial' });

test.describe('Homepage', () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
        await page.goto('/fr/');
    });

    test('both calls to action are visible without scrolling', async ({ page }) => {
        const viewport = page.viewportSize();
        expect(viewport).not.toBeNull();

        for (const name of [/projets/i, /cv/i]) {
            const cta = page.getByRole('link', { name }).first();

            await expect(cta).toBeVisible();

            const box = await cta.boundingBox();
            expect(box).not.toBeNull();

            // toBeVisible() is true for an element 3000px down the page. The
            // assertion that matters is where its bottom edge sits relative to
            // the fold.
            expect(box.y + box.height).toBeLessThanOrEqual(viewport.height);
        }

        // Nothing has been scrolled to reach them.
        expect(await page.evaluate(() => window.scrollY)).toBe(0);
    });

    test('the hero shows one paragraph and the biography lives below', async ({ page }) => {
        const hero = page.locator('section').first();

        await expect(hero.locator('p.font-body')).toHaveCount(1);

        const about = page.locator('section[aria-labelledby="about-heading"]');
        await expect(about).toBeVisible();

        // Real paragraph elements, not one block with collapsed newlines.
        expect(await about.locator('p').count()).toBeGreaterThan(1);
    });

    test('the about section sits after the calls to action', async ({ page }) => {
        const ctaBox = await page
            .getByRole('link', { name: /projets/i })
            .first()
            .boundingBox();
        const aboutBox = await page.locator('section[aria-labelledby="about-heading"]').boundingBox();

        expect(ctaBox).not.toBeNull();
        expect(aboutBox).not.toBeNull();
        expect(aboutBox.y).toBeGreaterThan(ctaBox.y);
    });

    test('decorative glyphs are hidden from assistive technology', async ({ page }) => {
        // A screen reader would otherwise read "slash slash" before the tagline
        // and "greater-than" before every section heading.
        for (const glyph of ['//', '›']) {
            const exposed = page.locator(`span:not([aria-hidden="true"])`, { hasText: glyph });

            await expect(exposed).toHaveCount(0);
        }
    });

    test('the page is served in the requested locale', async ({ page }) => {
        await page.goto('/de/');

        await expect(page.locator('html')).toHaveAttribute('lang', 'de');
        await expect(page.locator('section[aria-labelledby="about-heading"]')).toBeVisible();
    });
});

test.describe('Admin — Homepage form', () => {
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
        await expect(page.locator('#meta_description_fr')).toBeVisible();
        await expect(page.locator('#skills-editor')).toBeVisible();
    });

    test('displays success flash after valid save', async ({ page }) => {
        await page.locator('#tagline_fr').fill('Mon accroche');
        await page.locator('#subtitle_fr').fill('Mon sous-titre');
        await page.locator('#bio_fr').fill('Ma bio en français.');
        await page.locator('#meta_description_fr').fill('Ma description SEO.');
        // skills[fr] hidden input is serialized by the Vue component — default value '[]' is valid.

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
        await page.locator('#bio_fr').evaluate((el) => {
            el.value = 'x'.repeat(1001);
        });

        await page.getByRole('button', { name: /enregistrer/i }).click();

        await expect(page.locator('#bio_fr')).toHaveClass(/border-error/);
    });
});
