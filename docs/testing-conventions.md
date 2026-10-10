# Testing conventions

## The doctrine

How tests are written here, before any mechanism. Everything after this section is mechanisms and
traps; this is what they serve.

### Every level, and each thing at its lowest

What can be tested at a lower level is tested there. The higher levels do not repeat it: they prove
what only they can.

| Level | What it proves | What it leaves to the level below |
|---|---|---|
| Unit — PHPUnit `tests/Unit`, Vitest | **the logic**: a service, a rule, a pure function, in every case it can meet | — |
| Feature — HTTP requests, Artisan commands | **the assembly**: that the rule is really wired, the middleware really fires. One case per rule wired is the proof it is called | every edge case of that rule, again |
| Playwright | **what the user sees**: the rendering and the assembly of components, at the end of the chain | the data — it has been proven by then |

"Rendering" does not mean "superficial". The data is tested in full, at the level where that is
cheapest, and that is what lets the browser suite stay fast and stable.

The corollary is architectural, and already a rule:
[no logic in a controller, a model or a Vue component](../CLAUDE.md#service-layer--hard-rule).
Logic buried there can only be reached from a higher level, where a test costs more and protects
less.

### Past the happy path

A feature is tested where it works, where it refuses, and where nobody expected it to be:

- the accepted case and the **refused** one — access control on writing as much as on reading;
- the **error** it handles, and the **exception nobody planned**;
- the **boundary values**;
- a **third party that does not answer**, or answers something else;
- **improbable input**. Nobody knows what a direct request, a file or another program's output will
  contain: what the code cannot read must fail, not pass for empty.

**One test per acceptance criterion is the floor, not the measure.** Every criterion has a test —
LUMN-10 shipped with one implemented and untested, and the configuration it relied on could have
been removed without a test noticing. But criteria describe what was asked for, and most of what
breaks was not asked for.

**Write the cases from the specification, not from the code.** A test derived from the
implementation agrees with it, omissions included: a rule with three conditions of which two were
coded gets perfect tests of the two. Start from what the code must *refuse*.

### A test is trusted once it has bitten

A green test proves nothing until it has been seen red for the right reason. **Break the code the
test claims to cover, one line at a time, and check that this test fails.** Never call something
covered without having done it.

LUMN-73 is the measured case. Its tests, written one per acceptance criterion, covered 87% of the
script's lines; 11 of 29 single-line mutations left them all green — the script exiting 0 while
printing a failure among them. Rewritten from what the script must refuse, then mutated again:
78 mutations, none survived. Three defects of the script itself surfaced on the way, none of which
a passing test would have found.

Two rules of practice:

- **Restore from a copy, never with `git checkout --`.** On a file that carries uncommitted work,
  the checkout erases it silently.
- **A fix ships with the test that fails without it.** Checked the same way: undo the fix, the test
  must turn red. Otherwise another guard is doing the refusing, and the test passes with or without
  the fix.

### The mechanical pass

Mutations chosen by hand are chosen by whoever wrote the tests, and share their blind spots.
`scripts/mutate-lines.js` chooses nothing: it deletes each line of a file in turn, reruns a command,
and names the deletions that command did not notice.

```bash
node scripts/mutate-lines.js scripts/update-frankenphp.sh -- \
    npx vitest run scripts/__tests__/update-frankenphp.test.js --bail=1
```

It exits 0 when every deletion is noticed, 1 when at least one is not, and 2 when the pass could
not run. It works on anything read line by line — a shell script, a workflow, JavaScript, PHP —
and leaves blank lines and comments alone.

**The measured case is the day it was written, 2026-10-10.** `update-frankenphp.sh` had just been
declared tested: 97 mutations chosen by hand, none surviving. The mechanical pass then deleted each
of its 193 lines, and 36 deletions went unnoticed. Most were lines of display. Four were not:

- the `exit` after "already up to date". The test meant to cover it used a release that could not
  have been installed anyway, "to show neither matters" — so the script went on, held the release
  back, and the test stayed green;
- a binary that prints no version, which no test covered — and whose error message turned out to
  be **unreachable**: the pipeline that reads the version failed first, `pipefail` ended the script
  on that line, and it exited 1 without a word;
- the macOS and unsupported-platform branches;
- `set -euo pipefail` itself.

Four rules come with the tool:

- **Both passes, never one.** The mechanical pass is a floor. It finds the line nobody tests, never
  the rule that was coded wrong: a `<` where a `<=` was meant is not a deleted line. Mutations
  written from what the code must refuse remain the method.
- **Every survivor gets a verdict, written down.** A line to test; a line to delete, because it is
  a guard that cannot fire; or a line whose absence changes nothing — a `;;` before `esac`, a blank
  line of output. "Eight are left" is not a result. "Eight are left, and here is why each" is.
- **A command that already fails is refused.** Every deletion would then look noticed, and the pass
  would report a perfect score from a suite that cannot pass at all.
- **The file is put back, whatever happens** — a command that blows up, `Ctrl-C` — and a copy named
  `<file>.before-mutation` sits beside it meanwhile. If the process is killed outright, that copy
  is the original: the tool refuses to start again until it has been dealt with.

### What 100% does not see

The bar for logic is 100%. The threshold enforced today is lower — see
[Coverage thresholds](#coverage-thresholds) — and raising it is LUMN-57. Code is never excluded from
coverage to pass a threshold, and **a guard that cannot fire is deleted, not excluded**: it claims
to check something that is already guaranteed.

A covered line is one that ran, not one that is tested. Line coverage is blind to:

- **several rules on one line.** A validation array is covered by any request and exercised by
  none: one case per rule;
- **a fake that looks like coverage.** A test that reaches an endpoint through a faked response
  never reached the endpoint;
- **the seam between two files** — a file name, a cache key, a path written on both sides. Each
  side is at 100% and nothing checks they agree. A seam gets a test of its own;
- **a tolerance that stops halfway.** What one layer accepts must hold all the way down. Assert the
  effect the accepted value produces, not the status code.

### Never

- **Weaken, skip or comment out a test to get a green run.** A test that passes without checking
  what it names is worse than no test. A red test is a regression or a test to change for a stated
  reason — never one to silence.
- **Ship a feature in one pull request and its tests in another.**
- **Depend on a floating reference**: the order of execution, the state another test left, or a
  date computed from now when that date decides the result. Using the clock is fine; an outcome
  that changes with the day it runs is not. Anchor on absolute dates.
- **Let a test reach the network.** Every HTTP call is faked explicitly, and
  `Http::preventStrayRequests()` makes a forgotten one throw.

## PHP — database
- Test database: **PostgreSQL**, dedicated `cardascia_it_test` database — **never** the `cardascia_it` schema
- Connection overrides (host, port, database name) are in `phpunit.xml` — credentials come from `.env` and are never committed
- Never use SQLite for tests — FK constraints and type behaviour diverge from PostgreSQL

## PHP — coverage scope (`phpunit.xml` + `scripts/check-coverage.php`)
`composer test:coverage` runs **both Unit and Feature suites** (`--testsuite=Unit,Feature`).
The following are excluded from the `<source>` block:
- `app/Http/Controllers/` — thin orchestrators, tested via Feature (HTTP) tests
- `app/Providers/` — bootstrapping code
- `app/Models/` — tested via Feature tests against real DB

`app/Console/Commands/` is **included** — Artisan commands have Feature tests that contribute to coverage. Never exclude code from coverage to make a threshold pass.

## JS — coverage scope (`vitest.config.js`)
Two things are in scope for unit coverage: `resources/js/utils/**/*.js`, and one tooling script.
- `app.js` is an entry point (Vue island mounting), not unit-testable
- Vue components are covered by Playwright E2E tests and `@vue/test-utils` component tests
- `scripts/audit-advisories.js` is in scope **and held to 100%** of statements, branches, functions
  and lines, by a threshold of its own: it is a watch, and a line of it nobody exercises is a way
  for it to go quiet. Its last five lines — the block that runs only when the file is started as a
  program — are excluded with a `v8 ignore`, because coverage is collected in the test process and
  cannot see a child process. They are not untested: the suite starts the real script against
  stand-ins for `npm` and `composer` and asserts its exit code.
- `scripts/e2e-preflight.js` is **not measured yet**: 88% of lines and 70% of branches when measured
  by hand on 2026-10-07. Its own entry block and `preflight()` have no test.

**A coverage figure says a line ran, not that a test would notice it changing.** The tests of
`audit-advisories.js` were first written one per acceptance criterion: 87% of lines, and 11 of 29
single-line mutations of the script left every test green — among them the script exiting 0 while
printing a failure. Rewritten from the cases the script must refuse, then checked the same way:
78 mutations, none survives. One of them had found a guard that could never fire; it was deleted,
not excluded.

## Coverage thresholds
Both suites enforce **80% line coverage minimum** — commits are blocked by the pre-commit hook if the threshold is not met.

## PHP — a deprecation fails the suite

A deprecation is the only notice a package gives before removing something. Until LUMN-71 this
suite printed *0 deprecations* and saw almost none: **Laravel's error handler drops every
deprecation while the application runs in tests** (`HandleExceptions::shouldIgnoreDeprecationErrors()`),
so PHPUnit only ever heard about the ones raised by tests that never boot Laravel — and stayed
green on those too. Three probes out of four went through unseen.

Two settings close it, one for each kind of test:

| Where | Setting | What it catches |
|---|---|---|
| `Tests\TestCase::setUp()` | `$this->withoutDeprecationHandling()` | a deprecation raised while Laravel runs — in the test, in application code, during a request. It becomes an `ErrorException` |
| `phpunit.xml` | `failOnDeprecation`, `failOnPhpunitDeprecation` | a deprecation raised by a test that extends PHPUnit's own `TestCase` |

`Tests\Feature\Testing\DeprecationsAreErrorsTest` fails the day the first one is removed — which
nothing else would notice, since a suite that stops seeing deprecations stays green.

**The way out.** A deprecation raised by a package, which this code cannot fix, would fail every
test that reaches it. Call `$this->withDeprecationHandling()` in that test, with the reason in a
comment — never in `Tests\TestCase`, which would switch the whole suite back to silence.

Runtime deprecations are half of it: code merely annotated `@deprecated` raises nothing, and is
caught by static analysis instead — see [Deprecated code is an error](quality-tooling.md#deprecated-code-is-an-error).

## Boot-time decisions — `Tests\Concerns\RebootsInEnvironment`

`bootstrap/app.php` decides at boot which routes exist and which providers are registered. Those
decisions cannot be asserted by changing config: the app has already booted. The trait re-requires
`bootstrap/app.php` under another `APP_ENV` via `refreshApplication()`, writing the value to `$_ENV`,
`$_SERVER` **and** `putenv()` — Laravel's Env repository reads all three, and `phpunit.xml` populates
the first two.

It **fails loudly if the reboot stops taking effect**. Without that check, every test using it would
keep observing the `testing` environment and report green — the defect being asserted against would
be invisible, which is worse than having no test.

Used by `tests/Feature/Deployment/`, which covers the five guards that only matter off a developer's
machine: the e2e route allowlist, Telescope's conditional registration, `trustProxies`,
`public/.htaccess`, and driver parity across environments.

**Two traps recorded there, both found while writing those tests:**

- **`$this->get('/path')` builds its URL from `APP_URL`, which is `https://`.** Two `trustProxies`
  assertions passed with the middleware deleted entirely, because the request was already secure.
  Any test about scheme, host or proxy headers must address an explicit `http://` root — and carry a
  negative control, which is what caught it.
- **"Absent in production" is not a test of an allowlist.** A denylist of `production` passes it.
  The assertion that distinguishes them is *absent in an environment the codebase has never heard
  of* — `preprod`, `staging`, `review-app-42`.

## Pre-commit hook
Runs automatically on `git commit`:
1. `lint-staged` — format checks
2. `npm run test:unit:coverage` — JS unit tests + coverage threshold
3. `composer test:coverage` — PHP unit + feature tests + coverage threshold

## Playwright — dedicated E2E database
E2E tests run against a **dedicated PostgreSQL database** (`cardascia_it_e2e`) and a **dedicated server** (`php artisan serve --port=8001`) — never the dev database or dev server.

Prerequisite (one-time):
```bash
createdb -h 127.0.0.1 -p 5433 -U <user> cardascia_it_e2e
```

The Playwright `webServer` starts the server automatically with env overrides from `tests/e2e/helpers/e2e-env.js`:
- `DB_DATABASE=cardascia_it_e2e`
- `APP_URL=http://localhost:8001`
- `SESSION_DOMAIN=localhost`
- `SESSION_SECURE_COOKIE=false` (HTTP — not HTTPS like the dev server)

`globalSetup` (`tests/e2e/global-setup.js`) runs `migrate:fresh --force` before each test run — every run starts from a clean schema — then seeds `E2eSiteIdentitySeeder`.

**Why that seeder matters**: with an empty `site_identities` table the footer renders no links, so the axe-core scan never sees them and their accessibility goes unverified while the suite stays green. Seeding a fictional identity makes the existing scan cover them on all three themes. Any future feature whose markup only appears when data exists needs the same treatment.

`HomepageContentSeeder` runs next, for exactly that reason — and the rule had already been broken once before it did. With an empty `homepage_contents` table the homepage falls back to a one-sentence placeholder: the About section never renders, and the skills grid shows its fallback branch. axe-core scanned three themes without ever seeing what the CMS produces. Seeding it surfaced **373 real contrast violations** in `SkillsEditor`'s line-number gutter, plus an E2E test that only passed because the editor was empty.

The **real** seeder is used rather than an E2E twin: since LUMN-29 it reads a versioned, offline data file, so the browser suite exercises the content production will actually ship.

## Playwright — authentication
Admin tests use `storageState` to authenticate once and reuse the session — **never** call the auth helper in `beforeEach` (causes race conditions under `fullyParallel: true`).

```js
test.use({ storageState: ADMIN_AUTH_FILE }); // tests/e2e/.auth/admin.json — gitignored
```

The session is created once by `tests/e2e/auth.setup.js` via `GET /e2e/admin-auth` (helper route in `routes/e2e.php`, loaded in `local` and `testing` only).

The e2e routes call `session()->save()` explicitly after setting session data. It was required under `SESSION_DRIVER=redis`, which writes in `StartSession::terminate()` — after the response is sent — so the browser could follow the redirect before the session existed. The `database` driver adopted on 2026-09-14 writes during the request, so the call is now belt and braces; it is kept because an explicit save before a redirect is never wrong and it keeps the helper independent of the driver in use.

Form-submission tests must run in serial mode to prevent session flash interference between parallel workers:
```js
test.describe.configure({ mode: 'serial' });
```

## Playwright — one admin session per spec file
`auth.setup.js` creates **one isolated server-side session per spec file that loads admin pages**, each from its own browser context (`tests/e2e/helpers/auth.js`):

| Auth file | Constant | Used by |
|-----------|----------|---------|
| `.auth/admin.json` | `ADMIN_AUTH_FILE` | `homepage.spec.js` (its admin describe) |
| `.auth/admin-editor.json` | `ADMIN_EDITOR_AUTH_FILE` | `admin/skills-editor.spec.js` |
| `.auth/admin-identity.json` | `ADMIN_IDENTITY_AUTH_FILE` | `site-identity.spec.js` |
| `.auth/admin-a11y.json` | `ADMIN_A11Y_AUTH_FILE` | `accessibility.spec.js` |
| `.auth/admin-experiences.json` | `ADMIN_EXPERIENCES_AUTH_FILE` | `admin/experiences.spec.js` |

**Why one per file**: `fullyParallel: true` runs spec files concurrently. Two browser contexts created from the same `storageState` send the same `laravel_session` cookie → the same server-side session store. A flash message set by one spec's submission is consumed by the next page load in *any* spec sharing that session, regardless of browser-context isolation.

**The trap**: this is not limited to specs that submit forms. **Any page load consumes pending flashes**, so a read-only spec steals them too — that is why the axe-core scans need their own session, and why adding three scans on the shared session was enough to break `homepage-form.spec.js`.

**When adding a spec that loads admin pages**, give it its own auth file: add a constant in `helpers/auth.js`, add it to `SESSIONS` in `auth.setup.js`, and `test.use()` it. Reusing an existing one produces failures in a *different* spec, which is a miserable thing to debug.

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

**Specs that mutate site-wide data must restore it after *every* test, not just at the end.** The identity row feeds the footer of every page, so leaving it modified corrupts any spec that renders a public page. `site-identity.spec.js` keeps the admin form and the footer in **one serial file** for the same reason — split across two files, Playwright runs them in parallel and they read each other's half-written state.

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

## Faking a deployed site — `Tests\Concerns\FakesDeployedSite`

The smoke tests (LUMN-49) run against a healthy deployed site faked at the HTTP layer, shared by
`SmokeTestServiceTest` and `DeploySmokeTest`. Each test breaks one route and nothing else, and
`Http::preventStrayRequests()` makes any request the fake does not know throw: a probe hitting an
unexpected URL fails loudly, and no test reaches the network. Details in
[Smoke tests](smoke-tests.md#where-the-code-lives).
