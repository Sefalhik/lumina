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
| Accessibility | `docs/accessibility.md` | choosing a text colour or opacity, marking an element decorative |
| Architecture | `docs/architecture.md` | looking for where something lives, creating a service, model, controller or rule |
| Authentication, 2FA, roles | `docs/authentication.md` | touching login, 2FA, roles, admin middleware |
| CV timeline | `docs/cv-timeline.md` | touching `Experience` or adding a CV section |
| Dependency updates | `docs/dependency-updates.md` | changing `renovate.json5`, handling a Renovate PR, touching `update-frankenphp.sh` |
| Deployment | `docs/deployment.md` | touching environments, secrets, the server, the deploy sequence |
| Environment variables | `docs/environment-variables.md` | adding a variable, or asking where a value comes from |
| Git workflow | `docs/git-workflow.md` | changing the ruleset, re-keying a PR, diagnosing a blocked merge, shipping a brain dump |
| Homepage content | `docs/homepage-content.md` | touching the homepage copy, its seeder, `cms:export-seed` |
| Internationalisation | `docs/i18n.md` | adding a locale, a key, a translatable model |
| JIRA workflow | `docs/jira-workflow-reference.md` | creating, estimating or transitioning a ticket, touching `jira-sync.yml` |
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
php artisan octane:start        # Dev server via FrankenPHP (worker mode) — preferred
php artisan migrate             # Run migrations — requires explicit confirmation first
composer analyse                # PHPStan: app/ (level 8) then tests/ (level 5)
php artisan test                # Full PHPUnit suite (no coverage)
composer test:coverage          # Unit + Feature suites + PCOV coverage report + 80% minimum threshold
npm run test:unit:run           # Vitest unit suite (no coverage)
npm run test:e2e                # Playwright end-to-end suite
npm run check:full              # ESLint + Stylelint + PHPStan + PHPUnit + Vitest + Playwright (~40s)
npm run lint:js                 # ESLint (lint:js:fix to fix) — resources/js/**/*.{js,vue}
npm run lint:scss               # Stylelint (lint:fix to fix) — SCSS
npm run format                  # Prettier — SCSS, Vue, JS
npm run dev                     # Vite HMR dev server
npm run build                   # Production asset build
```

The rarer commands live with their domain: translation in [i18n](docs/i18n.md), `cms:export-seed`
in [Homepage content](docs/homepage-content.md), `deploy:smoke` in [Smoke tests](docs/smoke-tests.md),
`update:frankenphp` in [The FrankenPHP binary](docs/dependency-updates.md#the-frankenphp-binary).

## Stack

| Layer | Choice |
|-------|--------|
| Backend | Laravel 13 (v13.9+), PHP 8.5 CLI + FrankenPHP, PostgreSQL |
| Server | FrankenPHP via Laravel Octane (worker mode) in development; **Apache + PHP over FastCGI** (`cgi-fcgi`, not FPM) on alwaysdata — see `docs/deployment.md` |
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

### Routes under `{lang}`
Every public and admin route sits under `Route::prefix('{lang}')`. `SetLocale` removes the `lang`
parameter once the locale is set, so **a controller never takes a `string $lang` argument**, and URLs
are built without an explicit `lang`. Why it is safe:
[Route parameters and the `{lang}` prefix](docs/architecture.md#route-parameters-and-the-lang-prefix).

### Where things live
The map of the repository is [Key directories](docs/architecture.md#key-directories). **A new
service, model, controller or rule gets its row there in the same ticket that creates it.**

### FrankenPHP / Octane notes
- Worker mode keeps the app bootstrapped between requests — avoid storing state in static properties or singletons that should reset per request.
- Config is in `config/octane.php`.

## Testing conventions
See `docs/testing-conventions.md` before writing or changing any test — PHPUnit, Vitest or Playwright. It opens with [the doctrine](docs/testing-conventions.md#the-doctrine): each thing tested at its lowest level, past the happy path, and **a test is only trusted once breaking the code has turned it red** — by hand, and with [the mechanical pass](docs/testing-conventions.md#the-mechanical-pass), `node scripts/mutate-lines.js <file> -- <test command>`.

Key rules:
- Test database: **PostgreSQL** `cardascia_it_test`, never SQLite; E2E runs on its own `cardascia_it_e2e` database and server (port 8001)
- **Coverage threshold enforced today: 80%**, by the pre-commit hook and CI — the target is **99%** (LUMN-57), the doctrine's bar 100% for logic. PHP stood at 99.75% of lines on 2026-10-07; PCOV does not measure PHP branch coverage
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
See `docs/homepage-content.md`. The copy ships **with the deployment**, in 24 locales, from
`database/data/homepage-content.php` — the translation API is never called while deploying.
Regenerating it is three steps, in order: seed the French, `cms:translate`, `cms:export-seed`. The
seeder **never overwrites** published content.

## Accessibility (WCAG 2.1 AA)
Every view is axe-scanned in three themes (`tests/e2e/accessibility.spec.js`). Near-black backgrounds
need high opacity: small muted text at `/60`–`/70`, never `text-secondary`. A purely decorative
element takes **both** `aria-hidden="true"` and `data-a11y-role="decorative"` — the first alone does
not stop axe. Thresholds and the reasoning: [Accessibility](docs/accessibility.md).

## Site identity
See `docs/site-identity.md`. `SiteIdentity` is a **single-row, untranslated model** — `job_title`
included, since it cross-references the `sameAs` profiles (LUMN-15). Profile URLs are validated on
**three axes**: `url:https`, the host, and the shape of the profile path. Every field is nullable:
blank fields render nothing.

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
  server-side `.env` at `/home/cardascia-it/lumina/preprod/shared/.env`, and GitHub **environment**
  secrets (pipeline; its SSH key is tied to `scripts/deploy-gate.sh`). `.env.example` says *which*
  variables exist; `docs/deployment.md` says *where each value comes from*, with no values.
- **`ANTHROPIC_API_KEY` does not exist in preprod or production.** Translated content ships with the
  deployment (LUMN-29); a key that is never used can only be leaked.
- **One driver everywhere a browser or a deployment is involved**: `database`/`database`/`sync` for
  session, cache and queue. `phpunit.xml` keeps `array` drivers on purpose —
  [One driver everywhere](docs/deployment.md#one-driver-everywhere).
- **A release is built in its own directory and put in service by one link** (`releases/`, `shared/`,
  `current`): never edit `current/` in place; going back is `rollback.sh`. **OPcache does not follow
  that link**: the first lines of `public/index.php` are not boilerplate, and `FrontControllerTest`
  fails without them.
- `config:cache` freezes `.env` — after it runs, `env()` outside a config file returns `null`.

**Known gap, not yet closed**, blocking production (LUMN-54): `/up` has no ceiling though each call
queries the database (LUMN-92). Login and 2FA attempts are limited (LUMN-36) and a code is accepted
once (LUMN-91) — [Rate limiting](docs/authentication.md#rate-limiting).

## Environment variables
[Environment variables](docs/environment-variables.md) says what each variable means and where its
value comes from; `EnvironmentVariablesTest` fails on a variable of `.env.example` missing there. Some
are [deliberately absent from preprod and production](docs/environment-variables.md#deliberately-absent-from-preprod-and-production).

## Quality tooling
See `docs/quality-tooling.md` before changing the audit script, a linter config, PHPStan or the CI workflow.

Key rules:
- `npm run check:full` runs the 7 checks; its Playwright step first runs the **E2E preflight**, which refuses — with the command to run — on stale assets, a dead Vite server or a missing browser
- PHPStan: level 8 on `app/`, level 5 on `tests/`, and **deprecated code is an error** — statically here, and at runtime in the test suite, where Laravel would otherwise drop it; **never** `@phpstan-ignore` without a written justification; `PHP_INI_SCAN_DIR` must be exported or workers fail with `Class "Phar" not found`
- ESLint covers `resources/js/` and `scripts/`; its accessibility rules block commits on a missing label
- CI: the required checks are listed once, in [Required checks](docs/git-workflow.md#required-checks) — PHP, JS, E2E and a dependency review that fails a PR *introducing* a vulnerable package. Dependabot alerts, CodeQL and secret scanning are **repository settings**, and the alerts do **not** cover every known flaw: a daily, non-required `security-audit.yml` fails on any advisory absent from `accepted-advisories.json`, where each acceptance carries a reason and a review date; PHP has no security analysis (LUMN-66)

## Dependency updates (Renovate)
See `docs/dependency-updates.md`. Renovate runs as the hosted Mend app from `renovate.json5`. Minor
and patch updates are grouped per ecosystem; **every library major waits for approval**; runtime
majors (Node, PostgreSQL, PHP) are never a Renovate PR. An npm or Actions release waits **3 days**,
written in `renovate.json5` and `.npmrc` and held together by `ReleaseAgeParityTest`. Testing a
config change locally: **`git add` it first**, or the dry run silently ignores it.

## Branch protection and merge policy
See `docs/git-workflow.md` before changing the ruleset, re-keying a pull request, or diagnosing a blocked merge.

Key rules:
- `main` is protected by one ruleset with **no bypass actor**: no direct push, no force-push, squash only, [required checks](docs/git-workflow.md#required-checks) in strict mode
- **One intention, one ticket, one PR, one commit.** Several PRs sharing a JIRA key break the rule; a ticket spanning two intentions is at the wrong level — split it. Four kinds of PR carry no key on purpose, documentation-only ones included, and every one a person opens is titled `[HORS-TICKET] …`: [Pull requests that carry no key](docs/git-workflow.md#pull-requests-that-carry-no-key)
- Strict mode: once a PR merges, rebase the others before merging them
- Re-keying a PR means **renaming the branch and opening a new PR** — `jira-sync.yml` reads the branch name first
- Check protection with `gh api repos/Sefalhik/lumina/rules/branches/main`, never the classic `/protection` endpoint — it is blind to rulesets

## CV timeline (`Experience`)
See `docs/cv-timeline.md` — it is the pattern to replicate for a new CV section. Prose is translated,
`job_title` is not; a null `ended_at` means **"still in this position"**, named by `isCurrent()`,
never implied; the period sentence has one author, `CvService::period()`; views receive shaped
arrays, never models.

## Session brain dumps
One file per working session in `docs/blog-prep/`, `session-YYYY-MM-DD.md`. **It travels with the
ticket in flight**; only when none is in flight does it ship alone, as a `[HORS-TICKET]` PR from
`docs/session-YYYY-MM-DD`: [Session brain dumps](docs/git-workflow.md#session-brain-dumps).
