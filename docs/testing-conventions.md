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

**On the end-to-end infrastructure, targeted mutations first.** A line of a Playwright fixture,
setup or configuration is only noticed by a browser run: about ten seconds a deletion on a subset of
specs, and a subset cannot see what the specs outside it would — remove the seeder only the CV's
accessibility scan needs, and a subset that never opens the CV stays green. The practice, adopted on
LUMN-111 (2026-11-12):

- the **mechanical pass on the fixtures every test goes through** (`tests/e2e/helpers/test.js`,
  `auth.setup.js`): cheap enough, and that is where LUMN-111's first defect lived — 12 lines out of
  12 noticed, once the fixture was fixed;
- **targeted mutations, chosen from what the file must refuse**, for the rest — the setup, the
  configuration, the restore routes — each run on a subset that can actually see it;
- **every survivor of a subset gets a verdict naming the spec that covers it**, rather than a full
  run per survivor. A survivor no spec covers is a test to write, like anywhere else.

The doctrine does not change: both passes, and every survivor judged. What changes is where the
cheaper one is spent.

**Never run a mutation of code that destroys data.** A mutation removes a line, and on a file that
drops, migrates or deletes, the line removed may be the one that chose the target. On 2026-11-12
the mechanical pass ran `tests/e2e/global-setup.js` for real, through Playwright: deleting
`env: { ...process.env, ...env }` let `php artisan migrate:fresh --force` read `.env`, and the
**developer's database was wiped** — while the suite stayed green, on the template the previous run
had left. No backup existed.

- Before any pass, list what the file **executes**: a database, the file system, the network, a
  remote server. If any of it reaches outside the test sandbox, the file is mutated **only under a
  replaced executor** — `vi.mock('child_process')` for `global-setup.js`, in
  `scripts/__tests__/e2e-global-setup.test.js`, which is the only place that file may be mutated.
- The destructive code itself carries an allowlist of what it may touch, checked on every call
  (`artisanEnv()` in `global-setup.js`): a guard a single deleted line cannot route around silently.
- In doubt, ask before running.

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

## Playwright — one environment per worker
Every Playwright worker talks to **a server and a database of its own** (LUMN-111) — never the dev
database or dev server, and never another worker's.

| Slot | Server | Database |
|------|--------|----------|
| 0 | `http://localhost:8001` | `cardascia_it_e2e_0` |
| 1 | `http://localhost:8002` | `cardascia_it_e2e_1` |
| … | one per worker | one per worker |

`tests/e2e/helpers/workers.js` is the single source of that mapping and of the number of workers
(`E2E_WORKERS`). `playwright.config.js` sets `workers` to it and starts one `php artisan serve` per
slot; `scripts/__tests__/e2e-workers.test.js` fails when the two drift apart.

**Why.** Until 2026-11-12 every worker shared one server and one database. Two spec files writing
the same row corrupted each other whenever their timing overlapped: the homepage form and the skills
editor did, two runs in four, the day a few tests were added to one of them. The suite had already
grown three workarounds for that same sharing — public homepage tests reading German because the
admin writes French, the identity specs forced into one serial file, a comment admitting that
"serialising is necessary and not sufficient". Ordering the two culprits one after the other would
have been a fourth. Isolation removes the class of bug, not the instance.

Rejected: one server, with a request header choosing the database. It puts in the application a
mechanism by which a request picks its database — one more switch to keep out of production, next
to the `/e2e/admin-auth` backdoor. The isolation chosen is configuration only.

### How many workers — measured
`E2E_WORKERS` is **4**, the same locally and in CI. Measured on 2026-11-12 on a 16-core developer
machine, three full runs per value, 146 tests each time:

| Workers | Runs | Mean | Failures |
|---------|------|------|----------|
| 2 | 78 s · 78 s · 80 s | 78.7 s | none |
| **4** | 71 s · 70 s · 69 s | **70.0 s** | none |
| 6 | 81 s · 75 s · 74 s | 76.7 s | none |
| 8 | 83 s · 85 s · 85 s | 84.3 s | none |

Beyond 4 the suite gets **slower**: every slot adds work that does not run in parallel — one more
database copy, six more admin sessions — and more PHP servers compete for the machine.

