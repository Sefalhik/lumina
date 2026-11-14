import { test, expect } from './helpers/test.js';
import { ADMIN_MOTION_SESSION } from './helpers/auth.js';

/**
 * The motion switch (LUMN-11, WCAG 2.2.2). The rule itself — a choice wins both ways, without one
 * the preference — is proven by resources/js/utils/__tests__/motion.test.js. This proves what only a
 * browser shows: that the stylesheet, the anti-FOUC script, the snow, the boot sequence and the
 * switch all apply it, on every layout, in every theme.
 *
 * "Stopped" is measured, not assumed: the browser lists the animations it runs, and a looping one
 * still running is a failure — whatever its name, so that an effect added later is caught too.
 */

const THEMES = ['sprawl', 'steampunk', 'neon-noir'];

/**
 * Every page a visitor can open, by layout. An effect added anywhere without its stopped state fails
 * here on its first run — not only on the three pages a test happened to know.
 */
const PUBLIC_PAGES = ['/fr/', '/fr/cv', '/fr/projects', '/fr/blog', '/fr/login'];
const ADMIN_PAGES = [
    '/fr/admin',
    '/fr/admin/homepage',
    '/fr/admin/experiences',
    '/fr/admin/experiences/create',
    '/fr/admin/identity',
    '/fr/two-factor/challenge',
];

/** The looping animations the page runs right now, by name. */
function loops(page) {
    return page.evaluate(() =>
        document
            .getAnimations()
            .filter((animation) => animation.playState === 'running')
            .filter((animation) => animation.effect?.getTiming().iterations === Infinity)
            .map((animation) => animation.animationName ?? animation.constructor.name),
    );
}

/**
 * The same, sorted: "animations back on" means every one of them, not some. Asserting only that the
 * list is not empty let a stopped state that lost on specificity pass for a running one.
 */
async function sortedLoops(page) {
    return (await loops(page)).sort();
}

const toggle = (page) => page.locator('[data-motion-toggle]');
const snowFrames = async (page) => Number(await page.locator('canvas.dead-channel').getAttribute('data-frames'));

/** Several frame periods of the snow, twelve a second. */
const WAIT = 500;

/**
 * Opens a page as a returning visitor: theme and motion choice already stored, boot sequence seen.
 * `motion: null` stores no choice.
 */
async function open(page, path, { theme = 'sprawl', motion = null, boot = false } = {}) {
    await page.addInitScript(
        ([chosenTheme, chosenMotion, skipBoot]) => {
            if (skipBoot) sessionStorage.setItem('boot_sequence_played', '1');
            localStorage.setItem('theme', chosenTheme);
            if (chosenMotion !== null) localStorage.setItem('motion', chosenMotion);
        },
        [theme, motion, !boot],
    );
    await page.goto(path);
    await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
}

test.describe('animations stopped by the visitor', () => {
    for (const path of PUBLIC_PAGES) {
        for (const theme of THEMES) {
            test(`no looping animation runs on ${path} — ${theme}`, async ({ page }) => {
                await open(page, path, { theme, motion: 'off' });

                expect(await loops(page)).toEqual([]);
            });
        }
    }

    test('the snow draws one still frame, then nothing', async ({ page }) => {
        await open(page, '/fr/', { motion: 'off' });
        await expect.poll(() => snowFrames(page)).toBe(1);

        await page.waitForTimeout(WAIT);

        expect(await snowFrames(page)).toBe(1);
    });

    test('the effects are frozen, not removed: the glow and the cursor stay', async ({ page }) => {
        await open(page, '/fr/', { motion: 'off' });

        const glow = await page
            .locator('h2.neon-pulse-slow')
            .evaluate((element) => getComputedStyle(element).textShadow);
        const cursor = await page
            .locator('.cursor-blink')
            .first()
            .evaluate((element) => {
                const after = getComputedStyle(element, '::after');
                return { content: after.content, opacity: after.opacity };
            });

        expect(glow).not.toBe('none');
        // The glow is the pulse's brightest moment: four layers, the first at 8px.
        expect(glow.match(/px/g).length).toBeGreaterThanOrEqual(12);
        expect(cursor).toEqual({ content: '"█"', opacity: '1' });
    });

    test('hover and focus transitions stay', async ({ page }) => {
        await open(page, '/fr/', { motion: 'off' });

        const duration = await page
            .getByRole('navigation', { name: 'Navigation principale' })
            .getByRole('link')
            .first()
            .evaluate((element) => getComputedStyle(element).transitionDuration);

        expect(duration).not.toBe('0s');
    });

    test('the boot sequence does not play', async ({ page }) => {
        await open(page, '/fr/', { motion: 'off', boot: true });
        // The page's islands are mounted once the switch is: the boot sequence had its chance.
        await expect(toggle(page)).toBeVisible();

        await expect(page.locator('.boot-overlay')).toHaveCount(0);
        await expect(page.locator('body')).not.toHaveClass(/booting/);
    });
});

