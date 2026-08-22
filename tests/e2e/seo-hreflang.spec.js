import { test, expect } from '@playwright/test';

/**
 * Guards against divergence between the TWO implementations of "what is this
 * page's URL in another locale?":
 *
 *   PHP — App\Services\Seo\LocalizedUrlService, via the Laravel router
 *   JS  — resources/js/utils/language-switcher.js urlFor(), via path substitution
 *
 * Each is unit-tested in isolation and passes; only a browser sees both in the
 * same document. A divergence would send crawlers to one URL and visitors to
 * another for the same page in the same language.
 */

const INDEXABLE = ['fr', 'en', 'de', 'it', 'nl'];

/**
 * Trailing slashes are absorbed by the canonical tag — /de/ declares /de as its
 * canonical, so both collapse to a single indexed URL. Normalise them away: a
 * meaningful divergence is a different PATH, not a slash.
 */
const path = (url) => new URL(url, 'https://placeholder').pathname.replace(/\/$/, '') || '/';

const headAlternates = (page) =>
    page.evaluate(() =>
        Object.fromEntries(
            [...document.querySelectorAll('head link[rel="alternate"]')].map((el) => [
                el.getAttribute('hreflang'),
                el.href,
            ]),
        ),
    );

const switcherHrefs = async (page) => {
    await page.getByRole('button', { name: 'Changer de langue' }).click();

    return page.evaluate(() =>
        Object.fromEntries(
            [
                ...document.querySelectorAll(
                    'ul[role="listbox"][aria-label="Changer de langue"] a[hreflang]',
                ),
            ].map((el) => [el.getAttribute('hreflang'), el.href]),
        ),
    );
};

test.describe('SEO — hreflang tags and language switcher agree', () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
    });

    test('head alternates and switcher links match on the homepage', async ({ page }) => {
        await page.goto('/fr/');

        const alternates = await headAlternates(page);
        const switcher = await switcherHrefs(page);

        for (const locale of INDEXABLE) {
            expect(alternates[locale], `missing hreflang="${locale}" in head`).toBeTruthy();
            expect(switcher[locale], `missing switcher link for "${locale}"`).toBeTruthy();
            expect(path(switcher[locale]), `switcher and hreflang disagree for "${locale}"`).toBe(
                path(alternates[locale]),
            );
        }
    });

    test('head alternates and switcher links match on a page with a route parameter', async ({
        page,
    }) => {
        // The discriminating case: PHP rebuilds the URL from named route
        // parameters, JS swaps a path segment. A nested path is where the two
        // are most likely to drift apart.
        await page.goto('/fr/blog/guzzle-8-major-bump');

        const alternates = await headAlternates(page);
        const switcher = await switcherHrefs(page);

        for (const locale of INDEXABLE) {
            expect(path(alternates[locale])).toBe(`/${locale}/blog/guzzle-8-major-bump`);
            expect(path(switcher[locale]), `switcher and hreflang disagree for "${locale}"`).toBe(
                path(alternates[locale]),
            );
        }
    });

    test('the browser parses canonical and alternates as real head elements', async ({ page }) => {
        // Feature tests assert on the response string; this asserts the browser
        // actually built the nodes — catching malformed markup that a string
        // match would happily accept.
        await page.goto('/fr/cv');

        const canonical = await page
            .locator('head link[rel="canonical"]')
            .getAttribute('href');

        expect(path(canonical)).toBe('/fr/cv');

        await expect(page.locator('head link[rel="alternate"]')).toHaveCount(INDEXABLE.length + 1);
        await expect(page.locator('head link[rel="alternate"][hreflang="x-default"]')).toHaveCount(1);
    });

    test('a served but non-indexed locale exposes no alternates', async ({ page }) => {
        await page.goto('/mt/');

        await expect(page.locator('head link[rel="canonical"]')).toHaveCount(1);
        await expect(page.locator('head link[rel="alternate"]')).toHaveCount(0);
    });
});
