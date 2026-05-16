# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**cardascia-it.org** — Personal website and portfolio for Laurent Bernard-Cardascia (lead dev).
Domain: `cardascia-it.org` (prod), `dev.cardascia-it.org` (dev).

## Commands

```bash
# Backend
php artisan octane:start        # Dev server via FrankenPHP (worker mode) — preferred
php artisan serve               # Dev server (standard, port 8000)
php artisan migrate             # Run migrations — requires explicit confirmation first
php artisan i18n:translate      # Translate lang/fr/*.php + resources/js/i18n/fr.json via Anthropic API
php artisan cms:translate       # Translate CMS DB content from French to EU locales via Anthropic API

# Static analysis — PHPStan
composer analyse                # Run both configs: app/ (level 8) then tests/ (level 5)

# Testing — PHP
php artisan test                # Full PHPUnit suite (no coverage)
composer test:unit              # Unit suite only (no coverage)
composer test:coverage          # Unit + Feature suites + PCOV coverage report + 80% minimum threshold

# Testing — JS
npm run test:unit:run           # Vitest unit suite (no coverage)
npm run test:unit:coverage      # Vitest unit suite + V8 coverage report + 80% minimum threshold
npm run test:e2e                # Playwright end-to-end suite

# Frontend
npm run dev                     # Vite HMR dev server
npm run build                   # Production asset build
```

## Stack

| Layer | Choice |
|-------|--------|
| Backend | Laravel 13 (v13.9+), PHP 8.5 CLI + FrankenPHP, PostgreSQL |
| Server | FrankenPHP via Laravel Octane (worker mode) |
| Auth | Laravel Sanctum (session-based) + TOTP 2FA enforced for admin |
| Roles | `spatie/laravel-permission` — roles: `admin`, `maintainer`, `member`, public |
| Frontend | Blade (structure/SEO) + Vue 3.5 islands (`<script setup>`) |
| Assets | Vite 8 + SCSS + UnoCSS (presetWind3 + presetMini) |
| Routes (JS) | `tightenco/ziggy` — `route('name')` helper available in all JS via `@routes` directive |
| Testing (PHP) | PHPUnit 13, PCOV coverage driver |
| Testing (JS) | Vitest 4 + `@vitest/coverage-v8`, Playwright for E2E |
| Blog | Database-backed, custom lightweight CMS (planned) |

## Architecture

### Islands pattern
Public pages are Blade templates rendered server-side (SEO-first). Vue 3 components are mounted as **islands** — each interactive widget calls its own `createApp()` and is injected into a specific DOM node. There is no global Vue app instance for the whole page.

### API routes
A dedicated `routes/api.php` file handles JSON endpoints, registered under the `/api` prefix. JS code must reference routes by name via Ziggy's `route()` helper — never hardcode URLs.

### Service layer — hard rule
**No business logic in controllers, Eloquent models, or Vue components.**

- PHP: all non-trivial logic lives in `app/Services/` — framework-agnostic, fully unit-testable without HTTP context
- JS: all non-trivial logic lives in `resources/js/utils/` — pure functions, testable with Vitest
- Controllers are thin orchestrators: validate → call service → return response
- Vue components wire Vue primitives (refs, lifecycle, template) around utils; they never contain domain logic
- Extraction can be deferred when only one caller exists, but must be noted as a known debt item

### Authentication & 2FA flow
All auth routes are under the `/{lang}/` prefix so locale is always set when these pages render.

The admin access chain enforces three middleware layers in order:

```
auth → role:admin → two_factor_verified
```

`EnsureTwoFactorVerified` (`app/Http/Middleware/`) implements a three-state gate:
1. No `two_factor_confirmed_at` on the user → redirect to `/{lang}/two-factor/setup`
2. Flag present but `auth.two_factor_verified` absent from session → store `url.intended`, redirect to `/{lang}/two-factor/challenge`
3. Session flag present → pass through

Session keys used by the 2FA flow:
- `auth.two_factor_setup_secret` — temporary secret stored during setup, cleared on confirm
- `auth.two_factor_verified` — boolean flag set after a successful challenge, lasts the session

The admin account is seeded via `AdminSeeder` from `.env` values (`ADMIN_EMAIL`, `ADMIN_NAME`, `ADMIN_PASSWORD`). Run once after `migrate`: `php artisan db:seed --class=AdminSeeder`.

`bootstrap/app.php` configures both redirect callbacks:
- `redirectUsersTo` — authenticated users hitting guest routes → `/{lang}/home`
- `redirectGuestsTo` — unauthenticated users hitting auth routes → `/{lang}/login`

