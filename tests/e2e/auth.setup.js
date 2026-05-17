import { test as setup } from '@playwright/test';
import { ADMIN_AUTH_FILE, ADMIN_EDITOR_AUTH_FILE } from './helpers/auth.js';

setup('create admin session', async ({ page, browser: b }) => {
    await page.goto('/e2e/admin-auth');
    await page.waitForURL(/\/fr\/admin$/);
    await page.context().storageState({ path: ADMIN_AUTH_FILE });

    // Second isolated session for skills-editor tests — prevents flash-message
    // race conditions when both admin specs run in parallel (same session cookie
    // on the server would cause one spec to consume the other's flash).
    const ctx = await b.newContext();
    const page2 = await ctx.newPage();
    await page2.goto('/e2e/admin-auth');
    await page2.waitForURL(/\/fr\/admin$/);
    await ctx.storageState({ path: ADMIN_EDITOR_AUTH_FILE });
    await ctx.close();
});
