import { test as setup } from '@playwright/test';
import { ADMIN_AUTH_FILE } from './helpers/auth.js';

setup('create admin session', async ({ page }) => {
    await page.goto('/e2e/admin-auth');
    await page.waitForURL(/\/fr\/admin$/);
    await page.context().storageState({ path: ADMIN_AUTH_FILE });
});