### Roles & access
| Role | Access |
|------|--------|
| `admin` | Everything — monitoring, private tools, CMS |
| `maintainer` | Private sections in read-only |
| `member` | Semi-private sections, no tools/monitoring |
| — (public) | CV, blog, projects |

### Key directories
| Path | Role |
|------|------|
| `app/Http/Controllers/Auth/` | Login, logout, 2FA setup + challenge |
| `app/Http/Controllers/Public/` | Public-facing controllers |
| `app/Http/Controllers/Dashboard/` | Private sections (auth required) |
| `app/Http/Controllers/Admin/` | CMS and user management |
| `app/Http/Requests/Auth/` | `LoginRequest`, `TwoFactorCodeRequest` — validation isolated from controllers |
| `app/Http/Middleware/SetLocale.php` | Sets `App::setLocale()` from `{lang}` route param |
| `app/Http/Middleware/EnsureTwoFactorVerified.php` | 2FA gate — three-state redirect logic |
| `app/Services/` | Business logic — unit-tested, no HTTP dependency |
| `app/Services/Auth/LoginService.php` | Post-login redirect resolution (role-based) |
| `app/Services/Auth/TwoFactorService.php` | Secret generation, QR SVG, TOTP verify, DB confirm |
| `app/Services/AnthropicTranslator.php` | Anthropic API call + JSON flatten/unflatten helpers — shared by both translation commands |
| `app/Services/TranslationCache.php` | File-checksum + per-key TTL cache for i18n and CMS translations |
| `resources/views/` | Blade templates |
| `resources/js/` | Vue island components + utilities |
| `resources/js/utils/` | Pure JS utility modules (unit-tested) |
| `resources/js/i18n/` | vue-i18n locale files — one JSON per locale (24 EU languages) |
| `resources/css/app.scss` | Global styles (minimal — UnoCSS handles utilities) |
| `config/geo.php` | Geo API proxy configuration (env-driven) |
| `config/i18n.php` | Supported locales, default locale, native names, cache path, `cms_models` list |
| `lang/fr/` | PHP translation files — French source of truth for Blade `__()` |
| `storage/app/i18n/` | TranslationCache storage — checksums + per-key translations (gitignored) |
| `docs/` | Technical documentation |
| `scripts/` | Dev tooling scripts (coverage check, etc.) |

### FrankenPHP / Octane notes
- Worker mode keeps the app bootstrapped between requests — avoid storing state in static properties or singletons that should reset per request.
- Config is in `config/octane.php`.

## Testing conventions

### PHP — database
- Test database: **PostgreSQL**, dedicated `cardascia_it_test` database — **never** the `cardascia_it` schema
- Connection overrides (host, port, database name) are in `phpunit.xml` — credentials come from `.env` and are never committed
- Never use SQLite for tests — FK constraints and type behaviour diverge from PostgreSQL

### PHP — coverage scope (`phpunit.xml` + `scripts/check-coverage.php`)
`composer test:coverage` runs **both Unit and Feature suites** (`--testsuite=Unit,Feature`).
The following are excluded from the `<source>` block:
- `app/Http/Controllers/` — thin orchestrators, tested via Feature (HTTP) tests
- `app/Providers/` — bootstrapping code
- `app/Models/` — tested via Feature tests against real DB

`app/Console/Commands/` is **included** — Artisan commands have Feature tests that contribute to coverage. Never exclude code from coverage to make a threshold pass.

### JS — coverage scope (`vitest.config.js`)
Only `resources/js/utils/**/*.js` is in scope for unit coverage:
- `app.js` is an entry point (Vue island mounting), not unit-testable
- Vue components are covered by Playwright E2E tests and `@vue/test-utils` component tests

### Coverage thresholds
Both suites enforce **80% line coverage minimum** — commits are blocked by the pre-commit hook if the threshold is not met.

### Pre-commit hook
Runs automatically on `git commit`:
1. `lint-staged` — format checks
2. `npm run test:unit:coverage` — JS unit tests + coverage threshold
3. `composer test:coverage` — PHP unit + feature tests + coverage threshold

### Playwright — dedicated E2E database
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

`globalSetup` (`tests/e2e/global-setup.js`) runs `migrate:fresh --force` before each test run — every run starts from a clean schema.

### Playwright — authentication
Admin tests use `storageState` to authenticate once and reuse the session — **never** call the auth helper in `beforeEach` (causes race conditions under `fullyParallel: true`).

```js
test.use({ storageState: ADMIN_AUTH_FILE }); // tests/e2e/.auth/admin.json — gitignored
```

The session is created once by `tests/e2e/auth.setup.js` via `GET /e2e/admin-auth` (non-production helper route in `routes/e2e.php`).

