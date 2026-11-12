import { test, expect } from './helpers/test.js';

/**
 * Each theme renders its own fonts, served by the site (LUMN-102). Checked on what the browser did,
 * not on what the CSS declares: a family named wrong, or a face that never loaded, falls back to
 * `monospace` without an error anywhere.
 *
 * The public layout and the authentication one: they are the two that load different assets — the
 * second loads the stylesheet alone. The admin layout loads the same stylesheet as the public one.
 * The body text font applies to `.font-body` only — `<body>` itself carries Tailwind's `font-mono` —
 * and only the public pages use it.
 */

// `pixel` is the touch of a pixel font: VT323 in Sprawl (LUMN-103), the body font elsewhere.
const EXPECTED = {
    sprawl: { display: 'Space Mono', body: 'Share Tech Mono', pixel: 'VT323' },
    steampunk: { display: 'Cinzel Variable', body: 'Share Tech Mono', pixel: 'Share Tech Mono' },
    'neon-noir': { display: 'Orbitron Variable', body: 'Share Tech Mono', pixel: 'Share Tech Mono' },
};

const PAGES = {
    public: { url: '/fr', roles: ['display', 'body', 'pixel'] },
    authentication: { url: '/fr/login', roles: ['display'] },
};

/** The first family of an element's computed stack, and whether a face of it has loaded. */
async function renderedFont(page, selector) {
    return page.evaluate(async (css) => {
        await document.fonts.ready;
        const element = document.querySelector(css);
        if (element === null) return null;

        const family = getComputedStyle(element).fontFamily.split(',')[0].trim().replace(/^["']|["']$/g, '');
        const loaded = [...document.fonts].some((face) => face.family.replace(/^["']|["']$/g, '') === family && face.status === 'loaded');

        return { family, loaded };
    }, selector);
}

for (const [theme, fonts] of Object.entries(EXPECTED)) {
    for (const [layout, { url, roles }] of Object.entries(PAGES)) {
        test(`${layout} layout renders the fonts of ${theme}`, async ({ page }) => {
            await page.addInitScript((chosen) => {
                localStorage.setItem('theme', chosen);
                sessionStorage.setItem('boot_sequence_played', '1');
            }, theme);
            await page.goto(url);

            for (const role of roles) {
                expect(await renderedFont(page, `.font-${role}`), `.font-${role}`).toEqual({ family: fonts[role], loaded: true });
            }
        });
    }
}

// Space Mono is heavy in bold at button size: Sprawl's buttons take its regular weight (LUMN-103,
// decided on screenshots). The other themes keep DaisyUI's weight. The hero's profile buttons need
// an identity, which global-setup.js seeds.
for (const [theme, weight] of [['sprawl', '400'], ['steampunk', '600'], ['neon-noir', '600']]) {
    test(`the hero buttons of ${theme} are drawn at weight ${weight}`, async ({ page }) => {
        await page.addInitScript((chosen) => {
            localStorage.setItem('theme', chosen);
            sessionStorage.setItem('boot_sequence_played', '1');
        }, theme);
        await page.goto('/fr');

        const button = page.locator('#hero-profiles a').first();
        await expect(button).toBeVisible();
        expect(await button.evaluate((element) => getComputedStyle(element).fontWeight)).toBe(weight);
    });
}

test("Sprawl's bold headings are drawn with Space Mono's own bold, not a faked one", async ({ page }) => {
    // Space Mono's index.css ships the regular weight alone: without its 700 file, the browser
    // thickens the regular strokes itself, and every check on the family alone still passes.
    await page.addInitScript(() => {
        localStorage.setItem('theme', 'sprawl');
        sessionStorage.setItem('boot_sequence_played', '1');
    });
    await page.goto('/fr');

    const boldFaces = await page.evaluate(async () => {
        await document.fonts.ready;
        return [...document.fonts]
            .filter((face) => face.family.replace(/^["']|["']$/g, '') === 'Space Mono' && face.status === 'loaded')
            .map((face) => face.weight);
    });
    expect(boldFaces).toContain('700');
});
