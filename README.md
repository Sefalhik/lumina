# cardascia-it.org

Personal website and portfolio of **Laurent Bernard-Cardascia** — Lead Developer.

## Stack

| Layer | Technology |
|-------|------------|
| Backend | Laravel 13, PHP 8.4, PostgreSQL 16, Redis 7 |
| Server | FrankenPHP via Laravel Octane (worker mode) |
| Auth | Laravel Sanctum (session-based) + TOTP 2FA for admin (planned) |
| Roles | `spatie/laravel-permission` — `admin`, `maintainer`, `member`, `public` |
| Frontend | Blade (SSR/SEO) + Vue 3.5 islands (`<script setup>`) |
| Assets | Vite 8, Tailwind CSS v4, DaisyUI v5, SCSS |
| Named routes | Ziggy 2.x — `route()` helper shared from Blade to JS |
| Monitoring | Laravel Telescope (dev only — entries older than 48h pruned daily) |

## Requirements

- PHP 8.4+ with `phpredis` extension
- PostgreSQL 16 (local port: 5433)
- Redis 7+
- Node.js 20+
- [mkcert](https://github.com/FiloSottile/mkcert) for local HTTPS

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configure DB_* in .env
php artisan migrate
npm install
```

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
npm run test:e2e                # Playwright — headless (requires dev server)
npm run test:e2e:ui             # Playwright — interactive UI mode

# Tests — PHP (PHPUnit + PCOV)
composer test                   # Full test suite (Unit + Feature)
composer test:unit              # Unit suite only
composer test:coverage          # Unit suite + line coverage enforcement (≥ 80%)
```

The pre-commit hook (Husky + lint-staged) runs lint-staged, JS coverage, and PHP coverage
automatically on each `git commit`. Coverage gates block the commit if thresholds are not met.

> E2E tests require the dev server to be running: `npm start`
