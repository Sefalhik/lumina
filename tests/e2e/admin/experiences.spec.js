import { test, expect } from '@playwright/test';
import { ADMIN_EXPERIENCES_AUTH_FILE } from '../helpers/auth.js';

/**
 * The CV timeline, driven through the real admin screens.
 *
 * Serial: every test here submits a form, and flash messages are consumed by the
 * next page load on the same server-side session regardless of browser context.
 */
test.describe.configure({ mode: 'serial' });

test.use({ storageState: ADMIN_EXPERIENCES_AUTH_FILE });

/**
 * Values must not collide with anything else rendered on the page — the seeded
 * row, the footer, the home page fallbacks. A realistic value is comfortable to
 * read and dangerous to assert on.
 */
const EMPLOYER = 'Playwright Holdings';
const JOB_TITLE = 'Distinguished Operator';

/**
 * The seeded row (E2eExperienceSeeder) is what gives the axe-core scan of /cv
 * real timeline markup to inspect. Deleting it would leave the scan looking at
 * the empty state — green, and checking nothing.
 */
const SEEDED_EMPLOYER = 'E2E Test Employer';

async function fillForm(page, { employer, jobTitle, startedAt, endedAt = '' }) {
    await page.locator('#employer').fill(employer);
    await page.locator('#job_title').fill(jobTitle);
    await page.locator('#started_at').fill(startedAt);
    await page.locator('#ended_at').fill(endedAt);
}

test('the list is reachable from the dashboard', async ({ page }) => {
    await page.goto('/fr/admin');
    await page.getByRole('link', { name: /parcours professionnel/i }).click();

    await expect(page).toHaveURL(/\/fr\/admin\/experiences$/);
});

test('creating an experience publishes it on the CV page', async ({ page }) => {
    await page.goto('/fr/admin/experiences/create');

    await fillForm(page, {
        employer: EMPLOYER,
        jobTitle: JOB_TITLE,
        startedAt: '2022-06-01',
    });
    await page.locator('#description').fill('Pilotage de la suite de bout en bout.');

    await page.getByRole('button', { name: /enregistrer/i }).click();
    await page.waitForURL('**/fr/admin/experiences');

    await expect(page.getByText(/expérience ajoutée/i)).toBeVisible();
    await expect(page.getByText(EMPLOYER)).toBeVisible();

    // The point of the whole ticket: it reaches the public page.
    await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
    await page.goto('/fr/cv');

    await expect(page.getByText(JOB_TITLE)).toBeVisible();
    await expect(page.getByText('Pilotage de la suite de bout en bout.')).toBeVisible();
});

test('an open-ended position is shown as current', async ({ page }) => {
    await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
    await page.goto('/fr/cv');

    // Scoped to the list item so the assertion cannot be satisfied by another
    // position's badge.
    const entry = page.getByRole('listitem').filter({ hasText: JOB_TITLE });

    await expect(entry.getByText(/en poste/i)).toBeVisible();
    await expect(entry.getByText(/aujourd/i)).toBeVisible();
});

test('setting an end date removes the current marker', async ({ page }) => {
    await page.goto('/fr/admin/experiences');
    await page
        .getByRole('listitem')
        .filter({ hasText: EMPLOYER })
        .getByRole('link', { name: /modifier/i })
        .click();

    await page.locator('#ended_at').fill('2024-03-31');
    await page.getByRole('button', { name: /enregistrer/i }).click();
    await page.waitForURL('**/fr/admin/experiences');

    await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
    await page.goto('/fr/cv');

    const entry = page.getByRole('listitem').filter({ hasText: JOB_TITLE });

    await expect(entry).toBeVisible();
    await expect(entry.getByText(/en poste/i)).toHaveCount(0);
});

test('a server-side rejection is shown to the user', async ({ page }) => {
    await page.goto('/fr/admin/experiences/create');

    // The browser accepts both dates; only the server knows the order is wrong.
    await fillForm(page, {
        employer: EMPLOYER,
        jobTitle: JOB_TITLE,
        startedAt: '2024-04-01',
        endedAt: '2023-01-01',
    });

    await page.getByRole('button', { name: /enregistrer/i }).click();

    await expect(page.getByText(/date de fin ne peut pas précéder/i)).toBeVisible();
});

test('deleting an experience removes it from the CV page', async ({ page }) => {
    await page.goto('/fr/admin/experiences');

    await page
        .getByRole('listitem')
        .filter({ hasText: EMPLOYER })
        .getByRole('button', { name: /supprimer/i })
        .click();

    await page.waitForURL('**/fr/admin/experiences');
    await expect(page.getByText(/expérience supprimée/i)).toBeVisible();

    // The seeded row stays: it is what the axe-core scan of /cv inspects.
    await expect(page.getByText(SEEDED_EMPLOYER)).toBeVisible();

    await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
    await page.goto('/fr/cv');

    await expect(page.getByText(JOB_TITLE)).toHaveCount(0);
    await expect(page.getByText('E2E Test Position')).toBeVisible();
});
