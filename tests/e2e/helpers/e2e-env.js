/**
 * Environment overrides applied to the dedicated E2E server (port 8001).
 *
 * These values are injected by Playwright's webServer.env and by globalSetup
 * when running artisan commands — they override the corresponding .env values.
 */
export const e2eEnv = {
    DB_DATABASE: 'cardascia_it_e2e',
    APP_URL: 'http://localhost:8001',
    SESSION_DOMAIN: 'localhost',
    SESSION_SECURE_COOKIE: 'false',
};
