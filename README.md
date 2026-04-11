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

# Tests
npm run test:unit      # Vitest — Vue component unit tests (watch mode)
npm run test:unit:run  # Vitest — single run
npm run test:e2e       # Playwright — E2E + accessibility (requires dev server)
npm run test:e2e:ui    # Playwright — interactive UI mode
php artisan test       # PHPUnit — backend test suite
```

Husky runs lint-staged automatically on each `git commit`.

> E2E tests require the dev server to be running: `npm start`
