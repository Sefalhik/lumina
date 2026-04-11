import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test.describe('Accessibility — home page', () => {
    for (const theme of ['sprawl', 'steampunk', 'neon-noir']) {
        test(`WCAG 2.1 AA — theme: ${theme}`, async ({ page }) => {
            await page.goto('/');
            await page.evaluate((t) => {
                document.documentElement.setAttribute('data-theme', t);
            }, theme);

            const results = await new AxeBuilder({ page })
                .withTags(['wcag2a', 'wcag2aa'])
                .analyze();

            expect(
                results.violations.flatMap((v) =>
                    v.nodes.map((n) => ({
                        rule: v.id,
                        impact: v.impact,
                        element: n.html.substring(0, 150),
                        fix: n.failureSummary,
                    })),
                ),
            ).toEqual([]);
        });
    }
});
