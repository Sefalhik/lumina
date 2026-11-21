import path from 'path';
import { fileURLToPath } from 'url';

/**
 * One environment per Playwright worker: its own server, its own database (LUMN-111).
 *
 * Every worker used to share one server and one database, so two spec files writing the same row
 * corrupted each other at random — the homepage form and the skills editor did, two runs in four.
 * Each slot now gets a server on its own port, serving a database cloned from a template that
 * global-setup.js migrates and seeds once. A test reaches its slot's server through the baseURL
 * and storageState fixtures of helpers/test.js, which is why no spec imports @playwright/test
 * directly.
 *
 * This file is the single source of the mapping: playwright.config.js starts the servers from it,
 * global-setup.js creates the databases from it, auth.setup.js and the fixtures read it.
 */

/** Workers, servers and databases: always the same number. Chosen by measurement — see docs/testing/README.md. */
export const E2E_WORKERS = 4;

const FIRST_PORT = 8001;

/** Migrated and seeded once per run; every slot's database is a copy of it. No server points at it. */
export const TEMPLATE_DATABASE = 'cardascia_it_e2e';

/**
 * Overrides shared by every artisan command and every server of the suite.
 *
 * TELESCOPE_ENABLED: the package's provider is discovered in every environment, and recorded about
 * 3,400 entries per slot database in one run, measured on 2026-11-21 (LUMN-106) — seeding included,
 * copied into every slot. phpunit.xml switches it off the same way.
 */
const SHARED_ENV = {
    SESSION_DOMAIN: 'localhost',
    SESSION_SECURE_COOKIE: 'false',
    TELESCOPE_ENABLED: 'false',
};

/**
 * What the end-to-end servers serve (LUMN-106): the compiled build by default — what a visitor
 * receives — or, when E2E_DEV_SERVER=1 asks for it, the Vite dev server, to iterate without
 * rebuilding. Any other value is refused: a misspelt mode would test one thing while its author
 * believes it tests the other.
 *
 * @param {Record<string, string | undefined>} [env]
 * @returns {'build' | 'dev-server'}
 */
export function e2eMode(env = process.env) {
    const value = env.E2E_DEV_SERVER;
    if (value === undefined || value === '') {
        return 'build';
    }
    if (value === '1') {
        return 'dev-server';
    }
    throw new Error(`E2E_DEV_SERVER is "${value}": set it to 1 to test against the Vite dev server, or leave it unset.`);
}

/** @returns {number[]} every slot, 0 to E2E_WORKERS - 1 */
export function slots() {
    return Array.from({ length: E2E_WORKERS }, (_, slot) => slot);
}

/**
 * A slot outside the range would silently point a test at a server that was never started, or at
 * another worker's: refused rather than wrapped.
 *
 * @param {number} slot Playwright's testInfo.parallelIndex
 */
function assertSlot(slot) {
    if (!Number.isInteger(slot) || slot < 0 || slot >= E2E_WORKERS) {
        throw new RangeError(`No E2E environment for slot ${slot}: expected an integer from 0 to ${E2E_WORKERS - 1}.`);
    }
}

/** @param {number} slot */
export function portFor(slot) {
    assertSlot(slot);
    return FIRST_PORT + slot;
}

/** @param {number} slot */
export function urlFor(slot) {
    return `http://localhost:${portFor(slot)}`;
}

/** @param {number} slot */
export function databaseFor(slot) {
    assertSlot(slot);
    return `${TEMPLATE_DATABASE}_${slot}`;
}

/**
 * What to tell whoever runs the suite about the mode, or null when there is nothing to say: the build
 * is what the suite is for, the dev server a choice that must never pass for it.
 *
 * @param {'build' | 'dev-server'} mode
 * @returns {string | null}
 */
export function modeNotice(mode) {
    return mode === 'dev-server'
        ? 'E2E_DEV_SERVER=1: the pages are tested against the Vite dev server, not against the compiled build a visitor receives.'
        : null;
}

/**
 * Environment of the server serving a slot.
 *
 * `testing` by default: the application then ignores public/hot and serves the build
 * (AppServiceProvider), and nothing meant for a developer's machine runs. `local` in the dev-server
 * mode only, because the content security policy lets a dev server in on a developer's machine alone.
 *
 * @param {number} slot
 * @param {Record<string, string | undefined>} [env]
 */
export function serverEnv(slot, env = process.env) {
    const appEnv = e2eMode(env) === 'build' ? 'testing' : 'local';
    return { ...SHARED_ENV, APP_ENV: appEnv, DB_DATABASE: databaseFor(slot), APP_URL: urlFor(slot) };
}

/** Environment of the artisan commands that prepare the template. */
export function templateEnv() {
    return { ...SHARED_ENV, DB_DATABASE: TEMPLATE_DATABASE, APP_URL: 'http://localhost' };
}

const AUTH_DIR = path.join(path.dirname(fileURLToPath(import.meta.url)), '../.auth');

/**
 * The file holding a group's admin session on a slot. Sessions live in a database, so each slot's
 * server needs its own: the `admin` group of slot 2 is `.auth/slot-2/admin.json`.
 *
 * @param {string} group one of the session groups of helpers/auth.js
 * @param {number} slot
 */
export function sessionFileFor(group, slot) {
    assertSlot(slot);
    if (typeof group !== 'string' || !/^[a-z0-9-]+$/.test(group)) {
        throw new TypeError(`Not a session group name: ${String(group)}.`);
    }
    return path.join(AUTH_DIR, `slot-${slot}`, `${group}.json`);
}
