import { test as setup } from '@playwright/test';
import {
    ADMIN_AUTH_FILE,
    ADMIN_EDITOR_AUTH_FILE,
    ADMIN_A11Y_AUTH_FILE,
    ADMIN_IDENTITY_AUTH_FILE,
} from './helpers/auth.js';

/**
 * Creates one isolated server-side admin session per group of specs.
 *
 * Separate browser contexts are not enough: two contexts built from the same
 * storageState send the same laravel_session cookie and therefore share one
 * server-side session. Distinct auth files mean distinct cookies, hence fully
 * isolated flash-message stores.
 *
 * See helpers/auth.js for why each group needs its own.
 */
const SESSIONS = [
    ADMIN_AUTH_FILE, // general admin specs — homepage form
    ADMIN_EDITOR_AUTH_FILE, // skills editor
    ADMIN_IDENTITY_AUTH_FILE, // site identity form
    ADMIN_A11Y_AUTH_FILE, // axe-core scans — submit nothing, but still consume flashes
];

setup('create admin sessions', async ({ browser }) => {
    for (const file of SESSIONS) {
        const context = await browser.newContext();
        const page = await context.newPage();

        await page.goto('/e2e/admin-auth');
        await page.waitForURL(/\/fr\/admin$/);
        await context.storageState({ path: file });
        await context.close();
    }
});
