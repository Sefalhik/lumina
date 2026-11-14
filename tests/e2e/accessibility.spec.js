import { test, expect } from './helpers/test.js';
import AxeBuilder from '@axe-core/playwright';
import { ADMIN_A11Y_SESSION } from './helpers/auth.js';

const THEMES = ['sprawl', 'steampunk', 'neon-noir'];

// Each scan arrives already in its theme, stored before the page loads — as a returning visitor
// does. Switching the theme after load made axe measure colours in the middle of a transition: the
// admin cards animate everything for 300 ms (`transition-all`), and the steampunk scan read a card
// still half Sprawl, at 4.33:1 — under load only (found on LUMN-103).
function axeCheck(page) {
    return new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        // Purely decorative elements (aria-hidden, data-a11y-role="decorative") are WCAG-exempt
        // from contrast requirements. axe-core still scans visually-rendered elements regardless
        // of aria-hidden, so we exclude them explicitly.
        .exclude('[data-a11y-role="decorative"]')
        .analyze();
}

function formatViolations(results) {
    return results.violations.flatMap((v) =>
        v.nodes.map((n) => ({
            rule: v.id,
            impact: v.impact,
            element: n.html.substring(0, 150),
            fix: n.failureSummary,
        })),
    );
}

test.describe('Accessibility — home page', () => {
    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
            await page.addInitScript((chosen) => localStorage.setItem('theme', chosen), theme);
            await page.goto('/fr/');
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});

test('Sprawl — axe gives a contrast verdict on every text of the page, snow behind it', async ({ page }) => {
    // LUMN-103, criterion 3. A background drawn behind text can turn axe's contrast check from a
    // verdict into "incomplete", which silently removes the check instead of failing it. Measured
    // on 2026-11-12: the snow's canvas leaves the verdicts as they were. This keeps it so.
    await page.addInitScript(() => {
        sessionStorage.setItem('boot_sequence_played', '1');
        localStorage.setItem('theme', 'sprawl');
    });
    await page.goto('/fr/');
    await expect
        .poll(async () => Number(await page.locator('canvas.dead-channel').getAttribute('data-frames')))
        .toBeGreaterThan(0);

    const results = await new AxeBuilder({ page })
        .withRules(['color-contrast'])
        .exclude('[data-a11y-role="decorative"]')
        .analyze();
    const targets = (list) => list.flatMap((rule) => rule.nodes.map((node) => node.target.join(' ')));

    expect(targets(results.violations)).toEqual([]);
    // No contrast left undecided anywhere on the page. Before LUMN-103 the hero's primary button was
    // "incomplete": DaisyUI's invisible grain image hid its background from axe.
    expect(targets(results.incomplete)).toEqual([]);

    // The hero's texts are among those judged. axe names elements its own way, so each target is
    // resolved in the page and compared with the elements themselves, never as a string.
    const unjudged = await page.evaluate(
        ({ passed, wanted }) => {
            const judged = new Set(passed.flatMap((selector) => [...document.querySelectorAll(selector)]));
            return wanted.flatMap((selector) =>
                [...document.querySelectorAll(selector)].filter((element) => !judged.has(element)).map(() => selector),
            );
        },
        { passed: targets(results.passes), wanted: ['h1 > span', '[data-smoke="bio"]', '#hero-profiles > a'] },
    );
    expect(unjudged).toEqual([]);
});

test.describe('Sprawl — a hovered or focused control keeps its contrast', () => {
    // Every other scan sees the page at rest. Under a reduced-motion preference, the intrusion's
    // two colours stay around the text for as long as the hover or the focus lasts (LUMN-103): that
    // state is scanned here, with a verdict required — a text-shadow can make axe give up.
    test.use({ reducedMotion: 'reduce' });

    for (const [state, selector] of [
        ['hovered nav link', 'header nav a'],
        ['hovered hero button', '#hero-profiles > a'],
        ['focused footer link', 'footer nav a'],
    ]) {
        test(`${state}`, async ({ page }) => {
            await page.addInitScript(() => {
                sessionStorage.setItem('boot_sequence_played', '1');
                localStorage.setItem('theme', 'sprawl');
            });
            await page.goto('/fr/');
            await expect(page.locator('html')).toHaveAttribute('data-theme', 'sprawl');

            const control = page.locator(selector).first();
            await control.evaluate((element) => element.setAttribute('data-scanned', ''));
            const resting = await control.evaluate((element) => getComputedStyle(element).textShadow);
            if (state.startsWith('focused')) {
                await page.keyboard.press('Tab');
                await control.focus();
            } else {
                await control.hover();
            }
            await expect
                .poll(() => control.evaluate((element) => getComputedStyle(element).textShadow))
                .not.toBe(resting);

            const results = await new AxeBuilder({ page })
                .include('[data-scanned]')
                .withRules(['color-contrast'])
                .exclude('[data-a11y-role="decorative"]')
                .analyze();
            const targets = (list) => list.flatMap((rule) => rule.nodes.map((node) => node.target.join(' ')));

            expect(targets(results.violations)).toEqual([]);
            expect(targets(results.incomplete)).toEqual([]);
            expect(targets(results.passes).length).toBeGreaterThan(0);
        });
    }
});

