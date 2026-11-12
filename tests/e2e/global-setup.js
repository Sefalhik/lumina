import { execFileSync } from 'child_process';
import { databaseFor, slots, TEMPLATE_DATABASE, templateEnv } from './helpers/workers.js';

/**
 * Runs once before all Playwright tests: one fresh database per worker slot (LUMN-111).
 *
 * The template database is migrated and seeded once, then copied into every slot's database with
 * CREATE DATABASE … TEMPLATE — a file copy on PostgreSQL's side, far cheaper than migrating and
 * seeding each one. No server points at the template, which is what lets PostgreSQL copy it: a
 * template must have no open connection.
 *
 * Playwright starts the web servers *before* this runs (its plugins come first), which is why the
 * servers' readiness URL in playwright.config.js is a static file and not /up: /up reads the
 * database, and the slots' databases do not exist yet at that point.
 *
 * Prerequisite (once, run manually): the template database, and a role allowed to create databases.
 *   createdb -h 127.0.0.1 -p 5433 -U <user> cardascia_it_e2e
 */

/**
 * The only databases this file may touch. migrate:fresh drops every table of the database it runs
 * on: run anywhere else, it wipes it. On 2026-11-12 a mutation pass deleted the line that passed
 * this environment, artisan read .env instead, and the developer's database was wiped — the suite
 * stayed green on the template left by the previous run. Hence an allowlist, checked on every call.
 */
const DATABASES_THIS_SETUP_MAY_TOUCH = new Set([TEMPLATE_DATABASE, 'postgres']);

/**
 * The full environment of an artisan call: always built here, never inherited for the database.
 * Throws before anything runs when the database is not one this setup may touch.
 *
 * @param {string} database
 */
export function artisanEnv(database) {
    if (!DATABASES_THIS_SETUP_MAY_TOUCH.has(database)) {
        throw new Error(
            `global-setup refuses to run artisan on "${database}": only ${[...DATABASES_THIS_SETUP_MAY_TOUCH].join(', ')} may be touched.`,
        );
    }
    return { ...process.env, ...templateEnv(), DB_DATABASE: database };
}

/**
 * @param {string[]} args
 * @param {string} database
 */
function artisan(args, database) {
    execFileSync('php', ['artisan', ...args], {
        env: artisanEnv(database),
        stdio: 'inherit',
    });
}

export default function globalSetup() {
    artisan(['migrate:fresh', '--force'], TEMPLATE_DATABASE);

    // Seed a fictional site identity so the footer actually renders its links.
    // Without this the table is empty, the footer is bare, and the axe-core
    // scan never sees those links — the suite would stay green while their
    // accessibility went unchecked.
    artisan(['db:seed', '--class=E2eSiteIdentitySeeder', '--force'], TEMPLATE_DATABASE);

    // Same reasoning, applied to the homepage. With an empty homepage_contents
    // table the view falls back to a one-sentence placeholder: the About
    // section never renders and the skills grid shows its fallback branch, so
    // axe-core scans three themes without ever seeing what the CMS actually
    // produces.
    //
    // The real seeder is used rather than an E2E twin, because since LUMN-29 it
    // reads a versioned, offline data file — so the browser suite exercises the
    // exact content production will ship, in all twenty-four locales.
    artisan(['db:seed', '--class=HomepageContentSeeder', '--force'], TEMPLATE_DATABASE);

    // Same reasoning for the CV timeline: its markup — period, "still there"
    // badge, headings — only exists when a record does. An empty table renders
    // the empty state and the axe-core scan of /cv checks nothing.
    artisan(['db:seed', '--class=E2eExperienceSeeder', '--force'], TEMPLATE_DATABASE);

    // One copy per slot. FORCE closes whatever a previous run left connected; the names come from
    // helpers/workers.js, never from input. Run against the maintenance database: a database
    // cannot be dropped, nor its template copied, from a connection to either.
    const statements = slots().flatMap((slot) => [
        `DROP DATABASE IF EXISTS "${databaseFor(slot)}" WITH (FORCE)`,
        `CREATE DATABASE "${databaseFor(slot)}" TEMPLATE "${TEMPLATE_DATABASE}"`,
    ]);
    artisan(['tinker', '--execute', statements.map((sql) => `DB::statement('${sql}');`).join(' ')], 'postgres');
}
