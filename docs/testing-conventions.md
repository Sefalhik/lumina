# Testing conventions

## PHP — database
- Test database: **PostgreSQL**, dedicated `cardascia_it_test` database — **never** the `cardascia_it` schema
- Connection overrides (host, port, database name) are in `phpunit.xml` — credentials come from `.env` and are never committed
- Never use SQLite for tests — FK constraints and type behaviour diverge from PostgreSQL

## PHP — coverage scope (`phpunit.xml` + `scripts/check-coverage.php`)
`composer test:coverage` runs **both Unit and Feature suites** (`--testsuite=Unit,Feature`).
The following are excluded from the `<source>` block:
- `app/Http/Controllers/` — thin orchestrators, tested via Feature (HTTP) tests
- `app/Providers/` — bootstrapping code
- `app/Models/` — tested via Feature tests against real DB

`app/Console/Commands/` is **included** — Artisan commands have Feature tests that contribute to coverage. Never exclude code from coverage to make a threshold pass.

## JS — coverage scope (`vitest.config.js`)
Only `resources/js/utils/**/*.js` is in scope for unit coverage:
- `app.js` is an entry point (Vue island mounting), not unit-testable
- Vue components are covered by Playwright E2E tests and `@vue/test-utils` component tests

## Coverage thresholds
Both suites enforce **80% line coverage minimum** — commits are blocked by the pre-commit hook if the threshold is not met.

## Boot-time decisions — `Tests\Concerns\RebootsInEnvironment`

`bootstrap/app.php` decides at boot which routes exist and which providers are registered. Those
decisions cannot be asserted by changing config: the app has already booted. The trait re-requires
`bootstrap/app.php` under another `APP_ENV` via `refreshApplication()`, writing the value to `$_ENV`,
`$_SERVER` **and** `putenv()` — Laravel's Env repository reads all three, and `phpunit.xml` populates
the first two.

It **fails loudly if the reboot stops taking effect**. Without that check, every test using it would
keep observing the `testing` environment and report green — the defect being asserted against would
be invisible, which is worse than having no test.

Used by `tests/Feature/Deployment/`, which covers the five guards that only matter off a developer's
machine: the e2e route allowlist, Telescope's conditional registration, `trustProxies`,
`public/.htaccess`, and driver parity across environments.

**Two traps recorded there, both found while writing those tests:**

- **`$this->get('/path')` builds its URL from `APP_URL`, which is `https://`.** Two `trustProxies`
  assertions passed with the middleware deleted entirely, because the request was already secure.
  Any test about scheme, host or proxy headers must address an explicit `http://` root — and carry a
  negative control, which is what caught it.
- **"Absent in production" is not a test of an allowlist.** A denylist of `production` passes it.
  The assertion that distinguishes them is *absent in an environment the codebase has never heard
  of* — `preprod`, `staging`, `review-app-42`.

## Pre-commit hook
Runs automatically on `git commit`:
1. `lint-staged` — format checks
2. `npm run test:unit:coverage` — JS unit tests + coverage threshold
3. `composer test:coverage` — PHP unit + feature tests + coverage threshold

## Playwright — dedicated E2E database
E2E tests run against a **dedicated PostgreSQL database** (`cardascia_it_e2e`) and a **dedicated server** (`php artisan serve --port=8001`) — never the dev database or dev server.

Prerequisite (one-time):
```bash
createdb -h 127.0.0.1 -p 5433 -U <user> cardascia_it_e2e
```

The Playwright `webServer` starts the server automatically with env overrides from `tests/e2e/helpers/e2e-env.js`:
- `DB_DATABASE=cardascia_it_e2e`
- `APP_URL=http://localhost:8001`
- `SESSION_DOMAIN=localhost`
- `SESSION_SECURE_COOKIE=false` (HTTP — not HTTPS like the dev server)

`globalSetup` (`tests/e2e/global-setup.js`) runs `migrate:fresh --force` before each test run — every run starts from a clean schema — then seeds `E2eSiteIdentitySeeder`.

**Why that seeder matters**: with an empty `site_identities` table the footer renders no links, so the axe-core scan never sees them and their accessibility goes unverified while the suite stays green. Seeding a fictional identity makes the existing scan cover them on all three themes. Any future feature whose markup only appears when data exists needs the same treatment.

`HomepageContentSeeder` runs next, for exactly that reason — and the rule had already been broken once before it did. With an empty `homepage_contents` table the homepage falls back to a one-sentence placeholder: the About section never renders, and the skills grid shows its fallback branch. axe-core scanned three themes without ever seeing what the CMS produces. Seeding it surfaced **373 real contrast violations** in `SkillsEditor`'s line-number gutter, plus an E2E test that only passed because the editor was empty.

The **real** seeder is used rather than an E2E twin: since LUMN-29 it reads a versioned, offline data file, so the browser suite exercises the content production will actually ship.

## Playwright — authentication
Admin tests use `storageState` to authenticate once and reuse the session — **never** call the auth helper in `beforeEach` (causes race conditions under `fullyParallel: true`).

