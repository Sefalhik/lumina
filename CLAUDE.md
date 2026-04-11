# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**cardascia-it.org** — Personal website and portfolio for Laurent Bernard-Cardascia (lead dev).
Domain: `cardascia-it.org` (prod), `dev.cardascia-it.org` (dev).

## Commands

```bash
# Backend
php artisan serve          # Dev server (standard, port 8000)
php artisan octane:start   # Dev server via FrankenPHP (worker mode)
php artisan migrate        # Run migrations — requires explicit confirmation first
php artisan test           # Run PHPUnit test suite

# Frontend
npm run dev                # Vite HMR dev server
npm run build              # Production asset build
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
| Blog | Database-backed, custom lightweight CMS (planned) |

## Architecture

### Islands pattern
Public pages are Blade templates rendered server-side (SEO-first). Vue 3 components are mounted as **islands** — each interactive widget calls its own `createApp()` and is injected into a specific DOM node. There is no global Vue app instance for the whole page.

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
| `app/Http/Controllers/Public/` | Public-facing pages |
| `app/Http/Controllers/Dashboard/` | Private sections (auth required) |
| `app/Http/Controllers/Admin/` | CMS and user management |
| `resources/views/` | Blade templates |
| `resources/js/` | Vue island components |
| `resources/css/app.scss` | Global styles (minimal — UnoCSS handles utilities) |

### FrankenPHP / Octane notes
- Worker mode keeps the app bootstrapped between requests — avoid storing state in static properties or singletons that should reset per request.
- Config is in `config/octane.php`.
