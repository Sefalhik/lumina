import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { ADMIN_AUTH_FILE } from './helpers/auth.js';

const THEMES = ['sprawl', 'steampunk', 'neon-noir'];

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
            await page.goto('/fr/');
            await page.evaluate((t) => {
                document.documentElement.setAttribute('data-theme', t);
            }, theme);

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});

test.describe('Accessibility — login page', () => {
    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.goto('/fr/login');
            await page.evaluate((t) => {
                document.documentElement.setAttribute('data-theme', t);
            }, theme);

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});

test.describe('Accessibility — 2FA challenge page', () => {
    test.use({ storageState: ADMIN_AUTH_FILE });

    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.goto('/fr/two-factor/challenge');
            await page.evaluate((t) => {
                document.documentElement.setAttribute('data-theme', t);
            }, theme);

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});

test.describe('Accessibility — admin dashboard', () => {
    test.use({ storageState: ADMIN_AUTH_FILE });

    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.goto('/fr/admin');
            await page.evaluate((t) => {
                document.documentElement.setAttribute('data-theme', t);
            }, theme);

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});

test.describe('Accessibility — admin homepage edit', () => {
    test.use({ storageState: ADMIN_AUTH_FILE });

    for (const theme of THEMES) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.goto('/fr/admin/homepage');
            await page.evaluate((t) => {
                document.documentElement.setAttribute('data-theme', t);
            }, theme);

            expect(formatViolations(await axeCheck(page))).toEqual([]);
        });
    }
});
