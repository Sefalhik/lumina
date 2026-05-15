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
}
