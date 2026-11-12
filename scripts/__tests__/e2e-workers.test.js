// @vitest-environment node
import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import {
    databaseFor,
    E2E_WORKERS,
    portFor,
    serverEnv,
    sessionFileFor,
    slots,
    TEMPLATE_DATABASE,
    templateEnv,
    urlFor,
} from '../../tests/e2e/helpers/workers.js';
import { ADMIN_SESSIONS } from '../../tests/e2e/helpers/auth.js';
import config from '../../playwright.config.js';

/**
 * One environment per Playwright worker (LUMN-111). The browser suite proves the isolation from
 * the outside (tests/e2e/worker-isolation.spec.js) for the slot that happens to run it; this file
 * proves the mapping itself, for every slot, and the seams between the files that read it.
 */

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const e2eDir = path.join(root, 'tests/e2e');

describe('the slot mapping', () => {
    it('gives every slot a port, a URL and a database of its own', () => {
        const all = slots();

        expect(all).toEqual(Array.from({ length: E2E_WORKERS }, (_, slot) => slot));
        expect(new Set(all.map(portFor)).size).toBe(E2E_WORKERS);
        expect(new Set(all.map(urlFor)).size).toBe(E2E_WORKERS);
        expect(new Set(all.map(databaseFor)).size).toBe(E2E_WORKERS);
    });

    it('never points a slot at the template, which must stay free of connections to be copied', () => {
        for (const slot of slots()) {
            expect(databaseFor(slot)).not.toBe(TEMPLATE_DATABASE);
            expect(serverEnv(slot).DB_DATABASE).not.toBe(TEMPLATE_DATABASE);
        }
        expect(templateEnv().DB_DATABASE).toBe(TEMPLATE_DATABASE);
    });

    it('serves each slot from the database and URL of that slot', () => {
        for (const slot of slots()) {
            expect(serverEnv(slot)).toMatchObject({ DB_DATABASE: databaseFor(slot), APP_URL: urlFor(slot) });
            expect(urlFor(slot)).toBe(`http://localhost:${portFor(slot)}`);
        }
    });

    it('keeps one session file per slot and group, inside the gitignored .auth directory', () => {
        const files = slots().flatMap((slot) => ADMIN_SESSIONS.map((group) => sessionFileFor(group, slot)));

        expect(new Set(files).size).toBe(E2E_WORKERS * ADMIN_SESSIONS.length);
        for (const file of files) {
            expect(path.relative(path.join(e2eDir, '.auth'), file)).toMatch(/^slot-\d+\/[a-z0-9-]+\.json$/);
        }
    });

    it.each([[''], ['../admin'], ['admin.json'], ['Admin'], [null], [undefined], [42]])(
        'refuses %s as a session group: it ends up in a file path',
        (group) => {
            expect(() => sessionFileFor(group, 0)).toThrow(TypeError);
        },
    );

    it.each([[-1], [E2E_WORKERS], [1.5], ['0'], [Number.NaN], [undefined], [null]])(
        'refuses slot %s rather than pointing a test at a server nobody started',
        (slot) => {
            expect(() => portFor(slot)).toThrow(RangeError);
            expect(() => databaseFor(slot)).toThrow(RangeError);
            expect(() => sessionFileFor('admin', slot)).toThrow(RangeError);
        },
    );
});

describe('playwright.config.js', () => {
    it('prepares the databases before every run', () => {
        // Without it, each run reuses the slot databases the previous one left — stale schema,
        // stale data — and stays green on them: the targeted mutation pass of 2026-11-12 showed it.
        expect(path.resolve(root, config.globalSetup)).toBe(path.join(e2eDir, 'global-setup.js'));
    });

    it('runs exactly one worker per server', () => {
        expect(config.workers).toBe(E2E_WORKERS);
        expect(config.webServer).toHaveLength(E2E_WORKERS);
    });

    it('starts each slot on its port, with its own database', () => {
        config.webServer.forEach((server, slot) => {
            expect(server.command).toContain(`--port=${portFor(slot)}`);
            expect(server.env).toEqual(serverEnv(slot));
        });
    });

    it('waits on a static file, never on /up — the databases are created after the servers start', () => {
        for (const server of config.webServer) {
            expect(new URL(server.url).pathname).not.toBe('/up');
            expect(fs.existsSync(path.join(root, 'public', new URL(server.url).pathname))).toBe(true);
        }
    });
});

describe('the specs', () => {
    const files = fs
        .readdirSync(e2eDir, { recursive: true })
        .map(String)
        .filter((file) => /\.(spec|setup)\.js$/.test(file));

    it('are found at all', () => {
        // Guard against a glob that matches nothing, which would make the next tests pass vacuously.
        expect(files.length).toBeGreaterThan(10);
    });

    it.each(files)('%s takes test from helpers/test.js, never from @playwright/test', (file) => {
        // Imported directly, @playwright/test's test has no per-worker baseURL nor storageState:
        // the spec would talk to slot 0's server from every worker. LUMN-109 turns this into an
        // ESLint rule, and removes this test.
        const source = fs.readFileSync(path.join(e2eDir, file), 'utf8');

        expect(source).not.toMatch(/from ['"]@playwright\/test['"]/);
        expect(source).toMatch(/from ['"](\.\.?\/)+helpers\/test\.js['"]/);
    });

    it.each(files)('%s never sets storageState: it names an adminSession group', (file) => {
        // test.use({ storageState }) replaces the slot-aware fixture of helpers/test.js outright,
        // and sends the test to a session that exists in no slot's database.
        const source = fs.readFileSync(path.join(e2eDir, file), 'utf8');

        expect(source).not.toMatch(/test\.use\(\{[^}]*storageState/);
    });

    it.each(files)('%s checks every write to an /e2e/ helper route', (file) => {
        // The restore routes answered 419 for months and no spec noticed: an unchecked write is a
        // restore nobody knows failed. Every POST is wrapped in an assertion on its response.
        const source = fs.readFileSync(path.join(e2eDir, file), 'utf8');
        const posts = source.match(/request\.post\(['"`][^'"`]*\/e2e\//g) ?? [];
        const checked =
            source.match(
                // Whatever Prettier does to a long assertion: line breaks, a trailing comma. Never across a `;`.
                /expect\(\(await request\.post\(['"`][^'"`]*\/e2e\/[^'"`]*['"`],\s*\{[^;]*?\}\s*\)\)\.ok\(\)\)\.toBe\(\s*true\s*,?\s*\)/g,
            ) ?? [];

        expect(checked.length).toBe(posts.length);
    });

    it.each(files)('%s names no server port: the slot decides', (file) => {
        const source = fs.readFileSync(path.join(e2eDir, file), 'utf8');

        expect(source).not.toMatch(/localhost:\d{4}/);
    });
});
