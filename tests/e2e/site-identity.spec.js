import { test, expect } from '@playwright/test';
import { ADMIN_IDENTITY_AUTH_FILE } from './helpers/auth.js';

/**
 * Site identity, end to end: the admin form that writes it and the footer that
 * renders it.
 *
 * Both live in ONE file, in serial mode, on purpose. The form specs mutate the
 * single identity row, and that row feeds the footer of every page — split
 * across two files, Playwright would run them in parallel and they would read
 * each other's half-written state.
 *
 * The identity itself is seeded in global-setup.js, which also makes the
 * axe-core scan cover these links on all three themes.
 */

test.describe.configure({ mode: 'serial' });

const NETWORKS = ['GitHub', 'LinkedIn', 'Mastodon'];

// ─── Public footer ───────────────────────────────────────────────────────────

test.describe('Footer — site identity', () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
        await page.goto('/fr/');
    });

    const contactNav = (page) => page.getByRole('navigation', { name: 'Contact' });

    test('every seeded network is linked', async ({ page }) => {
        for (const network of NETWORKS) {
            await expect(
                contactNav(page).getByRole('link', { name: new RegExp(network, 'i') }),
            ).toHaveCount(1);
        }
    });

    test('social links carry rel="me" so Mastodon can verify the site', async ({ page }) => {
        const mastodon = contactNav(page).getByRole('link', { name: /Mastodon/i });

        await expect(mastodon).toHaveAttribute('href', 'https://mastodon.social/@example');
        await expect(mastodon).toHaveAttribute('rel', /\bme\b/);
        await expect(mastodon).toHaveAttribute('target', '_blank');
    });

    test('external links do not leak the opener', async ({ page }) => {
        for (const network of NETWORKS) {
            await expect(
                contactNav(page).getByRole('link', { name: new RegExp(network, 'i') }),
            ).toHaveAttribute('rel', /noopener/);
        }
    });

    test('the contact address is a plain mailto link', async ({ page }) => {
        // Deliberate: obfuscation is defeated by headless scrapers anyway, and
        // a dedicated address on the domain is replaceable if it gets flooded.
        await expect(contactNav(page).getByRole('link', { name: /example\.test/i })).toHaveAttribute(
            'href',
            'mailto:contact@example.test',
        );
    });

    test('each link exposes an accessible name', async ({ page }) => {
        // The bracket-free menu indices must never end up as the accessible
        // name — that is what the explicit aria-label guards against.
        const links = contactNav(page).getByRole('link');
        const count = await links.count();

        expect(count).toBe(NETWORKS.length + 1); // + contact

        for (let i = 0; i < count; i++) {
            const name = await links.nth(i).getAttribute('aria-label');
            expect(name?.trim().length ?? 0).toBeGreaterThan(3);
        }
    });

    test('the identity line shows name and job title', async ({ page }) => {
        const footer = page.locator('footer');

        await expect(footer).toContainText('E2E Test Identity');
        await expect(footer).toContainText('Lead Developer');
    });
});

// ─── Admin form ──────────────────────────────────────────────────────────────

test.describe('Admin — site identity form', () => {
    test.use({ storageState: ADMIN_IDENTITY_AUTH_FILE });

    let original;

    test.beforeAll(async ({ request }) => {
        original = await (await request.get('/e2e/site-identity')).json();
    });

    test.afterEach(async ({ request }) => {
        // Restore after EVERY test, not just at the end: this row drives the
        // footer of the whole site, so it must not stay modified any longer
        // than the test that changed it.
        await request.post('/e2e/site-identity', { data: original });
    });

    test.beforeEach(async ({ page }) => {
        await page.goto('/fr/admin/identity');
    });

    test('the dashboard links to the identity editor', async ({ page }) => {
        await page.goto('/fr/admin');
        await page.getByRole('link', { name: /identité du site/i }).click();

        await expect(page).toHaveURL(/\/fr\/admin\/identity$/);
    });

    test('shows every field of the form', async ({ page }) => {
        for (const id of [
            '#full_name',
            '#job_title_fr',
            '#contact_email',
            '#github_url',
            '#linkedin_url',
            '#mastodon_url',
        ]) {
            await expect(page.locator(id)).toBeVisible();
        }
    });

    test('a valid save shows the success flash', async ({ page }) => {
        await page.locator('#full_name').fill('Laurent Bernard-Cardascia');
        await page.locator('#job_title_fr').fill('Tech Lead');
        await page.locator('#github_url').fill('https://github.com/Sefalhik');

        await page.getByRole('button', { name: /enregistrer/i }).click();
        await page.waitForURL('**/fr/admin/identity');

        await expect(page.getByText(/identité sauvegardée/i)).toBeVisible();
    });

    test('a server-side rejection is actually shown to the user', async ({ page }) => {
        // This URL is valid to the browser, so type="url" lets it through and
        // the request reaches the server. It is the only way to prove the
        // server's own messages are reachable at all — a Feature test asserting
        // on the session could never catch a template that forgot to render them.
        await page.locator('#github_url').fill('https://www.linkedin.com/in/someone/');

        await page.getByRole('button', { name: /enregistrer/i }).click();
        await page.waitForURL('**/fr/admin/identity');

        await expect(page.getByText(/doit pointer vers github\.com/i)).toBeVisible();
    });

    test('a rejected submission keeps what the user typed', async ({ page }) => {
        // Losing a form's contents on a validation error is the classic way to
        // make people give up.
        await page.locator('#full_name').fill('Laurent Bernard-Cardascia');
        await page.locator('#github_url').fill('https://github.com/');

        await page.getByRole('button', { name: /enregistrer/i }).click();
        await page.waitForURL('**/fr/admin/identity');

        await expect(page.locator('#full_name')).toHaveValue('Laurent Bernard-Cardascia');
        await expect(page.locator('#github_url')).toHaveValue('https://github.com/');
    });

    test('saving from the admin updates the public footer', async ({ page }) => {
        // The whole chain in one test: form → database → every public page.
        // Nothing else covers it end to end.
        await page.locator('#full_name').fill('Identité Modifiée');
        await page.locator('#mastodon_url').fill('https://piaille.fr/@quelquun');

        await page.getByRole('button', { name: /enregistrer/i }).click();
        await page.waitForURL('**/fr/admin/identity');

        await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
        await page.goto('/fr/');

        const footer = page.locator('footer');
        await expect(footer).toContainText('Identité Modifiée');
        await expect(
            footer.getByRole('link', { name: /Mastodon/i }),
        ).toHaveAttribute('href', 'https://piaille.fr/@quelquun');
    });

    test('clearing a field removes it from the footer', async ({ page }) => {
        await page.locator('#mastodon_url').fill('');

        await page.getByRole('button', { name: /enregistrer/i }).click();
        await page.waitForURL('**/fr/admin/identity');

        await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
        await page.goto('/fr/');

        await expect(
            page.locator('footer').getByRole('link', { name: /Mastodon/i }),
        ).toHaveCount(0);
    });
});
