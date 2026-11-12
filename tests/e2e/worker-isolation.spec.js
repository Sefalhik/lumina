import { test, expect } from './helpers/test.js';
import { slots, urlFor } from './helpers/workers.js';

/**
 * Each worker talks to a server and a database of its own (LUMN-111).
 *
 * Proven from the outside, through the existing /e2e/site-identity helper routes: the worker writes
 * a marker into its own identity row, reads it back through the relative baseURL, then asks every
 * other slot's server and must not find it there. Two slots sharing a database — a server reused
 * from an earlier run with another environment, a wrong DB_DATABASE — turn this red.
 *
 * Writing the identity row is safe here: within a slot, spec files run one after another, and the
 * row is restored before the test ends, whatever happens.
 */
test('a worker reaches its own server, and no other worker sees what it writes', async ({ request }, testInfo) => {
    const own = testInfo.parallelIndex;
    const original = await (await request.get('/e2e/site-identity')).json();
    const marker = `isolation marker of slot ${own}`;

    try {
        expect((await request.post('/e2e/site-identity', { data: { ...original, full_name: marker } })).ok()).toBe(
            true,
        );

        // The relative URL went to this slot's server: the absolute one sees the same row.
        const ownIdentity = await (await request.get(`${urlFor(own)}/e2e/site-identity`)).json();
        expect(ownIdentity.full_name).toBe(marker);

        for (const other of slots().filter((slot) => slot !== own)) {
            const identity = await (await request.get(`${urlFor(other)}/e2e/site-identity`)).json();
            expect(identity.full_name, `slot ${other} sees what slot ${own} wrote`).not.toBe(marker);
        }
    } finally {
        expect((await request.post('/e2e/site-identity', { data: original })).ok()).toBe(true);
    }
});