test('a boot sequence already playing stops its cursor when animations stop', async ({ page, context }) => {
    // The sequence does not start once animations are stopped; it may be playing when they stop —
    // the choice made in another tab. Its cursor blinks in BootSequence.vue's own style.
    await open(page, '/fr/', { boot: true });
    await expect(page.locator('.boot-cursor')).toBeVisible();
    expect(await loops(page)).toContain(
        await page.locator('.boot-cursor').evaluate((el) => getComputedStyle(el).animationName),
    );

    const other = await context.newPage();
    await other.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
    await other.goto('/fr/');
    await toggle(other).click();

    await expect(page.locator('html')).toHaveAttribute('data-motion', 'off');
    await expect.poll(() => loops(page)).toEqual([]);
    await expect(page.locator('.boot-cursor')).toBeVisible();
});

test.describe('animations left on', () => {
    for (const theme of THEMES) {
        test(`the effects still run — the switch stops, it does not delete — ${theme}`, async ({ page }) => {
            await open(page, '/fr/', { theme });

            expect(await loops(page)).not.toEqual([]);
        });
    }

    test('the boot sequence plays for a first visit', async ({ page }) => {
        await open(page, '/fr/', { boot: true });

        await expect(page.locator('.boot-overlay')).toBeVisible();
    });
});

test.describe('under a reduced-motion preference, without a choice', () => {
    test.use({ reducedMotion: 'reduce' });

    for (const theme of THEMES) {
        test(`the page arrives still, before any interaction — ${theme}`, async ({ page }) => {
            await open(page, '/fr/', { theme });

            expect(await loops(page)).toEqual([]);
            await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
        });
    }

    test('the boot sequence does not play', async ({ page }) => {
        await open(page, '/fr/', { boot: true });
        await expect(toggle(page)).toBeVisible();

        await expect(page.locator('.boot-overlay')).toHaveCount(0);
    });

    test('the visitor can turn animations back on, and the choice wins over the preference', async ({ page }) => {
        // What "on" looks like: the same page, without the preference and without a choice.
        const reference = await page.context().newPage();
        await reference.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
        await reference.emulateMedia({ reducedMotion: 'no-preference' });
        await reference.goto('/fr/');
        const all = await sortedLoops(reference);
        expect(all).not.toEqual([]);
        await reference.close();

        await open(page, '/fr/');
        await toggle(page).click();

        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');
        await expect.poll(() => sortedLoops(page)).toEqual(all);
        await expect.poll(() => snowFrames(page)).toBeGreaterThan(2);
    });
});

