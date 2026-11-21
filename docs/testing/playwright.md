# Testing conventions — Playwright — sessions, helpers and patterns

Part of [Testing conventions](README.md).

## Playwright — authentication
Admin tests reuse a session created once — **never** call the auth helper in `beforeEach` (causes race conditions under `fullyParallel: true`).

```js
import { ADMIN_SESSION } from './helpers/auth.js';

test.use({ adminSession: ADMIN_SESSION }); // never storageState: see below
```

The sessions are created once by `tests/e2e/auth.setup.js` via `GET /e2e/admin-auth` (helper route in `routes/e2e.php`, loaded in `local` and `testing` only), **on every slot's server**: a session exists only in the database that created it. They land in `tests/e2e/.auth/slot-<n>/<group>.json`, gitignored.

**A spec never sets `storageState` itself.** `test.use({ storageState })` does not feed the slot-aware fixture of `helpers/test.js`, it *replaces* it — and sends the test to a session that exists in no slot's database. The first version of LUMN-111 did exactly that: every admin spec landed on the login page, while a diagnostic that opened the same files directly found all sixteen sessions valid. The Vitest guard refuses `storageState` in a spec.

The e2e routes call `session()->save()` explicitly after setting session data. It was required under `SESSION_DRIVER=redis`, which writes in `StartSession::terminate()` — after the response is sent — so the browser could follow the redirect before the session existed. The `database` driver adopted on 2026-09-14 writes during the request, so the call is now belt and braces; it is kept because an explicit save before a redirect is never wrong and it keeps the helper independent of the driver in use.

Form-submission tests must run in serial mode to prevent session flash interference between tests of the same file:
```js
test.describe.configure({ mode: 'serial' });
```

## Playwright — one admin session per spec file
`auth.setup.js` creates **one isolated server-side session per spec file that loads admin pages**, per slot, each from its own browser context (`tests/e2e/helpers/auth.js`):

| Session group | Constant | Used by |
|---------------|----------|---------|
| `admin` | `ADMIN_SESSION` | `homepage.spec.js` (its admin describe) |
| `admin-editor` | `ADMIN_EDITOR_SESSION` | `admin/skills-editor.spec.js` |
| `admin-identity` | `ADMIN_IDENTITY_SESSION` | `site-identity.spec.js` |
| `admin-a11y` | `ADMIN_A11Y_SESSION` | `accessibility.spec.js` |
| `admin-experiences` | `ADMIN_EXPERIENCES_SESSION` | `admin/experiences.spec.js` |
| `admin-csp` | `ADMIN_CSP_SESSION` | `content-security-policy.spec.js` |
| `admin-snow` | `ADMIN_SNOW_SESSION` | `dead-channel.spec.js` (its admin describe) |
| `admin-motion` | `ADMIN_MOTION_SESSION` | `motion.spec.js` (its admin describe) |

**Why one per file**: two browser contexts created from the same session file send the same session cookie → the same server-side session store. A flash message set by one spec's submission is consumed by the next page load in *any* spec sharing that session, regardless of browser-context isolation. Per-slot databases do not remove this: two spec files of the same slot still run one after another on one database, and a session left with a pending flash is inherited.

**The trap**: this is not limited to specs that submit forms. **Any page load consumes pending flashes**, so a read-only spec steals them too — that is why the axe-core scans need their own session, and why adding three scans on the shared session was enough to break `homepage-form.spec.js`.

**When adding a spec that loads admin pages**, give it its own group: add a constant in `helpers/auth.js` and to `ADMIN_SESSIONS`, then `test.use({ adminSession })` it. Reusing an existing one produces failures in a *different* spec, which is a miserable thing to debug.

## Playwright — e2e helper routes (`routes/e2e.php`)
Loaded in `local` and `testing` **only** — an allowlist in `bootstrap/app.php`.

`GET /e2e/admin-auth` creates an admin, sets the 2FA session flag and logs the caller in: no
password, no TOTP. It is a backdoor, and it must exist nowhere anyone else can reach.

Until 2026-09-14 the guard was the denylist `! app()->environment('production')`, which published
that route on **every environment name nobody had anticipated** — starting with the preprod site,
which is on the public internet. Same reasoning as `config/seo.php` → `public_routes`: a forgotten
denylist entry exposes something, a forgotten allowlist entry hides something.

Consequence for `.env` on any internet-facing host, preprod included: **`APP_ENV=production`**.
Naming an environment after its role rather than its exposure is how the backdoor gets published.

| Route | Purpose |
|-------|---------|
| `GET /e2e/admin-auth` | Creates `e2e-admin@test.local`, assigns admin role, logs in, sets 2FA session flag |
| `GET /e2e/homepage-content` | Returns current FR homepage content as JSON (for test snapshot) |
| `POST /e2e/homepage-content` | Restores FR homepage content from JSON body (for test teardown) |
| `GET /e2e/site-identity` | Returns the current site identity as JSON (for test snapshot) |
| `POST /e2e/site-identity` | Restores the site identity from JSON body (for test teardown) |
| `GET /e2e/environment` | The server's environment, how many entries Telescope has recorded in its database — the effect, not the setting — and whether it serves the Vite dev server. Read by `tested-build.spec.js` (LUMN-106) |

**The two `POST` routes are exempt from CSRF**, route by route (`withoutMiddleware(PreventRequestForgery::class)`). Playwright's `request` fixture holds no CSRF token: until 2026-11-12 both answered **419**, and every restore the suite ran was refused — silently, since no spec looked at the response. PHPUnit could not have caught it: Laravel skips CSRF verification under unit tests. **Every write to an `/e2e/` route is asserted** — `expect((await request.post(…)).ok()).toBe(true)` — and the Vitest guard counts them.

**Specs that mutate site-wide data must restore it after *every* test, not just at the end.** The identity row feeds the footer of every page, so leaving it modified corrupts the next spec file of the same slot that renders a public page.

Note: `two_factor_confirmed_at` is not in `User::$fillable` — assign it directly on the model instance to bypass the guard.

## Playwright — misc patterns
- `fill()` respects HTML `maxlength` — use `locator.evaluate((el) => { el.value = '...' })` to bypass it in tests that check server-side max-length validation
- `context.addInitScript()` applies to all pages created after the call; `page.addInitScript()` applies only to that page

## Playwright — boot overlay
Most E2E tests need to skip the boot sequence overlay. Use `page.addInitScript()` **before** `page.goto()`:
```js
await page.addInitScript(() => sessionStorage.setItem('boot_sequence_played', '1'));
await page.goto('/fr/');
```
The `BootSequence` component checks this key at `onMounted` and skips rendering when set.

When asserting on `[role="option"]`, always scope to the target listbox to avoid matching options from other components on the page:
```js
page.getByRole('listbox', { name: 'Changer de langue' }).getByRole('option')
```
