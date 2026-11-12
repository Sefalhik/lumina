import { test, expect } from './helpers/test.js';
import { ADMIN_SNOW_SESSION } from './helpers/auth.js';

/**
 * Sprawl's sky: the dead channel's snow (LUMN-103). The grain itself — its ceiling, its constant
 * mean — is proven by resources/js/utils/__tests__/dead-channel.test.js. This proves what only a
 * browser shows: that it runs when it should, and stops whenever nobody should see it move.
 *
 * The canvas counts its frames in `data-frames`. "Stopped" is measured, not assumed: the count must
 * not move over several frame periods.
 */

const canvas = (page) => page.locator('canvas.dead-channel');
const frames = async (page) => Number(await canvas(page).getAttribute('data-frames'));

/** Several frame periods at twelve a second: a running snow draws at least four frames in it. */
const WAIT = 500;

async function open(page, theme) {
    await page.addInitScript((chosen) => {
        sessionStorage.setItem('boot_sequence_played', '1');
        localStorage.setItem('theme', chosen);
    }, theme);
    await page.goto('/fr/');
}

/** Hides or shows the tab, as the browser would on switching tabs. */
async function setHidden(page, hidden) {
    await page.evaluate((value) => {
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => value });
        document.dispatchEvent(new Event('visibilitychange'));
    }, hidden);
}

test('Sprawl draws the snow behind the page, marked decorative', async ({ page }) => {
    await open(page, 'sprawl');

    await expect(canvas(page)).toBeVisible();
    await expect(canvas(page)).toHaveAttribute('aria-hidden', 'true');
    await expect(canvas(page)).toHaveAttribute('data-a11y-role', 'decorative');

    const before = await frames(page);
    await page.waitForTimeout(WAIT);
    expect(await frames(page)).toBeGreaterThanOrEqual(before + 4);
});

test('the snow is seen: an empty patch of the page changes from one frame to the next', async ({ page }) => {
    // toBeVisible() says the canvas is there, not that anyone sees it: an opaque <body> background
    // painted over it would hide the snow and keep every other test green.
    await open(page, 'sprawl');
    await expect.poll(() => frames(page)).toBeGreaterThan(2);

    // Right of the hero's console panel, below the header: no screen there, only the sky.
    const patch = { x: 1150, y: 200, width: 100, height: 80 };
    const first = await page.screenshot({ clip: patch });
    await page.waitForTimeout(WAIT);
    const second = await page.screenshot({ clip: patch });

    expect(first.equals(second)).toBe(false);
});

test('the snow stops while the tab is hidden, and starts again when it shows', async ({ page }) => {
    await open(page, 'sprawl');
    await expect.poll(() => frames(page)).toBeGreaterThan(0);

    await setHidden(page, true);
    const hidden = await frames(page);
    await page.waitForTimeout(WAIT);
    expect(await frames(page)).toBe(hidden);

    await setHidden(page, false);
    await page.waitForTimeout(WAIT);
    expect(await frames(page)).toBeGreaterThanOrEqual(hidden + 4);
});

test.describe('under a reduced-motion preference', () => {
    test.use({ reducedMotion: 'reduce' });

    test('the snow is drawn once and never moves', async ({ page }) => {
        await open(page, 'sprawl');

        await expect.poll(() => frames(page)).toBe(1);
        await page.waitForTimeout(WAIT);
        expect(await frames(page)).toBe(1);
    });
});

for (const theme of ['steampunk', 'neon-noir']) {
    test(`${theme} shows no snow, and runs nothing`, async ({ page }) => {
        await open(page, theme);

        await expect(canvas(page)).toBeHidden();
        await page.waitForTimeout(WAIT);
        expect(await frames(page)).toBe(0);
    });
}

test('leaving Sprawl stops the snow, coming back starts it', async ({ page }) => {
    await open(page, 'sprawl');
    await expect.poll(() => frames(page)).toBeGreaterThan(0);

    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'neon-noir'));
    await expect(canvas(page)).toBeHidden();
    const away = await frames(page);
    await page.waitForTimeout(WAIT);
    expect(await frames(page)).toBe(away);

    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'sprawl'));
    await page.waitForTimeout(WAIT);
    expect(await frames(page)).toBeGreaterThanOrEqual(away + 4);
});

