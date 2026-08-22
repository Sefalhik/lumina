# SEO — conventions

Reference for canonical and `hreflang` handling on cardascia-it.org.

## Two locale lists, two different questions

`config/i18n.php` holds **two** locale lists. Confusing them is the main trap in this area.

| Key | Answers | Size |
|-----|---------|------|
| `supported_locales` | What does the site **serve**? | 24 |
| `indexable_locales` | What does the site **declare to crawlers**? | 5 |

`supported_locales` drives routing (`/{lang}/` constraint), the language switcher, and both
translation commands. It stays at all 24 official EU languages.

`indexable_locales` drives `hreflang` only — currently `fr`, `en`, `de`, `it`, `nl`.

**Restricting the second list does not remove any locale from the site.** A visitor on `/mt/`
still gets a fully rendered Maltese page; `i18n:translate` still feeds it; the switcher still
offers it. Only the set of languages advertised to search engines shrinks.

### Why not advertise all 24

Not because machine translation is penalised — it is not. Google's spam policy targets scaled
production of content with no user value, not the translation method, and Google has withdrawn
its former advice to block auto-translated pages via `robots.txt`.

The reason is that it does not work. Search engines collapse equivalent content across
languages and serve a single version of a given concept — usually the one carrying the most
authority. A translated page that adds no market-specific intent is almost never served.
Declaring 24 languages multiplies crawl surface by 24 for a site with no established authority,
and returns nothing.

`indexable_locales` must be a **subset** of `supported_locales`. Entries outside it are dropped
at runtime and logged as a warning — the site keeps working, but the configuration is wrong and
says so.

## Public routes are an allowlist

`config/seo.php` → `public_routes` lists the route names allowed to carry SEO tags.

This is deliberately an **allowlist, never a denylist**, because auth and admin routes live
under the same `/{lang}/` prefix as public pages.

Consider the failure mode of each:

- **Allowlist** — forget to add a new public route: that page loses its SEO tags. Silent,
  harmless, fixed in one line.
- **Denylist** — forget to exclude a new admin route: the admin URL structure gets published in
  the `<head>` of every page on the site.

The asymmetry decides it. **When a new public page ships, add its route name to
`config/seo.php`.**

## canonical and hreflang follow different rules

They are not two halves of the same feature.

**`canonical` is always self-referencing**, in every served locale — indexed or not. A Maltese
page is *distinct content*, not a duplicate of the French one. Pointing its canonical at `/fr/`
would ask search engines to drop 19 languages from the index.

**`hreflang` requires reciprocity.** Every declared variant must reference all the others,
itself included. A page in a non-indexed locale therefore declares **no** `hreflang` at all: if
`/mt/` listed the five indexed locales while none of them listed `mt` back, the whole group
would be invalid.

Resulting behaviour:

| Page | canonical | hreflang |
|------|-----------|----------|
| Public, indexed locale (`/de/cv`) | self | 5 locales + `x-default` |
| Public, non-indexed locale (`/mt/cv`) | self | none |
| Auth / admin (`/fr/login`) | none | none |

`x-default` points at the site root `/`, which `LocaleResolver` already resolves from the
`Accept-Language` header — exactly `x-default` semantics: what to serve when no declared locale
fits the visitor.

## Where the code lives

| Path | Role |
|------|------|
| `app/Services/Seo/LocalizedUrlService.php` | Builds canonical + alternates. No Request dependency, unit-tested. |
| `app/Providers/AppServiceProvider.php` | Binds the service, and a `layouts.app` view composer feeds `$seoCanonical` / `$seoAlternates`. Glue only. |
| `resources/views/layouts/app.blade.php` | Renders the tags. No logic. |
| `config/i18n.php` | `indexable_locales` |
| `config/seo.php` | `public_routes` |

The view composer means no controller wires anything: a new public page gets its tags by being
added to `config/seo.php`, nothing else.

Route parameters are preserved when swapping locales — `blog.show` carries a `{slug}`, and
alternates must keep it. Only the `lang` parameter is substituted, overriding the value set by
`URL::defaults()` in `app/Http/Middleware/SetLocale.php`.

## Tests

| Suite | File |
|-------|------|
| Unit | `tests/Unit/Services/Seo/LocalizedUrlServiceTest.php` |
| Feature | `tests/Feature/Seo/HreflangTest.php` |

The Feature suite asserts on rendered HTML, including the **absence** of tags on auth pages —
that assertion is the regression guard for the allowlist rule above. Do not weaken it.