test.describe('the choice', () => {
    test('stops everything at once, and starts it again', async ({ page }) => {
        await open(page, '/fr/');
        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');
        const all = await sortedLoops(page);
        expect(all).not.toEqual([]);

        await toggle(page).click();

        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
        await expect.poll(() => loops(page)).toEqual([]);
        const stopped = await snowFrames(page);
        await page.waitForTimeout(WAIT);
        expect(await snowFrames(page)).toBe(stopped);

        await toggle(page).click();

        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');
        await expect.poll(() => sortedLoops(page)).toEqual(all);
        await expect.poll(() => snowFrames(page)).toBeGreaterThan(stopped + 2);
    });

    test('survives a reload, and a page of another layout', async ({ page }) => {
        await open(page, '/fr/');
        await toggle(page).click();
        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');

        await page.reload();
        await expect(page.locator('html')).toHaveAttribute('data-motion', 'off');
        expect(await loops(page)).toEqual([]);

        await page.goto('/fr/login');
        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
        expect(await loops(page)).toEqual([]);
    });

    test('made in one tab, is followed by the others', async ({ page, context }) => {
        await open(page, '/fr/');
        const other = await context.newPage();
        await other.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
        await other.goto('/fr/');
        await expect(toggle(other)).toHaveAttribute('aria-pressed', 'true');

        await toggle(page).click();

        await expect(toggle(other)).toHaveAttribute('aria-pressed', 'false');
        await expect(other.locator('html')).toHaveAttribute('data-motion', 'off');
        await expect.poll(() => loops(other)).toEqual([]);
    });
});

test.describe('a preference that changes during the visit', () => {
    test('is followed when the visitor made no choice', async ({ page }) => {
        await open(page, '/fr/');
        const all = await sortedLoops(page);
        expect(all).not.toEqual([]);

        await page.emulateMedia({ reducedMotion: 'reduce' });

        await expect.poll(() => loops(page)).toEqual([]);
        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');

        await page.emulateMedia({ reducedMotion: 'no-preference' });

        await expect.poll(() => sortedLoops(page)).toEqual(all);
        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');
    });

    test('does not override a choice', async ({ page }) => {
        await open(page, '/fr/', { motion: 'on' });
        const all = await sortedLoops(page);
        expect(all).not.toEqual([]);

        await page.emulateMedia({ reducedMotion: 'reduce' });

        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');
        expect(await sortedLoops(page)).toEqual(all);
    });
});

test.describe('the switch itself', () => {
    test('is a toggle button named for what it controls', async ({ page }) => {
        await open(page, '/fr/');

        const button = page.getByRole('button', { name: 'Animations', exact: true });
        await expect(button).toHaveAttribute('aria-pressed', 'true');

        await button.click();

        // The name does not change with the state: aria-pressed carries it.
        await expect(page.getByRole('button', { name: 'Animations', exact: true })).toHaveAttribute(
            'aria-pressed',
            'false',
        );
    });

    test('is named in the language of the page', async ({ page }) => {
        await open(page, '/de/');

        await expect(page.getByRole('button', { name: 'Animationen', exact: true })).toBeVisible();
    });

    test('works from the keyboard, and comes before the languages', async ({ page }) => {
        await open(page, '/fr/');

        const reached = [];
        for (let step = 0; step < 15; step++) {
            await page.keyboard.press('Tab');
            const focused = await page.evaluate(() => ({
                toggle: document.activeElement?.hasAttribute('data-motion-toggle') ?? false,
                languages: document.activeElement?.closest('#language-switcher') !== null,
            }));
            reached.push(focused);
            if (focused.toggle) break;
        }

        expect(reached.at(-1).toggle).toBe(true);
        expect(reached.some((focused) => focused.languages)).toBe(false);

        await page.keyboard.press('Enter');
        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
        await page.keyboard.press('Space');
        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');
    });

    test('stays visible and named on a phone', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 740 });
        await open(page, '/fr/');

        await expect(page.getByRole('button', { name: 'Animations', exact: true })).toBeVisible();
    });

    test('still works when the browser refuses to store the choice', async ({ page }) => {
        await page.addInitScript(() => {
            Storage.prototype.setItem = function refuse(key, value) {
                if (key === 'motion') throw new DOMException(`refused ${value}`, 'QuotaExceededError');
            };
        });
        await open(page, '/fr/');

        await toggle(page).click();

        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
        await expect.poll(() => loops(page)).toEqual([]);
    });
});

