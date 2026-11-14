import { test, expect } from './helpers/test.js';
import { ADMIN_CSP_SESSION } from './helpers/auth.js';

/**
 * The content security policy is sent as Report-Only (LUMN-37): it blocks nothing, so "no page
 * regresses" would be true whatever the policy said. What is checked instead is that the policy
 * reports no violation on the real pages, in every theme — the condition for enforcing it later.
 *
 * Violations are collected from the `securitypolicyviolation` event, which the browser fires for
 * report-only policies too. The listener is installed before any page script runs.
 */

const THEMES = ['sprawl', 'steampunk', 'neon-noir'];

// The 404 page included: an error page renders through the same layout, and is easy to forget.
const PUBLIC_PAGES = ['/fr', '/fr/cv', '/fr/projects', '/fr/blog', '/fr/login', '/fr/lumina-no-such-page'];

const ADMIN_PAGES = [
    '/fr/two-factor/challenge',
    '/fr/admin',
    '/fr/admin/homepage',
    '/fr/admin/identity',
    '/fr/admin/experiences',
    '/fr/admin/experiences/create',
];

async function collectViolations(page, theme, { skipBoot = true } = {}) {
    await page.addInitScript(
        ({ chosenTheme, skip }) => {
            window.__cspViolations = [];
            document.addEventListener('securitypolicyviolation', (event) => {
                window.__cspViolations.push({
                    directive: event.violatedDirective,
                    blocked: event.blockedURI,
                    source: `${event.sourceFile}:${event.lineNumber}`,
                    sample: event.sample,
                });
            });
            // Through the same path a returning visitor takes: the anti-flash script reads it
            // before the first paint, so the theme's fonts and styles load as they would for real.
            localStorage.setItem('theme', chosenTheme);
            if (skip) {
                sessionStorage.setItem('boot_sequence_played', '1');
            }
        },
        { chosenTheme: theme, skip: skipBoot },
    );
}

/**
 * The one violation tolerated, named and counted rather than filtered by kind.
 *
 * vuedraggable bundles core-js's old global-object detection, `new Function("return this")()`,
 * inside a try/catch: under an enforced policy it is refused and falls back to `window`, and nothing
 * breaks. It runs once, when the bundle is evaluated. Removing vuedraggable — LUMN-90 — removes it;
 * this tolerance goes with it. Any second eval on a page is reported like any other violation.
 */
function isToleratedEvalProbe(violation) {
    return violation.directive === 'script-src' && violation.blocked === 'eval';
}

async function violationsOn(page, url) {
    // The fonts are served by the site (LUMN-102): a request to Google's font hosts would send the
    // visitor's address there. Checked on the network, since a Report-Only policy lets it through.
    const googleFontRequests = [];
    page.on('request', (request) => {
        if (/^fonts\.(googleapis|gstatic)\.com$/.test(new URL(request.url()).hostname)) {
            googleFontRequests.push(request.url());
        }
    });

    const response = await page.goto(url);
    await page.waitForLoadState('networkidle');
    expect(googleFontRequests, 'a page loads a font from Google').toEqual([]);

    // The check is only meaningful while the policy is actually sent.
    expect(response.headers()['content-security-policy-report-only']).toContain("'nonce-");

    const violations = await page.evaluate(() => window.__cspViolations);
    const probes = violations.filter(isToleratedEvalProbe);
    expect(probes.length, 'vuedraggable evaluates its probe once per page load (LUMN-90)').toBeLessThanOrEqual(1);

    return violations.filter((violation) => !isToleratedEvalProbe(violation));
}

test.describe('Content security policy — public pages', () => {
    for (const theme of THEMES) {
        for (const url of PUBLIC_PAGES) {
            test(`no violation on ${url} — theme: ${theme}`, async ({ page }) => {
                await collectViolations(page, theme);

                expect(await violationsOn(page, url)).toEqual([]);
            });
        }

        test(`no violation while the boot sequence plays — theme: ${theme}`, async ({ page }) => {
            // The boot overlay animates and writes its own lines: it is skipped everywhere else.
            await collectViolations(page, theme, { skipBoot: false });

            expect(await violationsOn(page, '/fr')).toEqual([]);
        });
    }
});

test.describe('Content security policy — administration', () => {
    test.use({ adminSession: ADMIN_CSP_SESSION });

    for (const theme of THEMES) {
        for (const url of ADMIN_PAGES) {
            test(`no violation on ${url} — theme: ${theme}`, async ({ page }) => {
                await collectViolations(page, theme);

                expect(await violationsOn(page, url)).toEqual([]);
            });
        }
    }
});

test.describe('Content security policy — the check itself', () => {
    test('an inline script without the nonce is reported', async ({ page }) => {
        // Negative control: without it, a listener that never fires would make every test above
        // pass while checking nothing.
        await collectViolations(page, 'sprawl');
        await page.goto('/fr');
        await page.waitForLoadState('networkidle');

        await page.evaluate(() => {
            const script = document.createElement('script');
            script.textContent = 'window.__ranWithoutNonce = true;';
            document.body.appendChild(script);
        });
        await expect.poll(() => page.evaluate(() => window.__cspViolations.length)).toBeGreaterThan(0);

        // Report-Only: the browser reported it, and still ran it.
        expect(await page.evaluate(() => window.__ranWithoutNonce)).toBe(true);
    });
});
