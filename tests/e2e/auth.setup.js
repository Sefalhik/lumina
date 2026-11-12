import { test as setup } from './helpers/test.js';
import { ADMIN_SESSIONS } from './helpers/auth.js';
import { sessionFileFor, slots, urlFor } from './helpers/workers.js';

/**
 * Creates one isolated server-side admin session per group of specs, on every worker slot's server.
 *
 * Separate browser contexts are not enough: two contexts built from the same
 * storageState send the same laravel_session cookie and therefore share one
 * server-side session. Distinct auth files mean distinct cookies, hence fully
 * isolated flash-message stores.
 *
 * Each slot has its own database (LUMN-111), and a session exists only in the database that
 * created it: every group therefore gets one session per slot, and helpers/test.js hands each test
 * the one of its own slot. See helpers/auth.js for why each group needs its own.
 */
/**
 * The slots are prepared in parallel, and each slot's sessions one after the other. Parallel across
 * slots, because every slot has its own server and database. In series within a slot, because
 * `php artisan serve` handles one request at a time — nothing to gain — and /e2e/admin-auth runs
 * User::firstOrCreate: two concurrent calls on one database could both try to create the admin.
 */
setup('create admin sessions on every slot', async ({ browser }) => {
    await Promise.all(
        slots().map(async (slot) => {
            for (const group of ADMIN_SESSIONS) {
                // An explicit baseURL: this setup runs in one worker and prepares all of them.
                const context = await browser.newContext({ baseURL: urlFor(slot) });
                const page = await context.newPage();

                await page.goto('/e2e/admin-auth');
                await page.waitForURL(/\/fr\/admin$/);
                await context.storageState({ path: sessionFileFor(group, slot) });
                await context.close();
            }
        }),
    );
});
