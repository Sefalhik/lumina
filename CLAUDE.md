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
npm start                       # Dev server: FrankenPHP (worker mode) on https://dev.cardascia-it.org + Vite — preferred
php artisan octane:start        # Variant: Octane's own Caddyfile, plain HTTP on 127.0.0.1:8000 — see below
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

`npm start` is the reference dev server; how it reloads PHP, and why not `octane:start`:
[The development server](docs/architecture.md#the-development-server).

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
Admin routes pass `auth → role:admin → two_factor_verified`, in that order —
[Authentication](docs/authentication.md).

### Routes under `{lang}`
Every public and admin route sits under `Route::prefix('{lang}')`. `SetLocale` removes the `lang`
parameter once the locale is set, so **a controller never takes a `string $lang` argument**, and URLs
are built without an explicit `lang`. Why it is safe:
[Route parameters and the `{lang}` prefix](docs/architecture.md#route-parameters-and-the-lang-prefix).

### Where things live
The map of the repository is [Key directories](docs/architecture.md#key-directories). **A new
service, model, controller or rule gets its row there in the same ticket that creates it.**

### FrankenPHP / Octane notes
Worker mode keeps the app bootstrapped between requests — avoid storing state in static properties or
singletons that should reset per request.

## Testing conventions
See `docs/testing-conventions.md` before writing or changing any test — PHPUnit, Vitest or Playwright. It opens with [the doctrine](docs/testing-conventions.md#the-doctrine): each thing tested at its lowest level, past the happy path, and **a test is only trusted once breaking the code has turned it red** — by hand, and with [the mechanical pass](docs/testing-conventions.md#the-mechanical-pass), `node scripts/mutate-lines.js <file> -- <test command>`.

Key rules:
- Test database: **PostgreSQL** `cardascia_it_test`, never SQLite
- **Coverage threshold enforced today: 80%**, by the pre-commit hook and CI — the target is **99%** (LUMN-57), the doctrine's bar 100% for logic

## Internationalisation (i18n)
French is the source of truth: Blade `__()` from `lang/`, Vue islands through `useI18n()` and their
JSON files, CMS content through translatable JSON columns — [i18n](docs/i18n.md).

## Accessibility (WCAG 2.1 AA)
Every view is axe-scanned in three themes (`tests/e2e/accessibility.spec.js`). Near-black backgrounds
need high opacity: small muted text at `/60`–`/70`, never `text-secondary`. A purely decorative
element takes **both** `aria-hidden="true"` and `data-a11y-role="decorative"` — the first alone does
not stop axe. Thresholds and the reasoning: [Accessibility](docs/accessibility.md).
Every visitor can stop the animations (WCAG 2.2.2, level A): **a looping effect ships with its stopped
state** in `when-motion-stopped` —
[Motion — every visitor can stop it](docs/accessibility.md#motion--every-visitor-can-stop-it).

## SEO conventions
**Every new public route goes into `config/seo.php` → `public_routes`**, an allowlist. Indexing is
decided by `SITE_INDEXABLE`, never by `APP_ENV` — [SEO](docs/seo-conventions.md).

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
- **No secret in the repository**: `.env.example` says *which* variables exist,
  [Environment variables](docs/environment-variables.md) where each value comes from.
- **One driver everywhere**: `database`/`database`/`sync` for session, cache and queue —
  [One driver everywhere](docs/deployment.md#one-driver-everywhere).
- **Never edit `current/` in place**; going back is `rollback.sh`. The first lines of
  `public/index.php` are not boilerplate: OPcache does not follow the release link.
- `config:cache` freezes `.env` — after it runs, `env()` outside a config file returns `null`.

## Quality tooling
See `docs/quality-tooling.md` before changing the audit script, a linter config, PHPStan or the CI workflow.

Key rules:
- `npm run check:full` runs the 7 checks, and is the verdict before a pull request
- PHPStan: level 8 on `app/`, level 5 on `tests/`; **deprecated code is an error**; **never** `@phpstan-ignore` without a written justification

## Branch protection and merge policy
See `docs/git-workflow.md` before changing the ruleset, re-keying a pull request, or diagnosing a blocked merge.

Key rules:
- **One intention, one ticket, one PR, one commit.** A pull request without a key is titled `[HORS-TICKET] …`: [Pull requests that carry no key](docs/git-workflow.md#pull-requests-that-carry-no-key)
- Strict mode: once a PR merges, rebase the others before merging them
- Re-keying a PR means **renaming the branch and opening a new PR** — `jira-sync.yml` reads the branch name first

## Session brain dumps
One file per working session in `docs/blog-prep/`, `session-YYYY-MM-DD.md`. **It travels with the
ticket in flight**; only when none is in flight does it ship alone, as a `[HORS-TICKET]` PR from
`docs/session-YYYY-MM-DD`: [Session brain dumps](docs/git-workflow.md#session-brain-dumps).
