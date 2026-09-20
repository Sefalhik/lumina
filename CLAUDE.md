# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**cardascia-it.org** — Personal website and portfolio for Laurent Bernard-Cardascia (lead dev).
Domain: `cardascia-it.org` (prod), `dev.cardascia-it.org` (dev).

## Where things are documented

This file holds what must be known in every session. Each domain below has its own reference in
`docs/`: **read it before working on that domain** — the rules summarised here are not the whole of it.

| Domain | Reference | Read it before… |
|---|---|---|
| Authentication, 2FA, roles | `docs/authentication.md` | touching login, 2FA, roles, admin middleware |
| CV timeline | `docs/cv-timeline.md` | touching `Experience` or adding a CV section |
| Dependency updates | `docs/dependency-updates.md` | changing `renovate.json5`, handling a Renovate PR |
| Deployment | `docs/deployment.md` | touching environments, secrets, the server, the deploy sequence |
| Git workflow | `docs/git-workflow.md` | changing the ruleset, re-keying a PR, diagnosing a blocked merge |
| Homepage content | `docs/homepage-content.md` | touching the homepage copy, its seeder, `cms:export-seed` |
| Internationalisation | `docs/i18n.md` | adding a locale, a key, a translatable model |
| JIRA workflow | `docs/jira-workflow-reference.md` | transitioning tickets, touching `jira-sync.yml` |
| Logging | `docs/logging-conventions.md` | writing any log call |
| Quality tooling | `docs/quality-tooling.md` | changing the audit, a linter, PHPStan, CI |
| SEO | `docs/seo-conventions.md` | adding a public route, touching canonical / `hreflang` |
| Smoke tests | `docs/smoke-tests.md` | adding a probe, running `deploy:smoke`, touching `/up` or `X-Release` |
| Site identity | `docs/site-identity.md` | touching `SiteIdentity`, the footer links, `sameAs` |
| Testing | `docs/testing-conventions.md` | writing or changing a PHPUnit or Playwright test |

`docs/blog-prep/` holds session brain dumps and `docs/plans/` working plans — neither is a reference.

`tests/Feature/Documentation/DocumentationIndexTest.php` keeps this table true: a document in `docs/`
without its row fails, a row naming a missing file fails, and **this file fails past 400 lines** —
move what is consulted rather than known into `docs/`, and leave a pointer here.

**Refer to another section with a link to its anchor** — `[One driver everywhere](docs/deployment.md#one-driver-everywhere)` —
never by an italic title and never by position ("above", "below"): a moved section breaks those silently.
The same test checks every link and anchor.

## Commands

```bash
# Backend
php artisan octane:start        # Dev server via FrankenPHP (worker mode) — preferred
php artisan serve               # Dev server (standard, port 8000)
php artisan migrate             # Run migrations — requires explicit confirmation first
php artisan i18n:translate      # Translate lang/fr/*.php + resources/js/i18n/fr.json via Anthropic API
php artisan cms:translate       # Translate CMS DB content from French to EU locales via Anthropic API
php artisan cms:export-seed     # Freeze the translated homepage row into its versioned seed file
php artisan deploy:smoke --url=https://…   # Smoke-test a deployed environment — see docs/smoke-tests.md

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
| Session / cache / queue | `database` / `database` / `sync` — **the same in every environment that serves a browser**, see [Deployment](#deployment) |
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

### Authentication, 2FA and roles
See `docs/authentication.md` before touching login, 2FA, roles or the admin middleware chain.

Key rules:
- Admin routes pass `auth → role:admin → two_factor_verified`, in that order
- `EnsureTwoFactorVerified` is a three-state gate: no confirmed 2FA → setup; confirmed but not verified this session → challenge; verified → through
- Roles: `admin`, `maintainer`, `member`, public — via `spatie/laravel-permission`

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
| `app/Services/Smoke/` | `deploy:smoke` — probes a deployed environment from the outside; see `docs/smoke-tests.md` |
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
| `renovate.json5` | Renovate configuration — see `docs/dependency-updates.md` |
| `scripts/` | Dev tooling scripts (coverage check, audit, E2E preflight) — linted by ESLint, tested by Vitest in `scripts/__tests__/` |
| `app/Enums/` | PHP backed enums — single source of truth for constrained value sets, optionally shared with JS via a `forJs()` method (e.g., `SkillIcon`) |
| `app/Rules/` | Custom Laravel validation rules — framework-agnostic, fully unit-tested (e.g., `ValidSkillsJson`, `ProfileUrl`) |

### FrankenPHP / Octane notes
- Worker mode keeps the app bootstrapped between requests — avoid storing state in static properties or singletons that should reset per request.
- Config is in `config/octane.php`.

## Testing conventions
See `docs/testing-conventions.md` before writing or changing a PHPUnit or Playwright test.

Key rules:
- Test database: **PostgreSQL** `cardascia_it_test`, never SQLite; E2E runs on its own `cardascia_it_e2e` database and server (port 8001)
- **Coverage threshold enforced today: 80%**, by the pre-commit hook and CI — the target is **99%**. Measured at 100% of lines on both suites; PCOV does not measure PHP branch coverage
- Boot-time decisions are tested with `Tests\Concerns\RebootsInEnvironment`; any test about scheme, host or proxy headers addresses an explicit `http://` root and carries a negative control
- Playwright: **one admin session per spec file** that loads admin pages — any page load consumes pending flashes, read-only specs included. Specs mutating site-wide data restore it after *every* test
- Skip the boot overlay with `page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'))` before `page.goto()`
- The e2e helper routes exist in `local` and `testing` only — an allowlist, never a denylist