test.describe('Accessibility — home page, mobile menu open', () => {
    // The desktop scan never sees the mobile menu: it is display:none above the
    // md breakpoint. Here it is open, so axe checks its links in every theme.
    test.use({ viewport: { width: 390, height: 844 } });

    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
            await page.addInitScript((chosen) => localStorage.setItem('theme', chosen), theme);
            await page.goto('/fr/');
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);

            await page.getByRole('button', { name: 'Menu' }).click();
            await expect(page.locator('#mobile-menu a').first()).toBeVisible();

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});

test.describe('Accessibility — CV page', () => {
    // E2eExperienceSeeder guarantees a seeded position, so the scan sees the
    // real timeline markup — period, "still there" badge, headings — rather than
    // the empty state, which would pass while checking nothing.
    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
            await page.addInitScript((chosen) => localStorage.setItem('theme', chosen), theme);
            await page.goto('/fr/cv');
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});

// Animations stopped (LUMN-11): the switch in its other state, and every glow frozen at the pulse's
// brightest moment — the state in which a glowing text's contrast is lowest. The scans above meet the
// pulse wherever it happens to be.
test.describe('Accessibility — animations stopped', () => {
    const PAGES = [
        ['home page', '/fr/'],
        ['login page', '/fr/login'],
    ];

    for (const [name, path] of PAGES) {
        for (const theme of THEMES) {
            test(`WCAG 2.1 AA — ${name}, theme: ${theme}`, async ({ page }) => {
                await page.addInitScript((chosen) => {
                    sessionStorage.setItem('boot_sequence_played', '1');
                    localStorage.setItem('theme', chosen);
                    localStorage.setItem('motion', 'off');
                }, theme);
                await page.goto(path);
                await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
                await expect(page.locator('[data-motion-toggle]')).toHaveAttribute('aria-pressed', 'false');

                expect(formatViolations(await axeCheck(page))).toEqual([]);
            });
        }
    }
});

test.describe('Accessibility — admin dashboard, animations stopped', () => {
    test.use({ adminSession: ADMIN_A11Y_SESSION });

    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.addInitScript((chosen) => {
                localStorage.setItem('theme', chosen);
                localStorage.setItem('motion', 'off');
            }, theme);
            await page.goto('/fr/admin');
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
            await expect(page.locator('[data-motion-toggle]')).toHaveAttribute('aria-pressed', 'false');

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});

test.describe('Accessibility — login page', () => {
    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.addInitScript((chosen) => localStorage.setItem('theme', chosen), theme);
            await page.goto('/fr/login');
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});

test.describe('Accessibility — 2FA challenge page', () => {
    test.use({ adminSession: ADMIN_A11Y_SESSION });

    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.addInitScript((chosen) => localStorage.setItem('theme', chosen), theme);
            await page.goto('/fr/two-factor/challenge');
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});

test.describe('Accessibility — admin dashboard', () => {
    test.use({ adminSession: ADMIN_A11Y_SESSION });

    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.addInitScript((chosen) => localStorage.setItem('theme', chosen), theme);
            await page.goto('/fr/admin');
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});

test.describe('Accessibility — admin homepage edit', () => {
    test.use({ adminSession: ADMIN_A11Y_SESSION });

    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.addInitScript((chosen) => localStorage.setItem('theme', chosen), theme);
            await page.goto('/fr/admin/homepage');
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});

test.describe('Accessibility — admin experiences list', () => {
    test.use({ adminSession: ADMIN_A11Y_SESSION });

    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.addInitScript((chosen) => localStorage.setItem('theme', chosen), theme);
            await page.goto('/fr/admin/experiences');
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});

test.describe('Accessibility — admin experience form', () => {
    test.use({ adminSession: ADMIN_A11Y_SESSION });

    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.addInitScript((chosen) => localStorage.setItem('theme', chosen), theme);
            await page.goto('/fr/admin/experiences/create');
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});

test.describe('Accessibility — admin site identity edit', () => {
    test.use({ adminSession: ADMIN_A11Y_SESSION });

    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.addInitScript((chosen) => localStorage.setItem('theme', chosen), theme);
            await page.goto('/fr/admin/identity');
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});
