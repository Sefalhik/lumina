# Internationalisation

Three separate layers, each with its own tooling:

| Layer | Tool | Files |
|-------|------|-------|
| Strings UI — Blade | Laravel `lang/` native (`__()`) | `lang/fr/*.php`, `lang/{locale}/*.php` |
| Strings UI — Vue islands | `vue-i18n` v11 (`useI18n`) | `resources/js/i18n/*.json` (one file per locale) |
| CMS content (blog, projects…) | `spatie/laravel-translatable` | JSON columns in PostgreSQL |

| Part | What it holds |
|---|---|
| [locales-and-islands.md](locales-and-islands.md) | Locales, routing and islands |
| [translation.md](translation.md) | The translation commands |
