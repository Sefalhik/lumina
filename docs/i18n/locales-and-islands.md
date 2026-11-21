# Internationalisation — Locales, routing and islands

Part of [Internationalisation](README.md).

## Supported locales
All 24 official EU languages: `bg cs da de el en es et fi fr ga hr hu it lt lv mt nl pl pt ro sk sl sv`

Configured in `config/i18n.php` — the single source of truth for locale codes, native names, default locale, and cache path.

## Routing
All public routes are prefixed by `/{lang}/`. The `where` constraint on the prefix uses the supported locales list from config. The root route `/` redirects to the preferred locale via `LocaleResolver`.

## LocaleResolver (`app/Services/LocaleResolver.php`)
Iterates `$request->getLanguages()` (pre-sorted by q-factor by Symfony) and returns the first base code found in `$supported`. Falls back to `$default` when nothing matches.

Note: `getPreferredLanguage($locales)` always returns `$locales[0]` as fallback — never `null` — so `?? $default` would be dead code. Use `getLanguages()` + explicit iteration for real fallback control.

## Vue i18n — conventions
- Locale is read from `document.documentElement.lang` (set by Laravel on `<html>`)
- Each island gets a fresh instance via `createI18n()` (`resources/js/utils/i18n.js`)
- Locale files are loaded dynamically with `import.meta.glob('../i18n/*.json', { eager: true })` — adding a new locale requires only creating the JSON file
- `legacy: false` — Composition API only, `useI18n()` in `<script setup>`
- Keys are in English, scoped by component: `theme_switcher.*`, `boot.*`, `language_switcher.*`…
- Fallback locale: `fr`

## Adding an island with i18n
```js
// app.js
import { createI18n } from './utils/i18n.js';
createApp(MyComponent).use(createI18n()).mount(el);
```
```js
// MyComponent.vue
const { t } = useI18n();
```
Add keys to `resources/js/i18n/fr.json` — `i18n:translate` propagates them to the other locales: [Adding a key](translation.md#adding-a-key).