test.describe('the admin pages', () => {
    test.use({ adminSession: ADMIN_MOTION_SESSION });

    for (const path of ADMIN_PAGES) {
        for (const theme of THEMES) {
            test(`no looping animation runs on ${path} once stopped — ${theme}`, async ({ page }) => {
                await open(page, path, { theme, motion: 'off' });

                expect(await loops(page)).toEqual([]);
            });
        }
    }

    test('carry the switch, and it stops them', async ({ page }) => {
        await open(page, '/fr/admin');
        expect(await loops(page)).not.toEqual([]);

        await toggle(page).click();

        await expect.poll(() => loops(page)).toEqual([]);
    });
});

test.describe('without JavaScript', () => {
    // The stylesheet applies the rule by itself: a page whose script never runs still follows the
    // preference. No island, no anti-FOUC script — the media query alone.
    test.use({ javaScriptEnabled: false });

    test('the preference for reduced motion stops everything', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.goto('/fr/');

        expect(await loops(page)).toEqual([]);
    });

    test('without the preference, the effects run', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await page.goto('/fr/');

        expect(await loops(page)).toEqual(expect.arrayContaining(['crt-flicker', 'neon-pulse', 'glitch-red']));
    });
});

test.describe('a stored value that is no choice', () => {
    // The anti-FOUC script copies it as stored. The stylesheet and utils/motion.js must both read it
    // as no choice — a seam between CSS and JavaScript that neither side's own tests can see.
    test('lets a preference for reduced motion stop everything, and the switch says so', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await open(page, '/fr/', { motion: 'paused' });

        await expect(page.locator('html')).toHaveAttribute('data-motion', 'paused');
        expect(await loops(page)).toEqual([]);
        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
    });

    test('lets everything run without the preference, and the switch says so', async ({ page }) => {
        const reference = await page.context().newPage();
        await reference.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
        await reference.goto('/fr/');
        const all = await sortedLoops(reference);
        await reference.close();

        await open(page, '/fr/', { motion: 'paused' });

        await expect(page.locator('html')).toHaveAttribute('data-motion', 'paused');
        expect(await sortedLoops(page)).toEqual(all);
        await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');
    });
});

test('a storage that cannot be read: the page shows in its default theme, and the switch works', async ({ page }) => {
    // Blocked site data makes reading storage throw. The anti-FOUC script must not take the page down
    // with it, nor leave the switch unable to stop the animations.
    await page.addInitScript(() => {
        // The script's own failure would change nothing visible — the defaults are what it falls
        // back on. What it must not do is throw at the page: that is what its try/catch prevents.
        // Each refusal carries the line that read storage, so that an uncaught one is traced to
        // the inline script and not to a module: ThemeSwitcher.vue and BootSequence.vue throw here
        // too, unguarded — LUMN-124.
        Storage.prototype.getItem = function refuse() {
            const error = new DOMException('blocked', 'SecurityError');
            error.readBy = new Error().stack.split('\n')[2] ?? '';
            throw error;
        };
        window.inlineErrors = [];
        window.addEventListener('error', (event) => {
            const reader = event.error?.readBy ?? '';
            if (!/\.(vue|js)\b/.test(reader)) window.inlineErrors.push(reader);
        });
    });
    await page.goto('/fr/');

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'sprawl');
    await expect(page.locator('html')).not.toHaveAttribute('data-motion', /.*/);
    await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');

    await toggle(page).click();

    await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
    await expect.poll(() => loops(page)).toEqual([]);
    expect(await page.evaluate(() => window.inlineErrors)).toEqual([]);
});