The e2e routes require `session()->save()` after setting session data — with `SESSION_DRIVER=redis`, `StartSession::terminate()` writes Redis *after* the response is sent. The browser can follow a redirect before Redis is written, resulting in an empty session on the next request.

Form-submission tests must run in serial mode to prevent session flash interference between parallel workers:
```js
test.describe.configure({ mode: 'serial' });
```

### Playwright — e2e helper routes (`routes/e2e.php`)
Loaded only in non-production environments (guarded in `bootstrap/app.php`).

| Route | Purpose |
|-------|---------|
| `GET /e2e/admin-auth` | Creates `e2e-admin@test.local`, assigns admin role, logs in, sets 2FA session flag |
| `GET /e2e/homepage-content` | Returns current FR homepage content as JSON (for test snapshot) |
| `POST /e2e/homepage-content` | Restores FR homepage content from JSON body (for test teardown) |

Note: `two_factor_confirmed_at` is not in `User::$fillable` — assign it directly on the model instance to bypass the guard.

### Playwright — misc patterns
- `fill()` respects HTML `maxlength` — use `locator.evaluate((el) => { el.value = '...' })` to bypass it in tests that check server-side max-length validation
- `context.addInitScript()` applies to all pages created after the call; `page.addInitScript()` applies only to that page

### Playwright — boot overlay
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

## Static analysis (PHPStan)

### Dual-config setup
Two separate configs run in sequence via `composer analyse`:

| Config | File | Scope | Level |
|--------|------|-------|-------|
| App | `phpstan.neon` | `app/` (excl. `app/Providers/`) | 8 |
| Tests | `phpstan-tests.neon` | `tests/` | 5 |

Controllers and models are **included** in the app analysis (no `excludePaths` shortcut).

### Stub file
`phpstan-stubs.php` overrides `artisan()` to return `PendingCommand` (not `PendingCommand|int`) — the framework annotation is misleading; the implementation always wraps in `PendingCommand`.

### Type-narrowing conventions
- `$request->user()` returns `Authenticatable|null` — always narrow with `assert($user instanceof User)` in methods guaranteed by auth middleware
- Annotate `public array $translatable` on translatable models with `/** @var list<string> */`
- Never use `@phpstan-ignore` without a written justification comment

### PHP_INI_SCAN_DIR
PHPStan's ReactPHP worker processes don't inherit the conf.d scan path from the parent process.
The `analyse` script in `composer.json` always exports `PHP_INI_SCAN_DIR=/etc/php/8.5/cli/conf.d`
so that `phar.so` (and other extensions) are loaded in child processes too.
Without this, child workers fail with `Class "Phar" not found`.

## Internationalisation (i18n)

Three separate layers, each with its own tooling:

| Layer | Tool | Files |
|-------|------|-------|
| Strings UI — Blade | Laravel `lang/` native (`__()`) | `lang/fr/*.php`, `lang/{locale}/*.php` |
| Strings UI — Vue islands | `vue-i18n` v11 (`useI18n`) | `resources/js/i18n/*.json` (one file per locale) |
| CMS content (blog, projects…) | `spatie/laravel-translatable` | JSON columns in PostgreSQL |

### Supported locales
All 24 official EU languages: `bg cs da de el en es et fi fr ga hr hu it lt lv mt nl pl pt ro sk sl sv`

Configured in `config/i18n.php` — the single source of truth for locale codes, native names, default locale, and cache path.

### Routing
All public routes are prefixed by `/{lang}/`. The `where` constraint on the prefix uses the supported locales list from config. The root route `/` redirects to the preferred locale via `LocaleResolver`.

### LocaleResolver (`app/Services/LocaleResolver.php`)
Iterates `$request->getLanguages()` (pre-sorted by q-factor by Symfony) and returns the first base code found in `$supported`. Falls back to `$default` when nothing matches.

Note: `getPreferredLanguage($locales)` always returns `$locales[0]` as fallback — never `null` — so `?? $default` would be dead code. Use `getLanguages()` + explicit iteration for real fallback control.

### Vue i18n — conventions
- Locale is read from `document.documentElement.lang` (set by Laravel on `<html>`)
- Each island gets a fresh instance via `createI18n()` (`resources/js/utils/i18n.js`)
- Locale files are loaded dynamically with `import.meta.glob('../i18n/*.json', { eager: true })` — adding a new locale requires only creating the JSON file
- `legacy: false` — Composition API only, `useI18n()` in `<script setup>`
- Keys are in English, scoped by component: `theme_switcher.*`, `boot.*`, `language_switcher.*`…
- Fallback locale: `fr`

