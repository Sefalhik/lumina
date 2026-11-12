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

/** Workers, servers and databases: always the same number. Chosen by measurement — see docs/testing-conventions.md. */
export const E2E_WORKERS = 4;

const FIRST_PORT = 8001;

/** Migrated and seeded once per run; every slot's database is a copy of it. No server points at it. */
export const TEMPLATE_DATABASE = 'cardascia_it_e2e';

/** Overrides shared by every artisan command and every server of the suite. */
const SHARED_ENV = {
    SESSION_DOMAIN: 'localhost',
    SESSION_SECURE_COOKIE: 'false',
};

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

/** Environment of the server serving a slot. @param {number} slot */
export function serverEnv(slot) {
    return { ...SHARED_ENV, DB_DATABASE: databaseFor(slot), APP_URL: urlFor(slot) };
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
