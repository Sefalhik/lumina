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

const EXPECTED = {
    sprawl: { display: 'Orbitron Variable', body: 'Share Tech Mono' },
    steampunk: { display: 'Cinzel Variable', body: 'Share Tech Mono' },
    'neon-noir': { display: 'Orbitron Variable', body: 'Share Tech Mono' },
};

const PAGES = {
    public: { url: '/fr', roles: ['display', 'body'] },
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
