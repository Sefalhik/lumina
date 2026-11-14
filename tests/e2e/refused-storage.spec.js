import { test, expect } from './helpers/test.js';

/**
 * A browser that refuses storage (LUMN-124): a private window, blocked site data, a full quota.
 * Reading or writing `localStorage` and `sessionStorage` then throws. The page must stay the
 * visitor's — never covered for good, never in two minds about its theme — and throw nothing at
 * them: every preference is decoration, and none is worth an uncaught error.
 *
 * How each piece handles a refusal is proven on its own (utils/__tests__/storage.test.js and the
 * tests of the modules using it). This proves, in a real browser, that the page holds together.
 */

/** Makes one side of storage throw, before any script of the page runs. */
async function refuse(page, method) {
    await page.addInitScript((name) => {
        Storage.prototype[name] = function refused() {
            throw new DOMException('refused', name === 'getItem' ? 'SecurityError' : 'QuotaExceededError');
        };
    }, method);
}

/** Every error thrown at the page and left uncaught. */
function uncaught(page) {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    return errors;
}

const motionToggle = (page) => page.locator('[data-motion-toggle]');
const themeButton = (page) => page.getByRole('button', { name: 'Changer de thème' });
const themeList = (page) => page.getByRole('listbox', { name: /Thème actuel/ });

test.describe('storage that cannot be read', () => {
    test('the page shows in its default theme, without the boot sequence, and throws nothing', async ({ page }) => {
        await refuse(page, 'getItem');
        const errors = uncaught(page);

        await page.goto('/fr/');

        await expect(page.locator('html')).toHaveAttribute('data-theme', 'sprawl');
        await expect(page.locator('html')).not.toHaveAttribute('data-motion', /.*/);
        // A mark that cannot be read counts as seen: otherwise the sequence would play on every page.
        await expect(motionToggle(page)).toBeVisible();
        await expect(page.locator('.boot-overlay')).toHaveCount(0);
        await expect(page.locator('body')).not.toHaveClass(/booting/);
        expect(errors).toEqual([]);
    });

    test('the motion switch and the theme switcher still work', async ({ page }) => {
        await refuse(page, 'getItem');
        const errors = uncaught(page);
        await page.goto('/fr/');

        await motionToggle(page).click();
        await expect(motionToggle(page)).toHaveAttribute('aria-pressed', 'false');

        await themeButton(page).click();
        await themeList(page).getByRole('option').nth(1).getByRole('button').click();
        await expect(page.locator('html')).toHaveAttribute('data-theme', 'steampunk');
        await expect(themeList(page)).toHaveAccessibleName('Thème actuel : steampunk');

        expect(errors).toEqual([]);
    });
});

test.describe('storage that refuses to keep anything', () => {
    test('the boot sequence still leaves, and gives the page back', async ({ page }) => {
        // Before LUMN-124, the refused mark stopped the sequence before it removed its overlay:
        // the page stayed covered, and would not scroll, for good.
        await refuse(page, 'setItem');
        const errors = uncaught(page);
        await page.goto('/fr/');
        await expect(page.locator('.boot-overlay')).toBeVisible();

        await page.locator('.boot-overlay').click();

        await expect(page.locator('.boot-overlay')).toHaveCount(0, { timeout: 3000 });
        await expect(page.locator('body')).not.toHaveClass(/booting/);
        expect(errors).toEqual([]);
    });

    test('the theme switcher names the theme the page shows', async ({ page }) => {
        // Before LUMN-124, the switcher wrote before updating what it showed: a refused write left
        // the page in one theme and the switcher naming another.
        await refuse(page, 'setItem');
        const errors = uncaught(page);
        await page.goto('/fr/');
        await page.locator('.boot-overlay').click();
        await expect(page.locator('.boot-overlay')).toHaveCount(0, { timeout: 3000 });

        await themeButton(page).click();
        await themeList(page).getByRole('option').nth(2).getByRole('button').click();

        await expect(page.locator('html')).toHaveAttribute('data-theme', 'neon-noir');
        await expect(themeList(page)).toHaveAccessibleName('Thème actuel : neon-noir');
        expect(errors).toEqual([]);
    });

    test('the motion switch still stops the page', async ({ page }) => {
        await refuse(page, 'setItem');
        const errors = uncaught(page);
        await page.goto('/fr/');
        await page.locator('.boot-overlay').click();
        await expect(page.locator('.boot-overlay')).toHaveCount(0, { timeout: 3000 });

        await motionToggle(page).click();

        await expect(motionToggle(page)).toHaveAttribute('aria-pressed', 'false');
        await expect(page.locator('html')).toHaveAttribute('data-motion', 'off');
        expect(errors).toEqual([]);
    });

    test('the language still changes, and the click throws nothing', async ({ page }) => {
        await refuse(page, 'setItem');
        await page.addInitScript(() => {
            // Readable, so the boot sequence counts as seen; only writing is refused.
            Storage.prototype.getItem = function seen(key) {
                return key === 'boot_sequence_played' ? '1' : null;
            };
        });
        const errors = uncaught(page);
        await page.goto('/fr/');

        await page.getByRole('button', { name: 'Changer de langue' }).click();
        await page
            .getByRole('listbox', { name: 'Changer de langue' })
            .getByRole('option')
            .filter({ hasText: 'Deutsch' })
            .first()
            .click();

        await expect(page).toHaveURL(/\/de\/$/);
        expect(errors).toEqual([]);
    });
});