## Internationalisation (i18n)
See `docs/i18n.md` before adding a locale, a translation key, a translatable model, or touching `i18n:translate` / `cms:translate`.

Key rules:
- Three layers: Blade `__()` from `lang/`, Vue islands through `vue-i18n` JSON files, CMS content through `spatie/laravel-translatable` JSON columns
- The 24 EU locales are defined once, in `config/i18n.php`; French is the source of truth everywhere
- Vue: `useI18n()` in `<script setup>`, keys in English scoped by component (`theme_switcher.*`), one `createI18n()` per island
- A translatable model must also be listed in `config('i18n.cms_models')`, or `cms:translate` never walks it
- `i18n:translate --force` is required until LUMN-25 is fixed — the checksum answers `unchanged` forever

## Homepage content and the seed pipeline
See `docs/homepage-content.md` before touching the homepage copy, its seeder or `cms:export-seed`.

Key rules:
- The homepage copy ships **with the deployment**, in all 24 locales, from `database/data/homepage-content.php` — the translation API is never called while deploying
- Regenerating it is three steps, in order: edit French and `db:seed --class=HomepageContentSeeder`, then `cms:translate`, then `cms:export-seed`. **`setTranslations()` merges**: skipping `cms:translate` freezes new French beside stale locales
- The seeder **never overwrites** published content — editing live copy is the admin form's job, not a redeploy's
- The `fallback_*` keys of `lang/*/home.php` are deliberately neutral placeholders, never a second copy of the real text

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

## Quality tooling
See `docs/quality-tooling.md` before changing the audit script, a linter config, PHPStan or the CI workflow.

Key rules:
- `npm run check:full` runs the 7 checks; its Playwright step first runs the **E2E preflight**, which refuses — with the command to run — on stale assets, a dead Vite server or a missing browser
- PHPStan: level 8 on `app/`, level 5 on `tests/`; **never** `@phpstan-ignore` without a written justification; `PHP_INI_SCAN_DIR` must be exported or workers fail with `Class "Phar" not found`
- ESLint covers `resources/js/` and `scripts/`; its accessibility rules block commits on a missing label
- CI: three required checks — `PHP — PHPStan + PHPUnit`, `JS — ESLint + Stylelint + Vitest`, `E2E — Playwright`; the E2E job builds assets and installs the browser itself

## Dependency updates (Renovate)
See `docs/dependency-updates.md` for the full reference — installation, day-to-day use, and how to
test a configuration change before merging it.

Key rules:
- Configuration lives in `renovate.json5`; Renovate runs as the hosted Mend GitHub App, never in CI
- Minor and patch updates are grouped per ecosystem; **every major waits for approval** on the Dependency Dashboard
- **Runtime majors are never Renovate PRs** — Node and PostgreSQL in CI, and the `"php"` constraint, are disabled: moving CI alone would test a runtime production does not run
- **Schedule windows stay at 24 hours or more**: the free app visits once a day on an inactive repository
- Routine Renovate PRs carry no JIRA key, on purpose; a major gets a human ticket
- Testing a config change locally: **`git add` it first**, or the dry run silently ignores it

## Branch protection and merge policy
See `docs/git-workflow.md` before changing the ruleset, re-keying a pull request, or diagnosing a blocked merge.

Key rules:
- `main` is protected by one ruleset with **no bypass actor**: no direct push, no force-push, squash only, three required checks in strict mode
- **One intention, one ticket, one PR, one commit.** Several PRs sharing a JIRA key break the rule; a ticket spanning two intentions is at the wrong level — split it
- Strict mode: once a PR merges, rebase the others before merging them
- Re-keying a PR means **renaming the branch and opening a new PR** — `jira-sync.yml` reads the branch name first
- Check protection with `gh api repos/Sefalhik/lumina/rules/branches/main`, never the classic `/protection` endpoint — it is blind to rulesets

## CV timeline (`Experience`)
See `docs/cv-timeline.md` before touching `Experience`, `CvService`, `ExperienceService`, or adding a CV section — it is the pattern to replicate.

Key rules:
- Partial translation, decided at design time: prose is translated, `job_title` is not — it is a `sameAs` cross-reference key
- A null `ended_at` means **"still in this position"**, named by `isCurrent()` and `scopeCurrent()`, never implied
- The period sentence has one author, `CvService::period()`, read by both the public page and the admin index
- Views receive shaped arrays, never models; route URLs take no explicit `lang`

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
