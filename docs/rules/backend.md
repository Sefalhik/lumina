---
paths:
  - "app/**"
  - "routes/**"
  - "bootstrap/**"
  - "config/**"
  - "database/**"
---

# Rules — backend

## Where logic lives

- Put no business logic in a controller, an Eloquent model or a Vue component. It lives in
  `app/Services/`, framework-agnostic and unit-testable without HTTP. A controller validates, calls a
  service, returns a response.
- Logic may stay with its single caller for a while, as a known debt item — say so where it lives.
- Give every new service, model, controller or rule its row in
  [Key directories](../architecture.md#key-directories), in the ticket that creates it.
- Keep no per-request state in a static property or a singleton: FrankenPHP's worker mode keeps the
  application booted between requests.

## Routes

- Put every public and admin route under `Route::prefix('{lang}')`. A controller never takes a
  `string $lang` argument, and URLs are built without an explicit `lang` —
  [why it is safe](../architecture.md#route-parameters-and-the-lang-prefix).
- Admin routes pass `auth → role:admin → two_factor_verified`, in that order —
  [Authentication](../authentication.md).
- Add every new public route to `config/seo.php` → `public_routes`, an allowlist. Indexing is decided
  by `SITE_INDEXABLE`, never by `APP_ENV` — [SEO](../seo-conventions.md).
- Reference JSON endpoints by name, through Ziggy's `route()`, never by a hard-coded URL.

## Code

- Type every parameter and return value. PHPStan runs at level 8 on `app/`; deprecated code is an
  error; never `@phpstan-ignore` without a written justification.
- Call `env()` in configuration files only: once `config:cache` has run, it returns `null` anywhere
  else.
- Run no migration without Laurent's explicit go.

## Logs

- Static messages only — no interpolation. Context in a structured array, with the keys `service`,
  `method` and `step`.
- Pass an exception whole: `['exception' => $e]`.
- Never log personal data, a password, a token, or a full request or response body.

References: [architecture](../architecture.md), [logging](../logging-conventions.md),
[authentication](../authentication.md), [SEO](../seo-conventions.md).
