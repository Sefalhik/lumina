import { test, expect } from './helpers/test.js';
import { ADMIN_SESSION } from './helpers/auth.js';

/**
 * The public homepage and the admin form that feeds it, in one serial file.
 *
 * They cannot live apart. Under `fullyParallel: true` Playwright runs spec files
 * concurrently, and this admin form rewrites the French homepage content for the
 * length of its run. Restoring in afterAll is not enough — the window is the
 * whole run, not its end. `site-identity.spec.js` pairs its footer and its form
 * for exactly this reason.
 *
 * Serialising this file is necessary and not sufficient: `admin/skills-editor`
 * submits the same form from another file, and CI caught it doing so — it fills
 * the bio with one sentence, which makes the About section disappear.
 *
 * So the public tests read **German**. The admin form writes the source locale
 * and nothing else — five explicit `setTranslation($field, 'fr', …)` calls, and
 * `test_update_does_not_erase_other_locale_translations()` guards that — so no
 * admin spec can reach `/de/`. This is a property of the application, not a
 * trick, and German is the stricter subject anyway: its bio runs 1055 characters
 * against the French 1050.
 *
 * The day per-locale editing ships, that immunity ends and these tests need a
 * fixture of their own. French rendering stays covered by HomepagePublicTest.
 */
test.describe.configure({ mode: 'serial' });

test.describe('Homepage', () => {
    // The hero is the first section. Everything measured here is scoped to it.
    // Its profile buttons read the identity row, which the admin specs rewrite:
    // they are tested in site-identity.spec.js, serialised with those specs.
    const hero = (page) => page.locator('section').first();

    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
        await page.goto('/de/');
    });

    test('the hero shows one paragraph and the biography lives below', async ({ page }) => {
        await expect(hero(page).locator('p.font-body')).toHaveCount(1);

        const about = page.locator('section[aria-labelledby="about-heading"]');
        await expect(about).toBeVisible();

        // Real paragraph elements, not one block with collapsed newlines.
        expect(await about.locator('p').count()).toBeGreaterThan(1);
    });

    test('the about section sits after the hero', async ({ page }) => {
        const lead = hero(page).locator('[data-smoke="bio"]');
        const about = page.locator('section[aria-labelledby="about-heading"]');

        // Asserted before measured: boundingBox() on a locator that resolves to
        // nothing waits out the whole timeout and then reports a timeout, which
        // says nothing about what is missing.
        await expect(lead).toBeVisible();
        await expect(about).toBeVisible();

        const leadBox = await lead.boundingBox();
        const aboutBox = await about.boundingBox();

        expect(leadBox).not.toBeNull();
        expect(aboutBox).not.toBeNull();
        expect(aboutBox.y).toBeGreaterThan(leadBox.y);
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
        await expect(page.locator('html')).toHaveAttribute('lang', 'de');

        // The French page is rendered from the same code path; asserting it here
        // would only measure whatever the admin specs left behind.
        await expect(
            page.getByRole('navigation', { name: 'Hauptnavigation' }).getByRole('link', { name: 'Startseite' }),
        ).toBeVisible();
    });
});

test.describe('Homepage — mobile', () => {
    // An iPhone-sized viewport: the navigation collapses into the menu button.
    test.use({ viewport: { width: 390, height: 844 } });

    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
        await page.goto('/de/');
    });

    test('nothing overflows the screen sideways', async ({ page }) => {
        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );

        expect(overflow).toBe(0);
    });

    test('the menu opens on the links that remain, none of them empty', async ({ page }) => {
        await expect(page.getByRole('navigation', { name: 'Hauptnavigation' })).toBeHidden();
        // Closed until asked for: without its dropdown wrapper the menu would
        // sit open over the hero, and every assertion below would still pass.
        await expect(page.locator('#mobile-menu')).toBeHidden();

        await page.getByRole('button', { name: 'Menü' }).click();

        // CV, projects and blog are withdrawn until their pages are ready
        // (LUMN-104): a visitor's menu holds the home link alone.
        const links = page.locator('#mobile-menu a');
        await expect(links).toHaveCount(1);
        await expect(links.first()).toBeVisible();
        await expect(links.first()).toHaveText('Startseite');
    });

    test('the menu is operable from the keyboard alone', async ({ page }) => {
        const menuButton = page.getByRole('button', { name: 'Menü' });
        const menu = page.locator('#mobile-menu');
        const isFocused = (locator) => locator.evaluate((element) => element === document.activeElement);

        // Real Tab presses from the top of the page, not .focus(): the point is
        // that the button is reachable in the tab order at all. The bound is a
        // guard against an endless loop, not a target: it takes 34 presses
        // today, because the language switcher opens on focus and puts its 24
        // locales in the tab order before the menu button.
        for (let i = 0; i < 60 && !(await isFocused(menuButton)); i++) {
            await page.keyboard.press('Tab');
        }
        expect(await isFocused(menuButton)).toBe(true);

        // The menu opens on focus (DaisyUI's dropdown), and stays open while
        // the focus moves inside it.
        await expect(menu).toBeVisible();

        const homeLink = menu.getByRole('link', { name: 'Startseite' });
        for (let i = 0; i < 3 && !(await isFocused(homeLink)); i++) {
            await page.keyboard.press('Tab');
        }
        expect(await isFocused(homeLink)).toBe(true);
        await expect(homeLink).toBeVisible();

        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/\/de\/?$/);
    });
});

test.describe('Admin — Homepage form', () => {
    test.use({ adminSession: ADMIN_SESSION });

    let originalContent;

    test.beforeAll(async ({ request }) => {
        const res = await request.get('/e2e/homepage-content');
        originalContent = await res.json();
    });

    test.afterAll(async ({ request }) => {
        expect((await request.post('/e2e/homepage-content', { data: originalContent })).ok()).toBe(true);
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
