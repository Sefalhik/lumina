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
php artisan cms:export-seed     # Freeze the translated homepage row into its versioned seed file

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

# Full quality audit
npm run check:full              # ESLint + Stylelint + PHPStan + PHPUnit + Vitest + Playwright (~40s)

# Linting & formatting — JS
npm run lint:js                 # ESLint — check resources/js/**/*.{js,vue}
npm run lint:js:fix             # ESLint — auto-fix
npm run lint:scss               # Stylelint — check SCSS
npm run lint:fix                # Stylelint — auto-fix SCSS
npm run format                  # Prettier — format SCSS, Vue, JS

# Frontend
npm run dev                     # Vite HMR dev server
npm run build                   # Production asset build

# Maintenance
npm run update:frankenphp             # Update FrankenPHP binary — shows diff, asks confirmation
npm run update:frankenphp -- --force  # Update without prompt (CI/CD)
# Note: setcap cap_net_bind_service=+ep is re-applied automatically after each update
```

## Stack

| Layer | Choice |
|-------|--------|
| Backend | Laravel 13 (v13.9+), PHP 8.5 CLI + FrankenPHP, PostgreSQL |
| Server | FrankenPHP via Laravel Octane (worker mode) in development; **Apache + PHP-FPM** on alwaysdata — see `docs/deployment.md` |
| Session / cache / queue | `database` / `database` / `sync` — **the same in every environment that serves a browser**, see *Deployment* |
| Auth | Laravel Sanctum (session-based) + TOTP 2FA enforced for admin |
| Roles | `spatie/laravel-permission` — roles: `admin`, `maintainer`, `member`, public |
| Frontend | Blade (structure/SEO) + Vue 3.5 islands (`<script setup>`) |
| Assets | Vite 8 + SCSS + Tailwind 4 (`@tailwindcss/vite`) + DaisyUI 5 |
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

### Route parameters and the `{lang}` prefix

Laravel passes route parameters to a controller **positionally**. Every public and admin route sits
under `Route::prefix('{lang}')`, so `{lang}` would arrive as the *first* argument of any action — an
action typed `edit(Experience $experience)` receives the locale string instead of the bound model.

`SetLocale` therefore calls `$request->route()?->forgetParameter('lang')` once it has set the locale
and the URL default. Without it, every controller taking a route-model-bound parameter would need a
`string $lang` first argument, spreading a routing detail through the whole controller layer.

Safe because nothing reads the parameter afterwards: URL generation goes through
`URL::defaults(['lang' => …])`, and `LocalizedUrlService` always passes an explicit `lang` when
building alternates.

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
| `app/Services/HomepageContentService.php` | Splits the bio into the hero's hook and the About section's paragraphs — no model, no request |
| `database/data/homepage-content.php` | Homepage copy, 24 locales, generated by `cms:export-seed` — what a deployment reads |
| `app/Console/Commands/CmsExportSeed.php` | Writes that file from the DB row; third step of the content pipeline |
| `app/Services/AnthropicTranslator.php` | Anthropic API call + JSON flatten/unflatten helpers — shared by both translation commands |
| `app/Services/TranslationCache.php` | File-checksum + per-key TTL cache for i18n and CMS translations |
| `app/Services/Seo/LocalizedUrlService.php` | Canonical + `hreflang` alternate URLs — no Request dependency |
| `app/Services/SiteIdentityService.php` | Filters the `SiteIdentity` row into renderable links — footer today, `sameAs` next |
| `app/Services/CvService.php` | **CV read side** — shapes `Experience` records into renderable rows. Takes a collection rather than querying, so it stays unit-testable without a database |
| `app/Services/ExperienceService.php` | **CV write side** — applies a validated admin submission onto an `Experience` without saving. Reads `$translatable` from the model rather than repeating the list. Also shapes the admin index (`adminRows()`), borrowing `CvService::period()` so the period rule has one home |
| `app/Models/SiteIdentity.php` | Single-row site identity. **Nothing is translated**, `job_title` included — it is a `sameAs` cross-reference key (LUMN-15) |
| `app/Models/Experience.php` | One position in the CV timeline. Partially translated: prose only — `job_title` is a cross-reference key, not prose |
| `app/Http/Controllers/Public/CvController.php` | Public CV page — queries, delegates shaping to `CvService` |
| `app/Http/Controllers/Admin/ExperienceController.php` | CV timeline CRUD — validates, delegates to `ExperienceService`, persists |
| `resources/views/` | Blade templates |
| `resources/js/` | Vue island components + utilities |
| `resources/js/utils/` | Pure JS utility modules (unit-tested) |
| `resources/js/i18n/` | vue-i18n locale files — one JSON per locale (24 EU languages) |
| `resources/css/app.css` | Tailwind + DaisyUI entry point (`@import`/`@plugin`); utilities come from there |
| `resources/css/scss/` | Hand-written SCSS — themes and the `_effects.scss` glow/glitch layer |
| `config/geo.php` | Geo API proxy configuration (env-driven) |
| `config/i18n.php` | Supported locales, **indexable locales**, default locale, native names, cache path, `cms_models` list |
| `config/seo.php` | `public_routes` allowlist — route names allowed to carry canonical/`hreflang` |
| `lang/fr/` | PHP translation files — French source of truth for Blade `__()` |
| `storage/app/i18n/` | TranslationCache storage — checksums + per-key translations (gitignored) |
| `docs/` | Technical documentation |
| `docs/blog-prep/` | Session brain dumps — raw material for the blog, one file per session |
| `scripts/` | Dev tooling scripts (coverage check, etc.) |
| `app/Enums/` | PHP backed enums — single source of truth for constrained value sets, optionally shared with JS via a `forJs()` method (e.g., `SkillIcon`) |
| `app/Rules/` | Custom Laravel validation rules — framework-agnostic, fully unit-tested (e.g., `ValidSkillsJson`, `ProfileUrl`) |

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

### Boot-time decisions — `Tests\Concerns\RebootsInEnvironment`

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

`globalSetup` (`tests/e2e/global-setup.js`) runs `migrate:fresh --force` before each test run — every run starts from a clean schema — then seeds `E2eSiteIdentitySeeder`.

**Why that seeder matters**: with an empty `site_identities` table the footer renders no links, so the axe-core scan never sees them and their accessibility goes unverified while the suite stays green. Seeding a fictional identity makes the existing scan cover them on all three themes. Any future feature whose markup only appears when data exists needs the same treatment.

`HomepageContentSeeder` runs next, for exactly that reason — and the rule had already been broken once before it did. With an empty `homepage_contents` table the homepage falls back to a one-sentence placeholder: the About section never renders, and the skills grid shows its fallback branch. axe-core scanned three themes without ever seeing what the CMS produces. Seeding it surfaced **373 real contrast violations** in `SkillsEditor`'s line-number gutter, plus an E2E test that only passed because the editor was empty.

The **real** seeder is used rather than an E2E twin: since LUMN-29 it reads a versioned, offline data file, so the browser suite exercises the content production will actually ship.

### Playwright — authentication
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

### Playwright — one admin session per spec file
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

### Playwright — e2e helper routes (`routes/e2e.php`)
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
Package: `larastan/larastan` (replaced the abandoned `nunomaduro/larastan` in May 2026 — update `phpstan.neon` and `phpstan-tests.neon` include paths accordingly).

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

## Linting — ESLint

Config file: `eslint.config.js` (ESLint v9 flat config).

### Scope
`resources/js/**/*.{js,vue}` — source files and Vitest unit tests at the same level (no separate config for tests).

### Rule sets (in order)
1. `@eslint/js` — `eslint:recommended`
2. `eslint-plugin-vue` — `flat/recommended` (Vue 3 rules)
3. `eslint-plugin-vuejs-accessibility` — `flat/recommended` (static WCAG a11y checks on Vue templates)
4. `eslint-config-prettier` — disables formatting rules that conflict with Prettier

Custom rules on top:
- `vue/component-api-style: ['error', ['script-setup']]` — enforces `<script setup>`, rejects Options API
- `no-var: error`, `prefer-const: error`

### Accessibility static analysis (`eslint-plugin-vuejs-accessibility`)
Checks Vue templates at lint time for ARIA and semantic HTML issues — complements the runtime axe-core scan in `tests/e2e/accessibility.spec.js`.

Key rules enforced by `flat/recommended`:
- `form-control-has-label` — every `<input>`, `<select>`, `<textarea>` must have an accessible label (via `<label>`, `aria-label`, or `aria-labelledby`)
- `anchor-has-content` — `<a>` tags must have text or an `aria-label`
- `interactive-supports-focus` — interactive elements must be keyboard-reachable

These checks fire at commit time (lint-staged) and in CI — a missing `aria-label` blocks the commit.

### Globals
Browser globals (`window`, `document`, `sessionStorage`, `fetch`…) are declared via the `globals` package (`globals.browser`).
`route` is declared as a custom global — it's the Ziggy helper injected by the `@routes` Blade directive.

### Pre-commit integration
`lint-staged` runs `eslint` (check only, no `--fix`) after `prettier --write` on staged `.js` and `.vue` files. The commit is blocked if ESLint reports errors.

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

## Homepage content and the seed pipeline

Delivered by LUMN-29. The homepage copy ships **with the deployment**, in all 24 locales, and the
translation API is never called while deploying.

### Why the translations are committed

`HomepageContentSeeder` reads `database/data/homepage-content.php` rather than holding strings of
its own. Putting `cms:translate` on the deployment path would mean a production secret, an API cost
per deploy, several minutes of latency, a half-translated page when a call fails mid-run, and a
different result every time. Committing the output removes all five, and puts every translated
string through code review — the only place the five indexable locales (`fr`, `en`, `de`, `it`,
`nl`) get read by a human before a visitor sees them.

### Regenerating the file

```bash
# 1. edit the French values in database/data/homepage-content.php
php artisan db:seed --class=HomepageContentSeeder   # answer "yes" to overwrite
php artisan cms:translate                           # the only API call
php artisan cms:export-seed                         # freeze all 24 locales back
```

Two traps this sequence exists to avoid:

- **`setTranslations()` merges, it does not replace.** Seeding new French onto an existing row
  leaves the old translations of the *previous* text sitting beside it. `cms:translate` has to run
  before the export, or the file freezes new French next to stale locales.
- **`i18n:translate --force` is currently required** to regenerate `lang/*/home.php`, because the
  checksum defect filed as LUMN-25 makes the command answer `unchanged, skipping` forever.

### `php artisan cms:export-seed`

Reads the single `HomepageContent` row and writes `database/data/homepage-content.php`. One
option: `--dry-run`, which lists what it would export and touches no file.

**It refuses more than it repairs**, and each of the three refusals is deliberate — every one
returns a non-zero exit code and writes nothing at all:

| Refusal | Why |
|---|---|
| No row in the database | Nothing to mirror; run `db:seed` then `cms:translate` first |
| A locale outside `supported_locales` | The file is `require`d at deploy time. A key the app cannot serve has no business in it, and `spatie/laravel-translatable` accepts any key it is given |
| A translatable field with no translations | Every translated column is `NOT NULL`, so a file missing one key fails **when the seeder runs it** — at deploy time, with a constraint violation naming a column and nothing else |

That third rule is the one worth remembering: a partial export is not a smaller export, it is an
unusable one. The command fails in front of a developer rather than in a deployment log.

**Locales are sorted** before writing, so two exports of the same row are byte-identical and a diff
only ever shows a content change.

**It generates executable PHP**, so both the locale key and the value are escaped for single-quoted
strings. Note in passing: the key escaping is unreachable while the validation above stands — no
test can exercise it, and deleting it keeps the suite green at 100% coverage. It is kept as belt to
the validation's braces, and `CmsExportSeed` says so in place.

**Memory is bounded by design.** The command builds the whole file in memory before writing:
acceptable because it exports one row, measured at ~55 KB across five translated columns, for one
SQL query and no relations to lazily load. Covering many records — the blog engine — would mean
writing as a stream.

### What is instrumented, and what deliberately is not

`logging-conventions.md` says "every service, controller, and job — no exceptions". Applied to this
feature that gives three answers, not one:

| Component | Logs | Why |
|---|---|---|
| `HomepageContentSeeder` | **yes**, one `info` per branch | It decides whether production ships with its content or quietly keeps what was there. A deployment's console output is usually discarded; these two lines are what answer the question afterwards |
| `CmsExportSeed` | **yes**, on both refusals and on success | Every one writes or refuses to write a file a deployment will execute |
| `HomepageContentService` | **no**, on purpose | A pure function: no I/O, no failure mode, and it runs on every homepage render. The conventions' decision tree yields nothing to log — splitting a string is neither a failure nor a business event — and one line per render would be noise at request rate |
| `HomeController` | **no** | A successful public page render is not an event, and `GeoController` does the same |

Both seeder branches are asserted by `HomepageContentSeederTest` against the three mandatory
context keys, not merely against "something was logged".

### The seeder never overwrites published content

Its `confirm()` defaults to `false`, so a non-interactive run — every deployment — populates an
empty row and otherwise does nothing. Editing live content is the admin form's job, not a
redeploy's.

### The `fallback_*` keys are not a second copy

`lang/*/home.php` still carries `fallback_tagline`, `fallback_subtitle` and `fallback_bio`, but
they are **deliberately neutral placeholders**, not the real copy. The view calls
`getTranslation($field, $locale, true)`, and spatie's fallback returns the French value for a
missing locale — **never `null`** — so `?? __('home.fallback_bio')` only ever fires when the row is
absent entirely. Duplicating the real text there would give it a second home and let the two drift.

### The bio is split, and why

`HomepageContentService::prose()` returns the first paragraph as the hero's `lead` and the rest as
`body`, which the About section renders as real `<p>` elements. Two defects made that necessary:

- **The hero held the whole bio.** At 960 characters that is roughly 435px of text, which pushed
  the buttons about 350px below the fold. Identity, hook and call to action now fit above it; the
  prose gets a section where it is allowed to be long.
- **Paragraphs were not rendering at all.** HTML collapses the blank lines the admin textarea
  produces, and the `<p>` carried no `whitespace-pre-line`, so five paragraphs came out as one
  unbroken block. Real elements were chosen over that class: screen readers announce them, and the
  spacing is controllable.

Shrinking the font was considered and rejected. `text-lg` to `text-base` buys about 11% of height —
two lines out of fifteen, with the buttons still below the fold — and the bio is
`text-base-content/70`, which the contrast table above already places near its limit at that size.
A content-length problem is not a typography problem.

`HomepageProseTest` asserts the **ordering**, not the presence: hook before buttons, buttons before
biography. Asserting presence alone stays green with the whole bio back in the hero, which is
exactly the regression worth preventing.

### `HomepageContentService::prose()` — the paragraph rules

One method, no model, no request, no locale lookup: it takes a string and returns
`['lead' => string, 'body' => list<string>]`. That purity is why the fallback resolution stays in
`HomeController` — see the comment there.

| Input | Result |
|---|---|
| Blank line between paragraphs | the separator — what the admin textarea produces |
| Several blank lines in a row | still one separator, not an empty paragraph |
| A single newline | kept inside the paragraph, not a split |
| `\r\n` or a lone `\r` | normalised to `\n` first, so both split and neither leaves a stray CR inside the prose |
| Leading and trailing whitespace | trimmed, per paragraph |
| `null`, `''`, whitespace only | `['lead' => '', 'body' => []]` — the About section then renders nothing |

A one-paragraph bio yields an empty `body`, and the About section is skipped entirely: a heading
over nothing is worse than no section.

**The separator's greediness is load-bearing.** Because `\s*\n+` swallows a whole run of blank
lines, `preg_split` cannot return an empty segment in the middle, and the leading `trim()` rules out
one at either end. The method therefore filters nothing and guards nothing — it splits, trims and
returns. Narrowing the pattern to `/\n\n/` makes empty paragraphs possible again and the About
section would render blank `<p>` elements.

That coupling is guarded rather than merely written down:
`HomepageContentServiceTest::test_no_output_paragraph_is_ever_empty()` states it as an invariant
over nine blank-line arrangements and fails on exactly that change, naming the offending input.

**Cost**: measured at 0.002 ms for the 960-character bio, with nothing retained across calls. Peak
memory runs about 3× the input — normalised copy, split array, mapped array — which on a field the
admin form caps at 1000 characters is 3 KB. A 1 MB input splits in 9.6 ms. The separator does not
backtrack: 20 000 consecutive whitespace characters return in under a millisecond with no PCRE
error.

### What the tests pin, and what they cannot

`HomepageContentSeederTest` asserts on the shipped data file, not on a fixture: the file is what a
deployment reads, so the file is what has to be right. It checks locale coverage, JSON validity of
`skills`, that seeding sends no HTTP request, and that a second run preserves edited content.

Two deliberate limits, both measured rather than assumed:

- **Duplicate detection covers `bio` and `meta_description` only.** `tagline` is identical in seven
  locales because "Neuromatrix online — biocortex actif" is a coined phrase translators correctly
  left alone. Asserting on it would fail a good decision.
- **Only the French `bio` is length-checked**, at the `max:1000` the admin form enforces on
  `bio.fr`. Translations run longer — German measures 1062 — which is harmless while the form edits
  the source locale alone, and stops being harmless the day per-locale editing ships.

## Accessibility (WCAG 2.1 AA)

All views are axe-scanned against WCAG 2.1 AA via `@axe-core/playwright` in `tests/e2e/accessibility.spec.js` (3 themes × N pages).

### Color contrast — safe opacity thresholds
Dark-themed UIs with near-black backgrounds require higher opacity than typical designs. Thresholds below apply to lighter base colors (e.g., `text-base-content`, `text-primary`).

| Text size | Utility opacity | Status |
|-----------|----------------|--------|
| 10px (`text-[10px]`) | `/40` | ❌ fails (~2.6:1) |
| 10px | `/60` | ✅ passes (~4.8:1) for light base colors |
| 12–14px (`text-xs`, `text-sm`) | `/50` | ❌ fails (~3.5–4.1:1) |
| 12–14px | `/70` | ✅ passes (~5:1) for light base colors |
| Any | `text-secondary/50` | ❌ fails — `text-secondary` is a dark mid-tone in these themes |
| Any | `text-secondary` (full opacity) | ❌ still fails in some themes (e.g., neon-noir: ~2.5:1) |

When in doubt, prefer higher opacity — the design intent of "muted" labels is preserved at `/60`–`/70`. Never assume `text-secondary` at any opacity will pass for small text against near-black backgrounds.

### Decorative elements — axe-core exemption
When an element is purely aesthetic (no semantic content, `select-none`, not meaningful for AT) and its color cannot reach 4.5:1 regardless of opacity, apply both:
- `aria-hidden="true"` — removes the element from the accessibility tree (correct for screen readers)
- `data-a11y-role="decorative"` — tells the `axeCheck()` helper to skip the element

**Important**: `aria-hidden` alone does **not** suppress axe-core's color-contrast check — axe-core scans visually rendered elements regardless of AT visibility. The `.exclude('[data-a11y-role="decorative"]')` call in `accessibility.spec.js` is what actually removes the element from the scan, aligned with the WCAG 1.4.3 exception for incidental/decorative text.

Example: the `> LIST_` terminal-style header in `SkillsEditor.vue` — pure aesthetic decoration, uses `text-secondary` which cannot reach 4.5:1 in any theme at any opacity.

## Site identity
See `docs/site-identity.md` for the full reference.

Key rules:
- `SiteIdentity` is a **single-row, untranslated model**. It was designed as a mixed one — `spatie/laravel-translatable` works **column by column**, so translating `job_title` alone was possible — and LUMN-15 deliberately undid it on 2026-09-07, with its own migration: the title cross-references the GitHub, LinkedIn and Mastodon profiles a `sameAs` points at, and those carry one hand-typed title each. `Experience` repeats the same split for the same reason.
- Profile URLs are validated on **three axes** — `url:https`, the host, **and the shape of the profile path**. A host check alone accepts `https://github.com/`, which would misinform a `sameAs` declaration.
- Query strings and fragments are stripped in `prepareForValidation()` (LinkedIn's `?trk=…`)
- Footer links carry `rel="me"` — Mastodon verifies identity by mutual link
- The contact address is **deliberately** a plain `mailto:`; the reasoning is in the doc
- Every field is nullable and the table is empty after migrating — blank fields must render nothing

## SEO conventions
See `docs/seo-conventions.md` for the full reference.

Key rules:
- **Two locale lists, two questions**: `supported_locales` (24) = what the site *serves*; `indexable_locales` (5: fr, en, de, it, nl) = what it *declares to crawlers*. Restricting the second removes no locale from the site.
- `config/seo.php` → `public_routes` is an **allowlist, never a denylist** — auth and admin routes share the `/{lang}/` prefix, so a forgotten denylist entry would publish the admin URL structure sitewide. **Add every new public route there.**
- `canonical` is **always self-referencing**, in every served locale — a non-indexed locale is distinct content, never a duplicate to redirect away
- `hreflang` requires reciprocity, so pages in non-indexed locales declare **none at all**
- `x-default` points at `/`, which `LocaleResolver` resolves from `Accept-Language`

## Logging conventions
See `docs/logging-conventions.md` for the full reference.

Key rules:
- Static log messages only — no string interpolation
- Every log call must include `service`, `method`, and `step` context keys
- Exceptions must be passed as `['exception' => $e]` (full `Throwable`, not just the message)
- Never log PII, passwords, tokens, or full request/response bodies

## Deployment
See `docs/deployment.md` for the full reference — environments, where each secret comes from, the
server settings, and the deploy sequence.

Key rules:
- **`APP_ENV=production` on every internet-facing host, preprod included.** Any other value is what
  publishes the `/e2e/admin-auth` backdoor. Name the environment after its exposure, not its role.
- **Secrets have three homes, none of them the repository**: a password manager (human), the
  server-side `.env` at `/home/cardascia-it/preprod/.env` — above the `preprod/public` docroot by
  construction — and GitHub repository secrets (pipeline). `.env.example` documents *which*
  variables exist; `docs/deployment.md` documents *where each value comes from*, with no values.
- **`ANTHROPIC_API_KEY` does not exist in preprod or production.** Since LUMN-29 the translated
  content ships with the deployment, so neither translation command runs on a server. A key that is
  never used can only be leaked.
- **One driver everywhere a browser or a deployment is involved.** `database`/`database`/`sync` for
  session, cache and queue in dev, CI E2E, preprod and prod. `phpunit.xml` keeps `array` drivers on
  purpose — in-memory doubles in a single process are what a unit harness is for, and they have no
  behaviour a real driver lacks. Redis was dropped on 2026-09-14: no job, no queue, no `Redis::`
  call anywhere in `app/`, and its asynchronous write had already cost two workarounds.
- **`public/build` is gitignored**, so `npm run build` has to run somewhere before a deployment can
  render a page.
- `config:cache` freezes `.env` — after it runs, `env()` outside a config file returns `null`.

Four defects found on 2026-09-14, before the first deployment, none reproducible on a development
machine: a missing `public/.htaccess` (Octane never reads it, Apache needs it), a missing
`trustProxies` (TLS terminated upstream → an endless login loop, not an obvious misconfiguration),
`laravel/telescope` in `require-dev` while its provider was registered unconditionally
(`composer install --no-dev` fatal at boot), and the E2E denylist above. All four are written up in
`docs/deployment.md`.

**Known gap, not yet closed**: there is no rate limiting anywhere — `grep -rn throttle routes/
app/Http/` returns nothing and `LoginRequest` never calls `ensureIsNotRateLimited()`. Password
guessing on `/{lang}/login` is currently free and unobserved. 2FA still stands between a correct
password and the admin. This must be closed before `cardascia-it.org` points at the new site.

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

## Local quality audit

`npm run check:full` (or `bash scripts/check.sh`) runs all 7 checks in sequence with a colored summary:

```
  ESLint                    ✔  2s
  Stylelint                 ✔  1s
  PHPStan (app)             ✔  6s
  PHPStan (tests)           ✔  5s
  PHPUnit                   ✔  6s
  Vitest                    ✔  3s
  Playwright E2E            ✔  21s

  All 7 checks passed  in 44s
```

Exits 0 on success, 1 on any failure (shows the relevant output for the failing step).

**Prerequisite for E2E** : the `cardascia_it_e2e` database must exist — see *Playwright — dedicated E2E database* above.

## Continuous integration (GitHub Actions)

Workflow: `.github/workflows/ci.yml` — triggered on every push and every PR targeting `main`.

Three jobs — `php` and `js` run in parallel, `e2e` runs after `php` passes:

| Job | Steps |
|-----|-------|
| `PHP — PHPStan + PHPUnit` | setup-php 8.5 (pcov) → composer install → key:generate → PHPStan app (level 8) → PHPStan tests (level 5) → `composer test:coverage` (80% threshold enforced) |
| `JS — ESLint + Stylelint + Vitest` | Node 22 → npm ci → ESLint → Stylelint → Vitest |
| `E2E — Playwright` | setup-php + Node → composer + npm install → Playwright Chromium → `npm run test:e2e` |

**PostgreSQL** : each PHP-dependent job spins up its own `postgres:16` service. The PHP job uses `cardascia_it_test`; the E2E job uses `cardascia_it_e2e`. The workflow sets `DB_PORT: 5432` which overrides the phpunit.xml default (5433).

**`PHP_INI_SCAN_DIR`** : set to `/etc/php/8.5/cli/conf.d` in the PHP job env — same issue as local. GitHub Actions runners use Ubuntu with an APT PHP install (`conf.d` layout); PHPStan workers fail with `Class "Phar" not found` without this variable. Not needed in the E2E job (PHPStan doesn't run there).

**Coverage** : `coverage: pcov` in the PHP job + `composer test:coverage` enforces the 80% line coverage threshold in CI, not just locally.

**E2E drivers** : the job used to force `SESSION_DRIVER: file`, `CACHE_STORE: file`, `QUEUE_CONNECTION: sync` and `APP_MAINTENANCE_DRIVER: file`, because `.env.example` put them all on Redis and this job has no Redis server — which made CI the one place running a different session driver from development. Since 2026-09-14 all four read `database`/`database`/`sync`/`file` straight from `.env.example`, and the overrides were removed rather than updated: restating a value that already matches is how the two drift apart again. Only `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `APP_URL`, the `DB_*` block and `ANTHROPIC_API_KEY` are still overridden.

**`needs: [php]`** on the E2E job : no point running the full browser suite if the backend is already broken.

## Branch protection and merge policy

Set up 2026-09-12. `main` is governed by a **single ruleset**, `Protection de main`
(`id 23077780`), active, with **no bypass actors** — it applies to administrators, including the
repository owner.

| Rule | Effect |
|------|--------|
| `deletion` | `main` cannot be deleted |
| `non_fast_forward` | no force-push |
| `required_linear_history` | no merge commits |
| `pull_request` | direct pushes blocked; **0 required approvals**; squash is the only allowed merge method |
| `required_status_checks` (**strict**) | the three quality checks must pass, and the branch must be up to date |

Required checks, by exact context name — a typo here produces a requirement that is never
satisfied, and the PR hangs forever:

```
PHP — PHPStan + PHPUnit
JS — ESLint + Stylelint + Vitest
E2E — Playwright
```

**`JIRA Sync` is deliberately excluded.** It is not a quality gate: it only runs on pull-request
events, and it cannot fail by design — a refused transition is a `::warning::` (see LUMN-17).
Requiring it would prove nothing.

**Zero required approvals is not an oversight.** GitHub forbids approving your own pull request, so
on a solo repository requiring even one approval locks the owner out permanently.

**Strict mode means rebasing.** Once a PR is merged, every other open PR becomes out of date and
must be rebased onto `main` before it can be merged. With one or two branches in flight this costs
seconds, and it is what catches a branch that no longer passes against the new base.

At the repository level, **squash is the only merge method** (`allow_merge_commit` and
`allow_rebase_merge` are off), and `delete_branch_on_merge` is on.

### One intention, one ticket, one PR, one commit

`main` receives exactly one squashed commit per pull request, and each carries the JIRA key of **one
intention**. Several commits inside a branch are fine — the squash collapses them. What breaks the
rule is several *pull requests* sharing one key.

When a ticket turns out to span more than one intention, it is not the rule that bends: the ticket is
at the wrong level. `Epic` exists in this project and `LUMN-3` to `LUMN-7` already use it. Convert
the parent and give each intention its own child rather than repeating a key across commits — which
is also what makes decommissioning work, since the epic then enumerates exactly what to revert.

Amending a commit already on `main` is not an option here and never will be: the ruleset carries
`non_fast_forward` and `pull_request`, with no bypass actor.

### Re-keying a pull request means opening a new one

`jira-sync.yml` extracts the key from the **branch name first**, the title second:

```bash
KEY=$(printf '%s\n%s' "$HEAD_REF" "$PR_TITLE" | grep -oiE 'LUMN-[0-9]+' | head -1 | ...)
```

So changing the title alone leaves the synchronisation aimed at the old ticket.

Renaming the branch does not fix it either, and this was measured on 2026-09-14 rather than assumed.
`POST /repos/{owner}/{repo}/branches/{branch}/rename` moved the branch and fired a `push` event —
**no `pull_request` event** — and GitHub then *closed* the pull request while keeping
`head.ref` pointing at a branch that no longer existed. Merging it would have transitioned the epic.

**Rename the branch, then open a fresh pull request from it.** The commits are untouched; only the
pull request number changes. Close the old one with a comment saying why, so the history explains
itself.

### Checking protection — do not use the classic endpoint

```bash
gh api repos/Sefalhik/lumina/rules/branches/main   # effective rules, with their ruleset_id
gh api repos/Sefalhik/lumina/rulesets              # existing rulesets
gh api repos/Sefalhik/lumina/rulesets/<id>         # detail, including bypass_actors
```

`GET /repos/{owner}/{repo}/branches/main/protection` reports **classic branch protection only** and
is blind to rulesets. On this repository it answers `404 Branch not protected` while the branch is
in fact protected — a false negative that has already produced one wrong diagnosis.

Always inspect `bypass_actors` as well: a ruleset that can be bypassed is a reminder, not a
protection. The previous ruleset had one in `always` mode, which is why it did not apply to the
owner.

## CV timeline (`Experience`)

Delivered by LUMN-18. First entity of the CV page; the pattern below is meant to be replicated for
education, certifications and skills.

### Partial translation — decided at design time

| Field | Translated | Why |
|-------|-----------|-----|
| `employer`, `location` | no | proper nouns and geography |
| `started_at`, `ended_at` | no | dates |
| **`job_title`** | **no** | it is what a `sameAs` statement cross-references with the LinkedIn profile, which carries one hand-typed title. A localised one would match in `fr` alone — the mistake LUMN-15 had to undo on `SiteIdentity` |
| `description`, `achievements` | yes | prose |

`ExperienceService` reads this split from the model's `$translatable` rather than repeating it, so
changing the model alone changes the service's behaviour — and fails the two tests that guard the
decision.

Declaring `$translatable` is only half of it: `Experience::class` must also sit in
`config('i18n.cms_models')`, or `cms:translate` never walks it. That second half was missed when
LUMN-18 shipped and added on 2026-09-13 — the model was translatable and untranslated for a day,
with nothing reporting it.

### A null `ended_at` means "still in this position"

A business state, not missing data. It is named rather than implied: `isCurrent()`,
`scopeCurrent()`, a comment on the column, and tests asserting both. See the memory rule on
explicit NULL semantics — an implicit one is re-explained at every reading.

### Ordering lives in two places, deliberately

`Experience::scopeMostRecentFirst()` orders by `started_at` then `id`; `CvService::timeline()`
sorts by `started_at` alone. The second does not override the first — **PHP 8 sorts are stable**,
so positions starting the same month keep the order the query gave them and the `id` tie-breaker
survives.

Both are load-bearing: the service sort makes the page correct whatever the caller hands over, the
scope makes it deterministic. Swapping either for an unstable sort loses the tie-breaker silently.

### The period sentence has one author

`CvService::period()` writes "04/2024 — 09/2026", or "04/2024 — aujourd'hui" while the position is
held, and **both** the public page and the admin index read it — the latter through
`ExperienceService::adminRows()`. The index used to rebuild that ternary in Blade with its own
label, so the same business rule lived in a service and in a template. Two tests now read the same
string from both pages, so a format change on one side fails unless it is made on both.

The views receive shaped arrays, never models: handing a template a model is what invited the
ternary to be written there in the first place.

### Route URLs take no explicit `lang`

`SetLocale` sets `URL::defaults(['lang' => …])` for the whole request, so
`route('admin.experiences.index')` already carries the locale. Passing `['lang' => app()->getLocale()]`
is redundant, and it invites the argument to drift from the locale actually in effect. Seven call
sites in `app/` still repeat it (auth, 2FA, homepage, identity) — pre-existing, worth a sweep.

### Query cost

The CV page issues **one query for the positions**, whatever their number — the model has no
relation, so nothing can be lazily loaded. `ExperienceTest` pins this as an *invariance* rather
than a fixed count, so the guard survives LUMN-19 adding a second section.

The weight is elsewhere: **~94 KB of JSON per row** once `cms:translate` has run, since
`spatie/laravel-translatable` stores all twenty-four locales in one column and the page loads them
all to display one. Harmless at CV scale — worth settling before the blog engine reuses the pattern
on article bodies.

### Normalisation is the framework's job

Inputs arrive trimmed: `TrimStrings` sits in the global middleware stack, immediately before
`ConvertEmptyStringsToNull`. A `prepareForValidation()` that only trims is dead code — one was
written here and removed once measured. `SiteIdentityRequest` still carries a half-dead one: its
trimming is redundant, its query-string stripping is not.
## Session brain dumps

`docs/blog-prep/` holds one Markdown file per working session — what was found, what resisted, and
the article leads that came out of it. Named `session-YYYY-MM-DD.md`, with a `b`, `c`… suffix when a
day has more than one.

**A brain dump travels with the ticket in flight — unless no ticket is in flight.**

It is not a deliverable: it documents a session, not a feature. Attaching it to a ticket it does not
implement ties a piece of prose to that ticket's fate — and if the ticket is blocked, the writing
sits in an open pull request waiting on something it has nothing to do with. That happened once, for
a day, on LUMN-13.

Nothing was actually stuck there: `jira-sync.yml` gates every transition on the expected source
state, so merging could never have moved a blocked ticket. The pull request was simply pointless.
Commit the dump alongside the work of the session that produced it and the question does not arise.

**The exception, and its condition.** When a session ends with nothing in flight — the last ticket
merged, the next not started — the dump has nothing to travel with. Holding it until the next ticket
opens is not what the rule asked for: the rule exists to keep prose off a ticket's critical path, and
there is no critical path to stay off.

It then ships alone, as a `docs:` pull request with no JIRA key, and the branch is named after the
session rather than after a ticket: `docs/session-YYYY-MM-DD`.

The condition is what makes this an exception rather than a loophole: **no ticket in flight** means
none — not "none I feel like waiting for". If a ticket is open and the dump documents its session,
the dump goes with it. The failure mode being avoided is a dump blocked by someone else's work, not a
dump that has to wait its turn.

Recorded 2026-09-14, the first time the case arose: the rule as written would have held a finished
session's writing hostage to a ticket that did not exist yet.