### Adding an island with i18n
```js
// app.js
import { createI18n } from './utils/i18n.js';
createApp(MyComponent).use(createI18n()).mount(el);
```
```js
// MyComponent.vue
const { t } = useI18n();
```
Add keys to `resources/js/i18n/fr.json` — the `i18n:translate` command propagates them to other locales.

### Translation commands — shared infrastructure

Both `i18n:translate` and `cms:translate` share the same service layer:

- **`AnthropicTranslator`** (`app/Services/AnthropicTranslator.php`) — makes the API call, strips markdown fences, decodes JSON, logs errors. Also provides `flattenJson()` / `unflattenJson()` helpers for dot-notation key handling.
- **`TranslationCache`** (`app/Services/TranslationCache.php`) — two-level cache: file/value checksum (skip unchanged sources) + per-key TTL store (6 months). Supports both file-backed sources (`snapshotFile`) and DB-backed CMS records (`snapshotCmsRecord` / `getChangedCmsFields`).
- **`AppServiceProvider`** binds both services with config values resolved at request time.

The cache lives in `storage/app/i18n/` (gitignored) — JSON files, not Redis (volatile) and not a DB table (needs migration). Persistent, diffable, zero dependencies.

Requires `ANTHROPIC_API_KEY` in `.env`. Both commands are no-ops with `--dry-run`.

### `php artisan i18n:translate`
Translates `lang/fr/*.php` and `resources/js/i18n/fr.json` to all other EU locales.

PHP source files may contain nested arrays (e.g. `validation.php` → `attributes` sub-array). The command flattens them to dot-notation before sending to the API, then reconstructs the nested structure for the output file via `exportPhpArray()`.

Options: `--locale=de`, `--force` (bypass checksum), `--dry-run`.

### `php artisan cms:translate`
Translates DB content for all models listed in `config('i18n.cms_models')`. Each model must use `spatie/laravel-translatable` (`HasTranslations` trait + `$translatable` array).

Change detection: hashes the French field values per record and compares to the stored snapshot. Only changed or missing fields trigger an API call.

Cache key per record: `cms_{ModelBaseName}_{id}` (e.g. `cms_HomepageContent_1`).

Options: `--locale=de`, `--force` (retranslate all fields), `--dry-run`.

To add a new CMS model: add its class to `config/i18n.php` → `cms_models`.

## Accessibility (WCAG 2.1 AA)

All views are axe-scanned against WCAG 2.1 AA via `@axe-core/playwright` in `tests/e2e/accessibility.spec.js` (3 themes × N pages).

### Color contrast — safe opacity thresholds
Dark-themed UIs with near-black backgrounds require higher opacity than typical designs.

| Text size | Utility opacity | Status |
|-----------|----------------|--------|
| 10px (`text-[10px]`) | `/40` | ❌ fails (~2.6:1) |
| 10px | `/60` | ✅ passes (~4.8:1) |
| 12–14px (`text-xs`, `text-sm`) | `/50` | ❌ fails (~3.5–4.1:1) |
| 12–14px | `/70` | ✅ passes (~5:1) |
| Any | `text-secondary/50` | ❌ fails — use `text-secondary` (full opacity) |

These thresholds were validated across the three themes (sprawl, steampunk, neon-noir). When in doubt, prefer higher opacity — the design intent of "muted" labels is preserved at `/60`–`/70`.

## Logging conventions
See `docs/logging-conventions.md` for the full reference.

Key rules:
- Static log messages only — no string interpolation
- Every log call must include `service`, `method`, and `step` context keys
- Exceptions must be passed as `['exception' => $e]` (full `Throwable`, not just the message)
- Never log PII, passwords, tokens, or full request/response bodies

## Environment variables
| Variable | Default | Description |
|---|---|---|
| `GEO_API_BASE_URL` | `http://ip-api.com/json` | Geo API base URL |
| `GEO_FETCH_TIMEOUT` | `3` | HTTP timeout in seconds |
| `GEO_CACHE_TTL` | `86400` | Success cache duration (seconds) |
| `GEO_FAILURE_CACHE_TTL` | `60` | Failure/rate-limit retry window (seconds) |
| `GEO_DEV_FALLBACK_IP` | _(empty)_ | Public IP substitute for local dev (127.0.0.1 → this value) |
| `ANTHROPIC_API_KEY` | _(empty)_ | API key for `i18n:translate` command |
| `ADMIN_EMAIL` | _(empty)_ | Admin account email — used by `AdminSeeder` |
| `ADMIN_NAME` | _(empty)_ | Admin account display name — used by `AdminSeeder` |
| `ADMIN_PASSWORD` | _(empty)_ | Admin account password — used by `AdminSeeder` (never commit a value) |
