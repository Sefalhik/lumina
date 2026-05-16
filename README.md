# cardascia-it.org

Personal website and portfolio of **Laurent Bernard-Cardascia** — Lead Developer.

## Stack

| Layer | Technology |
|-------|------------|
| Backend | Laravel 13, PHP 8.5, PostgreSQL 16, Redis 7 |
| Server | FrankenPHP via Laravel Octane (worker mode) |
| Auth | Laravel Sanctum (session-based) + TOTP 2FA enforced for admin |
| Roles | `spatie/laravel-permission` — `admin`, `maintainer`, `member`, `public` |
| Frontend | Blade (SSR/SEO) + Vue 3.5 islands (`<script setup>`) |
| Assets | Vite 8, Tailwind CSS v4, DaisyUI v5, SCSS |
| Named routes | Ziggy 2.x — `route()` helper shared from Blade to JS |
| Monitoring | Laravel Telescope (dev only — entries older than 48h pruned daily) |

## Requirements

- PHP 8.5+ with `phpredis` extension
- PostgreSQL 16 (local port: 5433)
- Redis 7+
- Node.js 20+
- [mkcert](https://github.com/FiloSottile/mkcert) for local HTTPS

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configure DB_* and ADMIN_* in .env (see Environment variables section below)
php artisan migrate
php artisan db:seed --class=AdminSeeder   # creates the admin role + account
npm install
```

## i18n translation commands

Two Artisan commands translate content from French (source of truth) to all 23 other EU locales via the Anthropic API. Both require `ANTHROPIC_API_KEY` in `.env`.

```bash
php artisan i18n:translate              # Translate lang/fr/*.php + resources/js/i18n/fr.json
php artisan i18n:translate --locale=de  # Single locale
php artisan i18n:translate --dry-run    # Preview without API calls or file writes
php artisan i18n:translate --force      # Bypass checksum cache

php artisan cms:translate               # Translate CMS DB content (HomepageContent…)
php artisan cms:translate --locale=de   # Single locale
php artisan cms:translate --dry-run     # Preview without API calls or DB writes
php artisan cms:translate --force       # Bypass translation cache
```

Both commands use a two-level cache in `storage/app/i18n/` (gitignored):
1. **File/value checksum** — skips files or DB fields that haven't changed since the last run
2. **Per-key TTL cache** (6 months) — only sends new/changed keys to the API

The list of CMS models to translate is declared in `config/i18n.php` under `cms_models`.

## Development

```bash
npm start
```

Starts FrankenPHP (HTTPS on `https://dev.cardascia-it.org`) and Vite HMR in parallel.

> Requires a `/etc/hosts` entry: `127.0.0.1 dev.cardascia-it.org`
> and mkcert certificates in `storage/certs/` (`cert.pem` + `key.pem`).

## Server-side geo API

The boot sequence overlay fetches visitor location through a **Laravel proxy** (`GET /api/geo`)
to avoid browser CORS and rate-limit issues with the upstream provider (ip-api.com).
The controller delegates to `GeoService`, which:

- Returns data directly from a **Redis success cache** (24 h TTL).
- Short-circuits with a **negative cache** (60 s TTL) after any failure, to avoid hammering a rate-limited API.
- Substitutes `GEO_DEV_FALLBACK_IP` in local dev, where the request IP is always `127.0.0.1`.

Relevant `.env` keys:

| Variable | Default | Description |
|---|---|---|
| `GEO_API_BASE_URL` | `http://ip-api.com/json` | Upstream provider base URL |
| `GEO_FETCH_TIMEOUT` | `3` | HTTP timeout (seconds) |
| `GEO_CACHE_TTL` | `86400` | Success cache TTL (seconds) |
| `GEO_FAILURE_CACHE_TTL` | `60` | Negative cache TTL (seconds) |
| `GEO_DEV_FALLBACK_IP` | _(empty)_ | Public IP substituted for private addresses in dev |

## Authentication

All auth routes (`/login`, `/logout`, `/two-factor/*`) are under the `/{lang}/` prefix and fully localised.

The admin access chain enforces three middleware in order: `auth` → `role:admin` → `two_factor_verified`.

| Step | Route | Description |
|------|-------|-------------|
| Login | `GET/POST /{lang}/login` | Credential check — session-based |
| 2FA setup | `GET/POST /{lang}/two-factor/setup` | First-time TOTP enrolment (QR + manual key) |
| 2FA challenge | `GET/POST /{lang}/two-factor/challenge` | Per-session TOTP verification |
| Admin | `GET /{lang}/admin` | Accessible only after all three steps |

Admin credentials are seeded from `.env`:

| Variable | Description |
|----------|-------------|
| `ADMIN_EMAIL` | Admin account email |
| `ADMIN_NAME` | Admin account display name |
| `ADMIN_PASSWORD` | Admin account password (min. 12 chars recommended) |

## Themes

Three retro-futuristic themes selectable via the navbar switcher:

| Theme | Vibe |
|-------|------|
| **Sprawl** | Gibson cyberpunk — terminal green, scanlines, RGB glitch, CRT flicker |
| **Steampunk** | Victorian industrial — brass, copper, Cinzel typography |
| **Neon Noir** | Blade Runner — cyan/magenta, animated rain, scanlines |

## Roles

| Role | Access |
|------|--------|
| `admin` | Everything — monitoring (Telescope), private tools, CMS |
| `maintainer` | Private sections in read-only |
| `member` | Semi-private sections |
| `public` | CV, blog, projects |

## Code Quality

```bash
# Linting & formatting
npm run lint:scss      # Stylelint — check SCSS
npm run lint:fix       # Stylelint — auto-fix SCSS
npm run format         # Prettier — format SCSS, Vue, JS
./vendor/bin/pint      # Laravel Pint — format PHP (PSR-12)

# Tests — JS (Vitest)
npm run test:unit               # Vitest — watch mode
npm run test:unit:run           # Vitest — single run
npm run test:unit:coverage      # Vitest — single run + coverage (80% threshold enforced)

# Tests — E2E (Playwright)
npm run test:e2e                # Playwright — headless (starts its own server on :8001)
npm run test:e2e:ui             # Playwright — interactive UI mode

# Tests — PHP (PHPUnit + PCOV)
composer test                   # Full test suite (Unit + Feature)
composer test:unit              # Unit suite only
composer test:coverage          # Unit suite + line coverage enforcement (≥ 80%)
```

The pre-commit hook (Husky + lint-staged) runs lint-staged, JS coverage, and PHP coverage
automatically on each `git commit`. Coverage gates block the commit if thresholds are not met.

### E2E prerequisite (one-time setup)

E2E tests use a dedicated database and start their own server on port 8001 — the dev server does not need to be running.

```bash
createdb -h 127.0.0.1 -p 5433 -U <your_pg_user> cardascia_it_e2e
```
