import { test, expect } from '@playwright/test';
import { ADMIN_EDITOR_AUTH_FILE } from '../helpers/auth.js';

test.describe('Admin — SkillsEditor', () => {
    // Serial mode: saves in later tests accumulate in the DB across the suite.
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: ADMIN_EDITOR_AUTH_FILE });

    let originalContent;

    test.beforeAll(async ({ request }) => {
        const res = await request.get('/e2e/homepage-content');
        originalContent = await res.json();
    });

    test.afterAll(async ({ request }) => {
        await request.post('/e2e/homepage-content', { data: originalContent });
    });

    test.beforeEach(async ({ page }) => {
        await page.goto('/fr/admin/homepage');
    });

    // ── Helpers ───────────────────────────────────────────────────────────────

    const editor      = (page)       => page.locator('#skills-editor');
    const nameInput   = (page, n)    => page.getByRole('textbox',  { name: `Nom de la catégorie ${n}` });
    const techInput   = (page, t, n) => page.getByRole('textbox',  { name: `Technologie ${t} de la catégorie ${n}` });
    const iconSelect  = (page, n)    => page.getByRole('combobox', { name: `Icône de la catégorie ${n}` });
    const addCategory = (page)       => page.getByRole('button',   { name: '+ Ajouter une catégorie' });
    const addTech     = (page)       => page.getByRole('button',   { name: '+ Ajouter une techno' });
    const rmCategory  = (page, n)    => page.getByRole('button',   { name: `Supprimer la catégorie ${n}` });
    const rmTech      = (page, t)    => page.getByRole('button',   { name: `Supprimer la technologie ${t}` });
    const saveBtn     = (page)       => page.getByRole('button',   { name: /enregistrer/i });
    const errorBox    = (page)       => page.locator('[class*="border-error/40"]');
    const successBox  = (page)       => page.locator('[class*="border-primary/40"]').filter({ hasText: /sauvegardé/i });

    // Returns the current number of category rows rendered in the editor.
    const catCount    = (page)       => page.getByRole('button', { name: /supprimer la catégorie/i }).count();

    // Fills all required text fields so that the form is valid except for skills.
    async function fillRequiredFields(page) {
        await page.locator('#tagline_fr').fill('Accroche de test');
        await page.locator('#subtitle_fr').fill('Sous-titre de test');
        await page.locator('#bio_fr').fill('Bio de test.');
        await page.locator('#meta_description_fr').fill('Description SEO de test.');
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    test('renders the LIST_ header', async ({ page }) => {
        await expect(editor(page)).toContainText('LIST_');
    });

    // ── Categories ────────────────────────────────────────────────────────────

    test('add category button shows a new category row', async ({ page }) => {
        const before = await catCount(page);
        await addCategory(page).click();
        await expect(nameInput(page, before + 1)).toBeVisible();
    });

    test('new category name input starts with border-error', async ({ page }) => {
        const before = await catCount(page);
        await addCategory(page).click();
        await expect(nameInput(page, before + 1)).toHaveClass(/border-error/);
    });

    test('filling the category name removes border-error', async ({ page }) => {
        const before = await catCount(page);
        await addCategory(page).click();
        await nameInput(page, before + 1).fill('Backend');
        await expect(nameInput(page, before + 1)).not.toHaveClass(/border-error/);
    });

    test('remove category button removes the row', async ({ page }) => {
        const before = await catCount(page);
        await addCategory(page).click();
        await nameInput(page, before + 1).fill('Backend');
        await rmCategory(page, before + 1).click();
        await expect(nameInput(page, before + 1)).not.toBeVisible();
    });

    // ── Techs ─────────────────────────────────────────────────────────────────

    test('add tech button shows a new tech input', async ({ page }) => {
        const before = await catCount(page);
        await addCategory(page).click();
        await nameInput(page, before + 1).fill('Backend');
        await addTech(page).last().click();
        await expect(techInput(page, 1, before + 1)).toBeVisible();
    });

    test('new tech input starts with border-error', async ({ page }) => {
        const before = await catCount(page);
        await addCategory(page).click();
        await nameInput(page, before + 1).fill('Backend');
        await addTech(page).last().click();
        await expect(techInput(page, 1, before + 1)).toHaveClass(/border-error/);
    });

    test('filling the tech name removes border-error', async ({ page }) => {
        const before = await catCount(page);
        await addCategory(page).click();
        await nameInput(page, before + 1).fill('Backend');
        await addTech(page).last().click();
        await techInput(page, 1, before + 1).fill('PHP');
        await expect(techInput(page, 1, before + 1)).not.toHaveClass(/border-error/);
    });

    test('remove tech button removes the tech input', async ({ page }) => {
        const before = await catCount(page);
        await addCategory(page).click();
        await nameInput(page, before + 1).fill('Backend');
        await addTech(page).last().click();
        await rmTech(page, 1).click();
        await expect(techInput(page, 1, before + 1)).not.toBeVisible();
    });

    // ── Line numbers ──────────────────────────────────────────────────────────

    test('categories are numbered with BASIC 10-20 steps', async ({ page }) => {
        const before = await catCount(page);
        await addCategory(page).click();
        await addCategory(page).click();
        await expect(editor(page)).toContainText(`${(before + 1) * 10}:`);
        await expect(editor(page)).toContainText(`${(before + 2) * 10}:`);
    });

    // ── Icon selector ─────────────────────────────────────────────────────────

    test('icon selector allows changing the category icon', async ({ page }) => {
        const before = await catCount(page);
        await addCategory(page).click();
        await iconSelect(page, before + 1).selectOption('◈');
        await expect(iconSelect(page, before + 1)).toHaveValue('◈');
    });

    // ── Server-side validation ────────────────────────────────────────────────

    test('server rejects a category without a name', async ({ page }) => {
        await fillRequiredFields(page);
        await addCategory(page).click();
        // name intentionally left empty — backend ValidSkillsJson must reject it
        await saveBtn(page).click();
        await expect(errorBox(page)).toBeVisible();
    });

    test('server rejects a tech with an empty name', async ({ page }) => {
        const before = await catCount(page);
        await fillRequiredFields(page);
        await addCategory(page).click();
        await nameInput(page, before + 1).fill('Backend');
        await addTech(page).last().click();
        // tech value intentionally left empty
        await saveBtn(page).click();
        await expect(errorBox(page)).toBeVisible();
    });

    // ── Full round-trip ───────────────────────────────────────────────────────

    test('saving a valid category with a tech succeeds', async ({ page }) => {
        const before = await catCount(page);
        await fillRequiredFields(page);
        await addCategory(page).click();
        await nameInput(page, before + 1).fill('Backend');
        await addTech(page).last().click();
        await techInput(page, 1, before + 1).fill('PHP');

        await saveBtn(page).click();
        await page.waitForURL('**/fr/admin/homepage');

        await expect(successBox(page)).toBeVisible();
    });

    test('saved skills are persisted and reloaded on next visit', async ({ page }) => {
        const before = await catCount(page);
        await fillRequiredFields(page);
        await addCategory(page).click();
        await nameInput(page, before + 1).fill('Frontend');

        await saveBtn(page).click();
        await page.waitForURL('**/fr/admin/homepage');

        await page.goto('/fr/admin/homepage');
        await expect(nameInput(page, before + 1)).toHaveValue('Frontend');
    });
});
