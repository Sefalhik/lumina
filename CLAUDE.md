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

# Testing — PHP
php artisan test                # Full PHPUnit suite (no coverage)
composer test:unit              # Unit suite only (no coverage)
composer test:coverage          # Unit suite + PCOV coverage report + 80% minimum threshold

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
| Backend | Laravel 13 (v13.4+), PHP 8.4, MariaDB |
| Server | FrankenPHP via Laravel Octane (worker mode) |
| Auth | Laravel Sanctum (session-based) + TOTP 2FA for admin (planned) |
| Roles | `spatie/laravel-permission` — roles: `admin`, `maintainer`, `member`, public |
| Frontend | Blade (structure/SEO) + Vue 3.5 islands (`<script setup>`) |
| Assets | Vite 8 + SCSS + UnoCSS (presetWind3 + presetMini) |
| Routes (JS) | `tightenco/ziggy` — `route('name')` helper available in all JS via `@routes` directive |
| Testing (PHP) | PHPUnit 12, PCOV coverage driver |
| Testing (JS) | Vitest 4 + `@vitest/coverage-v8`, Playwright for E2E |
| Blog | Database-backed, custom lightweight CMS (planned) |

## Architecture

### Islands pattern
Public pages are Blade templates rendered server-side (SEO-first). Vue 3 components are mounted as **islands** — each interactive widget calls its own `createApp()` and is injected into a specific DOM node. There is no global Vue app instance for the whole page.

### API routes
A dedicated `routes/api.php` file handles JSON endpoints, registered under the `/api` prefix. JS code must reference routes by name via Ziggy's `route()` helper — never hardcode URLs.

### Services
Business logic lives in `app/Services/`. Services are framework-agnostic classes, fully unit-testable without HTTP context.

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
| `app/Http/Controllers/Public/` | Public-facing controllers |
| `app/Http/Controllers/Dashboard/` | Private sections (auth required) |
| `app/Http/Controllers/Admin/` | CMS and user management |
| `app/Services/` | Business logic — unit-tested, no HTTP dependency |
| `resources/views/` | Blade templates |
| `resources/js/` | Vue island components + utilities |
| `resources/js/utils/` | Pure JS utility modules (unit-tested) |
| `resources/css/app.scss` | Global styles (minimal — UnoCSS handles utilities) |
| `config/geo.php` | Geo API proxy configuration (env-driven) |
| `docs/` | Technical documentation |
| `scripts/` | Dev tooling scripts (coverage check, etc.) |

### FrankenPHP / Octane notes
- Worker mode keeps the app bootstrapped between requests — avoid storing state in static properties or singletons that should reset per request.
- Config is in `config/octane.php`.

## Testing conventions

### PHP — coverage scope (`phpunit.xml`)
Only `app/Services/` is in scope for unit coverage. The following are deliberately excluded:
- `app/Http/Controllers/` — thin orchestrators, tested via Feature (HTTP) tests
- `app/Providers/` — bootstrapping code
- `app/Models/` — tested via Feature tests against real DB

### JS — coverage scope (`vitest.config.js`)
Only `resources/js/utils/**/*.js` is in scope for unit coverage:
- `app.js` is an entry point (Vue island mounting), not unit-testable
- Vue components are covered by Playwright E2E tests

### Coverage thresholds
Both suites enforce **80% line coverage minimum** — commits are blocked by the pre-commit hook if the threshold is not met.

### Pre-commit hook
Runs automatically on `git commit`:
1. `lint-staged` — format checks
2. `npm run test:unit:coverage` — JS unit tests + coverage threshold
3. `composer test:coverage` — PHP unit tests + coverage threshold

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
