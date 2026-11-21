# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**cardascia-it.org** — Personal website and portfolio for Laurent Bernard-Cardascia (lead dev).
Domain: `cardascia-it.org` (prod), `dev.cardascia-it.org` (dev).

## Where things are documented

The documentation has three tiers, after Claude Code's own model (LUMN-150): this file, loaded in
every session; the **rules** of `docs/rules/`, each loaded by itself when a file of its domain is read
or written; and the references below, which the rules link to for the reasons and the details.
**Read a reference before working on its domain** — a rule says what to do, not why.

| Domain | Reference | Read it before… |
|---|---|---|
| Accessibility | `docs/accessibility.md` | choosing a text colour or opacity, marking an element decorative |
| Architecture | `docs/architecture.md` | looking for where something lives, creating a service, model, controller or rule |
| Authentication, 2FA, roles | `docs/authentication.md` | touching login, 2FA, roles, admin middleware |
| CV timeline | `docs/cv-timeline.md` | touching `Experience` or adding a CV section |
| Dependency updates | `docs/dependencies/README.md` | changing `renovate.json5`, handling a Renovate PR, touching `update-frankenphp.sh` |
| Deployment | `docs/deployment/README.md` | touching environments, secrets, the server, the deploy sequence |
| Environment variables | `docs/environment-variables.md` | adding a variable, or asking where a value comes from |
| Git workflow | `docs/git-workflow.md` | changing the ruleset, re-keying a PR, diagnosing a blocked merge, shipping a brain dump |
| Homepage content | `docs/homepage-content.md` | touching the homepage copy, its seeder, `cms:export-seed` |
| Internationalisation | `docs/i18n/README.md` | adding a locale, a key, a translatable model |
| JIRA workflow | `docs/jira-workflow-reference.md` | creating, estimating or transitioning a ticket, touching `jira-sync.yml` |
| Logging | `docs/logging-conventions.md` | writing any log call |
| Rules | `docs/rules/README.md` | wondering which rule applies where, or adding one |
| Quality tooling | `docs/quality/README.md` | changing the audit, a linter, PHPStan, CI |
| SEO | `docs/seo-conventions.md` | adding a public route, touching canonical / `hreflang` |
| Smoke tests | `docs/smoke-tests/README.md` | adding a probe, running `deploy:smoke`, touching `/up` or `X-Release` |
| Site identity | `docs/site-identity.md` | touching `SiteIdentity`, the footer links, `sameAs` |
| Testing | `docs/testing/README.md` | writing or changing a PHPUnit or Playwright test |

`docs/blog-prep/` holds session brain dumps and `docs/plans/` working plans — neither is a reference.

`tests/Feature/Documentation/DocumentationIndexTest.php` keeps this table true: a document in `docs/`
without its row — or, in a subdirectory, without its link from that directory's `README.md` — fails,
a row naming a missing file fails, **this file fails past 200 lines**, and **any document past
15 KB**. What applies to one part of the code belongs in a rule; what is consulted rather than known,
in a reference.

**Refer to another section with a link to its anchor** — `[One driver everywhere](docs/deployment/environments.md#one-driver-everywhere)` —
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
npm run check:full              # ESLint + Stylelint + Pint + PHPStan + PHPUnit + Vitest + Playwright (~45s)
npm run lint:js                 # ESLint (lint:js:fix to fix) — resources/js/**/*.{js,vue}
npm run lint:scss               # Stylelint (lint:fix to fix) — SCSS
npm run format                  # Prettier — SCSS, Vue, JS
npm run dev                     # Vite HMR dev server
npm run build                   # Production asset build
```

`npm start` is the reference dev server; how it reloads PHP, and why not `octane:start`:
[The development server](docs/architecture.md#the-development-server).

The rarer commands live with their domain: translation in [i18n](docs/i18n/README.md), `cms:export-seed`
in [Homepage content](docs/homepage-content.md), `deploy:smoke` in [Smoke tests](docs/smoke-tests/README.md),
`update:frankenphp` in [The FrankenPHP binary](docs/dependencies/frankenphp.md#the-frankenphp-binary).

## Stack

| Layer | Choice |
|-------|--------|
| Backend | Laravel 13 (v13.9+), PHP 8.5 CLI + FrankenPHP, PostgreSQL |
| Server | FrankenPHP via Laravel Octane (worker mode) in development; **Apache + PHP over FastCGI** (`cgi-fcgi`, not FPM) on alwaysdata — see `docs/deployment/README.md` |
| Session / cache / queue | `database` / `database` / `sync` — **the same in every environment that serves a browser**, see [the deployment rules](docs/rules/deployment.md) |
| Auth | Laravel Sanctum (session-based) + TOTP 2FA enforced for admin |
| Roles | `spatie/laravel-permission` — roles: `admin`, `maintainer`, `member`, public |
| Frontend | Blade (structure/SEO) + Vue 3.5 islands (`<script setup>`) |
| Assets | Vite 8 + SCSS + Tailwind 4 (`@tailwindcss/vite`) + DaisyUI 5 |
| Routes (JS) | `tightenco/ziggy` — `route('name')` helper available in all JS via `@routes` directive |
| Testing (PHP) | PHPUnit 13, PCOV for line coverage; Xdebug, off by default, for branches on demand — [Branches — Xdebug, on demand](docs/testing/doctrine.md#branches--xdebug-on-demand) |
| Testing (JS) | Vitest 4 + `@vitest/coverage-v8`, Playwright for E2E |
| Blog | Database-backed, custom lightweight CMS (planned) |

## Architecture

Blade renders the pages, for SEO; Vue 3 **islands** add the interaction, each with its own
`createApp()`; business logic lives in `app/Services/` and `resources/js/utils/`, never in a
controller, a model or a component. The rules [backend](docs/rules/backend.md) and
[frontend](docs/rules/frontend.md) load with the code they govern; the map of the repository is
[Key directories](docs/architecture.md#key-directories).

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
