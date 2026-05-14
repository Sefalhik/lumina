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

### Services
Business logic lives in `app/Services/`. Services are framework-agnostic classes, fully unit-testable without HTTP context.

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
| `resources/views/` | Blade templates |
| `resources/js/` | Vue island components + utilities |
| `resources/js/utils/` | Pure JS utility modules (unit-tested) |
| `resources/js/i18n/` | vue-i18n locale files — one JSON per locale (24 EU languages) |
| `resources/css/app.scss` | Global styles (minimal — UnoCSS handles utilities) |
| `config/geo.php` | Geo API proxy configuration (env-driven) |
| `config/i18n.php` | Supported locales, default locale, native names, cache path |
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

### `php artisan i18n:translate`
Translates French source files to all other supported locales using the Anthropic API (Claude Haiku).

Two-level optimization:
1. **File checksum** (SHA-256) — skip unchanged source files entirely
2. **Per-key TTL cache** (6 months) — only send new/changed keys to the API; serve the rest from `storage/app/i18n/`

Options: `--locale=de` (single locale), `--force` (bypass checksum), `--dry-run` (no API calls, no file writes).

The cache is JSON files in `storage/app/i18n/` — not Redis (volatile) and not a DB table (needs migration). Persistent, diffable, zero dependencies.

Requires `ANTHROPIC_API_KEY` in `.env`. Skipped automatically with `--dry-run`.

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