`auth.setup.js` then prepared the slots in parallel instead of one after the other (the sessions of
one slot stay in series: its server answers one request at a time). Same machine, 4 workers, three
runs: 62.8 s · 63.9 s · 63.3 s, **63.3 s** on average, the 24 sessions taking 5.6 s. The shared
single database of before ran in about 58 s with 8 workers, and failed at random: the 5 seconds are
the price of isolation. A GitHub runner has 4 cores, so CI gets one
server per core. Re-measure before changing the number, and change it in `helpers/workers.js` only.

### How a test reaches its slot
**Every spec imports `test` and `expect` from `tests/e2e/helpers/test.js`**, never from
`@playwright/test`. That `test` sets, from `testInfo.parallelIndex`:

- `baseURL` — the slot's server, so `page.goto('/fr/')` and the `request` fixture reach it;
- `storageState` — from the `adminSession` option, see [one admin session per spec file](#playwright--one-admin-session-per-spec-file).

`parallelIndex`, not `workerIndex`: the first is the worker's slot, stable from 0 to workers − 1;
the second grows every time a worker restarts after a failure. A spec that names a port
(`localhost:8001`) or imports `@playwright/test` directly escapes its slot: the Vitest guard above
fails on both (LUMN-109 turns the import rule into an ESLint rule).

### The databases
Prerequisite (one-time): the template database, and a role allowed to create databases.
```bash
createdb -h 127.0.0.1 -p 5433 -U <user> cardascia_it_e2e
```

`globalSetup` (`tests/e2e/global-setup.js`) runs `migrate:fresh --force` on the **template**,
`cardascia_it_e2e`, seeds it, then copies it into every slot's database with
`CREATE DATABASE … TEMPLATE …` — a file copy on PostgreSQL's side, much cheaper than migrating and
seeding each one. No server points at the template: PostgreSQL refuses to copy a database that has
open connections.

**Playwright starts the web servers before `globalSetup` runs** (its plugins come first —
`createGlobalSetupTasks` in Playwright's runner). That is why the servers' readiness URL is a
static file and not `/up`: `/up` reads the database, the slots' databases do not exist yet when the
servers start, and Playwright only accepts a status from 200 to 403 as "ready".

Each server gets `DB_DATABASE` and `APP_URL` of its slot, plus `SESSION_DOMAIN=localhost` and
`SESSION_SECURE_COOKIE=false` (HTTP — not HTTPS like the dev server).

### Why the template is seeded
**`E2eSiteIdentitySeeder`**: with an empty `site_identities` table the footer renders no links, so
the axe-core scan never sees them and their accessibility goes unverified while the suite stays
green. Seeding a fictional identity makes the existing scan cover them on all three themes. Any
future feature whose markup only appears when data exists needs the same treatment.

`HomepageContentSeeder` runs next, for exactly that reason — and the rule had already been broken once before it did. With an empty `homepage_contents` table the homepage falls back to a one-sentence placeholder: the About section never renders, and the skills grid shows its fallback branch. axe-core scanned three themes without ever seeing what the CMS produces. Seeding it surfaced **373 real contrast violations** in `SkillsEditor`'s line-number gutter, plus an E2E test that only passed because the editor was empty.

The **real** seeder is used rather than an E2E twin: since LUMN-29 it reads a versioned, offline data file, so the browser suite exercises the content production will actually ship.

### Isolation does not replace restoring
Within a slot, spec files run one after another, and each inherits what the previous one left. A
spec that changes site-wide data still restores it — see the e2e helper routes below. What
isolation removes is the **concurrent** collision, and with it the need for any new workaround:
do not add another spec-ordering trick, another locale chosen to dodge a writer, another file merged
"so they run in series". `tests/e2e/worker-isolation.spec.js` proves, from the outside, that a
worker's writes are invisible to every other slot.

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

## Faking a deployed site — `Tests\Concerns\FakesDeployedSite`

The smoke tests (LUMN-49) run against a healthy deployed site faked at the HTTP layer, shared by
`SmokeTestServiceTest` and `DeploySmokeTest`. Each test breaks one route and nothing else, and
`Http::preventStrayRequests()` makes any request the fake does not know throw: a probe hitting an
unexpected URL fails loudly, and no test reaches the network. Details in
[Smoke tests](smoke-tests.md#where-the-code-lives).
