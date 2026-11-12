import { test as base, expect } from '@playwright/test';
import { sessionFileFor, urlFor } from './workers.js';

/**
 * The `test` every spec imports, instead of @playwright/test's (LUMN-111).
 *
 * It points each test at its own worker's environment — see helpers/workers.js:
 *
 * - baseURL is the server of the worker's slot, so page.goto('/fr/') and the request fixture
 *   reach that slot's server and database, and no other;
 * - adminSession, set by a spec with `test.use({ adminSession: ADMIN_SESSION })`, becomes the
 *   storageState of that group's session as created on the slot's server: a session only exists
 *   in the database it was created in.
 *
 * Specs name a group, never a file: a storageState set by a spec would replace this fixture
 * altogether — test.use overrides a fixture, it does not feed it — and send the test to a session
 * that exists in no slot's database. That is exactly how the first version of this file failed.
 *
 * The slot is testInfo.parallelIndex, stable from 0 to workers - 1 — not workerIndex, which grows
 * every time a worker restarts after a failure. scripts/__tests__/e2e-workers.test.js fails when a
 * spec imports @playwright/test directly or sets storageState, and so escapes all of this.
 */
export const test = base.extend({
    adminSession: [null, { option: true }],

    // eslint-disable-next-line no-empty-pattern -- Playwright reads fixture dependencies from the destructuring; this one has none
    baseURL: async ({}, use, testInfo) => {
        await use(urlFor(testInfo.parallelIndex));
    },

    storageState: async ({ adminSession, storageState }, use, testInfo) => {
        await use(adminSession === null ? storageState : sessionFileFor(adminSession, testInfo.parallelIndex));
    },
});

export { expect };