test('a theme colour the browser cannot draw stops the snow, reported once', async ({ page }) => {
    // A canvas ignores a colour it cannot parse and keeps painting in black, silently. A broken
    // floor is the case that would go unseen: a black floor under a valid ceiling is still "valid".
    const errors = [];
    page.on('console', (message) => {
        if (message.type() === 'error' && message.text().startsWith('The dead channel snow stopped')) {
            errors.push(message.text());
        }
    });
    await open(page, 'sprawl');
    await expect.poll(() => frames(page)).toBeGreaterThan(0);

    // The theme attribute set again makes the island re-read its colours, as a theme change does.
    await page.evaluate(() => {
        document.documentElement.style.setProperty('--color-base-100', 'not-a-colour');
        document.documentElement.setAttribute('data-theme', 'sprawl');
    });
    await expect.poll(() => errors.length).toBe(1);

    const stopped = await frames(page);
    await page.waitForTimeout(WAIT);
    expect(await frames(page)).toBe(stopped);
    expect(errors).toHaveLength(1);
});

test('no pixel of snow is lighter than base-300, as the browser draws it', async ({ page }) => {
    // The unit test proves the arithmetic on given colours; this proves the colours the island
    // reads from the theme, and what the canvas actually holds.
    await open(page, 'sprawl');
    await expect.poll(() => frames(page)).toBeGreaterThan(2);

    const { lightest, ceiling } = await page.evaluate(() => {
        const luminance = (red, green, blue) =>
            [red, green, blue]
                .map((channel) => channel / 255)
                .map((c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
                .reduce((sum, c, index) => sum + c * [0.2126, 0.7152, 0.0722][index], 0);

        const probe = document.createElement('canvas').getContext('2d');
        probe.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--color-base-300').trim();
        probe.fillRect(0, 0, 1, 1);
        const [r, g, b] = probe.getImageData(0, 0, 1, 1).data;

        const snow = document.querySelector('canvas.dead-channel');
        const { data } = snow.getContext('2d').getImageData(0, 0, snow.width, snow.height);
        let max = 0;
        for (let offset = 0; offset < data.length; offset += 4) {
            max = Math.max(max, luminance(data[offset], data[offset + 1], data[offset + 2]));
        }
        return { lightest: max, ceiling: luminance(r, g, b) };
    });

    expect(lightest).toBeGreaterThan(0);
    expect(lightest).toBeLessThanOrEqual(ceiling);
});

test.describe('the admin pages', () => {
    test.use({ adminSession: ADMIN_SNOW_SESSION });

    test('have the same sky: the snow runs behind the dashboard in Sprawl', async ({ page }) => {
        // Laurent's decision of 2026-11-12: the whole interface is one console.
        await page.addInitScript(() => localStorage.setItem('theme', 'sprawl'));
        await page.goto('/fr/admin');

        await expect(canvas(page)).toBeVisible();
        const before = await frames(page);
        await page.waitForTimeout(WAIT);
        expect(await frames(page)).toBeGreaterThanOrEqual(before + 4);
    });
});

test.describe("Sprawl's console panels", () => {
    // Option A of 2026-11-12: the text sits on the console's screens, the snow is the sky around them.
    const PANELS = [
        '#main-content > section:first-of-type .console-panel',
        'section[aria-labelledby="about-heading"]',
        'section[aria-labelledby="skills-heading"]',
    ];

    /** The computed background of an element, and base-100 as the browser resolves it. */
    const backgrounds = (page, selector) =>
        page.evaluate((css) => {
            const probe = document.createElement('div');
            probe.style.backgroundColor = 'var(--color-base-100)';
            document.body.append(probe);
            const base100 = getComputedStyle(probe).backgroundColor;
            probe.remove();
            return { panel: getComputedStyle(document.querySelector(css)).backgroundColor, base100 };
        }, selector);

    for (const selector of PANELS) {
        test(`${selector} is an opaque screen in Sprawl: no snow under the text`, async ({ page }) => {
            await open(page, 'sprawl');

            const { panel, base100 } = await backgrounds(page, selector);
            expect(panel).toBe(base100);
        });
    }

    for (const theme of ['steampunk', 'neon-noir']) {
        test(`${theme} draws no console panel`, async ({ page }) => {
            await open(page, theme);

            for (const selector of PANELS) {
                expect((await backgrounds(page, selector)).panel).toBe('rgba(0, 0, 0, 0)');
            }
        });
    }

    test('the name holds on one line at desktop width', async ({ page }) => {
        await open(page, 'sprawl');

        const { height, lineHeight } = await page
            .locator('h1 span')
            .nth(1)
            .evaluate((span) => ({
                height: span.getBoundingClientRect().height,
                lineHeight: parseFloat(getComputedStyle(span).lineHeight),
            }));
        expect(height).toBeLessThan(lineHeight * 1.5);
    });
});