```js
test.use({ storageState: ADMIN_AUTH_FILE }); // tests/e2e/.auth/admin.json — gitignored
```

The session is created once by `tests/e2e/auth.setup.js` via `GET /e2e/admin-auth` (helper route in `routes/e2e.php`, loaded in `local` and `testing` only).

The e2e routes call `session()->save()` explicitly after setting session data. It was required under `SESSION_DRIVER=redis`, which writes in `StartSession::terminate()` — after the response is sent — so the browser could follow the redirect before the session existed. The `database` driver adopted on 2026-09-14 writes during the request, so the call is now belt and braces; it is kept because an explicit save before a redirect is never wrong and it keeps the helper independent of the driver in use.

Form-submission tests must run in serial mode to prevent session flash interference between parallel workers:
```js
test.describe.configure({ mode: 'serial' });
```

## Playwright — one admin session per spec file
`auth.setup.js` creates **one isolated server-side session per spec file that loads admin pages**, each from its own browser context (`tests/e2e/helpers/auth.js`):

| Auth file | Constant | Used by |
|-----------|----------|---------|
| `.auth/admin.json` | `ADMIN_AUTH_FILE` | `homepage.spec.js` (its admin describe) |
| `.auth/admin-editor.json` | `ADMIN_EDITOR_AUTH_FILE` | `admin/skills-editor.spec.js` |
| `.auth/admin-identity.json` | `ADMIN_IDENTITY_AUTH_FILE` | `site-identity.spec.js` |
| `.auth/admin-a11y.json` | `ADMIN_A11Y_AUTH_FILE` | `accessibility.spec.js` |
| `.auth/admin-experiences.json` | `ADMIN_EXPERIENCES_AUTH_FILE` | `admin/experiences.spec.js` |

**Why one per file**: `fullyParallel: true` runs spec files concurrently. Two browser contexts created from the same `storageState` send the same `laravel_session` cookie → the same server-side session store. A flash message set by one spec's submission is consumed by the next page load in *any* spec sharing that session, regardless of browser-context isolation.

**The trap**: this is not limited to specs that submit forms. **Any page load consumes pending flashes**, so a read-only spec steals them too — that is why the axe-core scans need their own session, and why adding three scans on the shared session was enough to break `homepage-form.spec.js`.

**When adding a spec that loads admin pages**, give it its own auth file: add a constant in `helpers/auth.js`, add it to `SESSIONS` in `auth.setup.js`, and `test.use()` it. Reusing an existing one produces failures in a *different* spec, which is a miserable thing to debug.

## Playwright — e2e helper routes (`routes/e2e.php`)
Loaded in `local` and `testing` **only** — an allowlist in `bootstrap/app.php`.

`GET /e2e/admin-auth` creates an admin, sets the 2FA session flag and logs the caller in: no
password, no TOTP. It is a backdoor, and it must exist nowhere anyone else can reach.

Until 2026-09-14 the guard was the denylist `! app()->environment('production')`, which published
that route on **every environment name nobody had anticipated** — starting with the preprod site,
which is on the public internet. Same reasoning as `config/seo.php` → `public_routes`: a forgotten
denylist entry exposes something, a forgotten allowlist entry hides something.

Consequence for `.env` on any internet-facing host, preprod included: **`APP_ENV=production`**.
Naming an environment after its role rather than its exposure is how the backdoor gets published.

| Route | Purpose |
|-------|---------|
| `GET /e2e/admin-auth` | Creates `e2e-admin@test.local`, assigns admin role, logs in, sets 2FA session flag |
| `GET /e2e/homepage-content` | Returns current FR homepage content as JSON (for test snapshot) |
| `POST /e2e/homepage-content` | Restores FR homepage content from JSON body (for test teardown) |
| `GET /e2e/site-identity` | Returns the current site identity as JSON (for test snapshot) |
| `POST /e2e/site-identity` | Restores the site identity from JSON body (for test teardown) |

**Specs that mutate site-wide data must restore it after *every* test, not just at the end.** The identity row feeds the footer of every page, so leaving it modified corrupts any spec that renders a public page. `site-identity.spec.js` keeps the admin form and the footer in **one serial file** for the same reason — split across two files, Playwright runs them in parallel and they read each other's half-written state.

Note: `two_factor_confirmed_at` is not in `User::$fillable` — assign it directly on the model instance to bypass the guard.

## Playwright — misc patterns
- `fill()` respects HTML `maxlength` — use `locator.evaluate((el) => { el.value = '...' })` to bypass it in tests that check server-side max-length validation
- `context.addInitScript()` applies to all pages created after the call; `page.addInitScript()` applies only to that page

## Playwright — boot overlay
Most E2E tests need to skip the boot sequence overlay. Use `page.addInitScript()` **before** `page.goto()`:
```js
await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
await page.goto('/fr/');
```
The `BootSequence` component checks this key at `onMounted` and skips rendering when set.

When asserting on `[role="option"]`, always scope to the target listbox to avoid matching options from other components on the page:
```js
page.getByRole('listbox', { name: 'Changer de langue' }).getByRole('option')
```
