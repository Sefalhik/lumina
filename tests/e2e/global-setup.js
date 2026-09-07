import { execSync } from 'child_process';
import { e2eEnv } from './helpers/e2e-env.js';

/**
 * Runs once before all Playwright tests.
 *
 * Resets the dedicated E2E database to a clean state so every test run
 * starts from a known schema with no leftover data.
 *
 * Prerequisite (one-time, run manually):
 *   createdb -h 127.0.0.1 -p 5433 -U sefalhik cardascia_it_e2e
 */
export default function globalSetup() {
    execSync('php artisan migrate:fresh --force', {
        env: { ...process.env, ...e2eEnv },
        stdio: 'inherit',
    });

    // Seed a fictional site identity so the footer actually renders its links.
    // Without this the table is empty, the footer is bare, and the axe-core
    // scan never sees those links — the suite would stay green while their
    // accessibility went unchecked.
    execSync('php artisan db:seed --class=E2eSiteIdentitySeeder --force', {
        env: { ...process.env, ...e2eEnv },
        stdio: 'inherit',
    });

    // Same reasoning, applied to the homepage. With an empty homepage_contents
    // table the view falls back to a one-sentence placeholder: the About
    // section never renders and the skills grid shows its fallback branch, so
    // axe-core scans three themes without ever seeing what the CMS actually
    // produces.
    //
    // The real seeder is used rather than an E2E twin, because since LUMN-29 it
    // reads a versioned, offline data file — so the browser suite exercises the
    // exact content production will ship, in all twenty-four locales.
    execSync('php artisan db:seed --class=HomepageContentSeeder --force', {
        env: { ...process.env, ...e2eEnv },
        stdio: 'inherit',
    });

    // Same reasoning for the CV timeline: its markup — period, "still there"
    // badge, headings — only exists when a record does. An empty table renders
    // the empty state and the axe-core scan of /cv checks nothing.
    execSync('php artisan db:seed --class=E2eExperienceSeeder --force', {
        env: { ...process.env, ...e2eEnv },
        stdio: 'inherit',
    });
}
