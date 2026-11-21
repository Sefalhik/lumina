# Testing conventions — PHPUnit and Vitest

Part of [Testing conventions](README.md).

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
- `scripts/claude-rules.js` is **not in scope**: 92% of lines, and the seven it leaves are its entry
  block, tested as a process on a git tree — exit code and output — where coverage cannot follow
  (LUMN-150). Its mutation passes are in the pull request.

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
caught by static analysis instead — see [Deprecated code is an error](../quality/audit-and-analysis.md#deprecated-code-is-an-error).

## PHP — a test that checks nothing fails the suite

PHPUnit calls a test that performs no assertion *risky*: it prints a line about it and exits 0.
Delete the one line of a test that checks something, and the test stays green. The mechanical pass
of LUMN-149 met three such lines in a single file — each deletion survived, reported as risky and
noticed by nothing.

`phpunit.xml` sets `failOnRisky="true"`. `Tests\Feature\Testing\RiskyTestsFailTest` runs a nested
PHPUnit, under this same configuration, on a test that checks nothing, and fails the day the setting
is removed. A twin that checks something has to pass, so the failure cannot come from a run broken
for another reason.

A test whose only purpose is to run without throwing says so: `$this->expectNotToPerformAssertions()`,
or `$this->addToAssertionCount(1)` after the call, as `DeprecationsAreErrorsTest` does.

**Vitest, the same** (LUMN-55): `vitest.config.js` sets `expect: { requireAssertions: true }`, and a
test without an `expect` fails with "expected any number of assertion, but got none". Its witness,
`scripts/__tests__/require-assertions.test.js`, runs a nested Vitest under this configuration on an
empty test and its twin. The 3 412 tests of the suite already all asserted something when it was
switched on; the mechanical pass of a new test is what showed the gap.

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

## Faking a deployed site — `Tests\Concerns\FakesDeployedSite`

The smoke tests (LUMN-49) run against a healthy deployed site faked at the HTTP layer, shared by
`SmokeTestServiceTest` and `DeploySmokeTest`. Each test breaks one route and nothing else, and
`Http::preventStrayRequests()` makes any request the fake does not know throw: a probe hitting an
unexpected URL fails loudly, and no test reaches the network. Details in
[Smoke tests](../smoke-tests/probes.md#where-the-code-lives).

## Shared test helpers — `tests/Concerns/`

Three traits written for LUMN-110, each because the same code had been copied into several tests:

| Trait | What it gives | Why it exists |
|---|---|---|
| `UsesTemporaryDirectory` | `temporaryDirectory()`: a directory of the test's own, removed with everything in it — symbolic links included — by a PHPUnit `#[After]` hook | The recursive removal was copied into four test classes. A hook rather than a Laravel callback, so a bare PHPUnit test case can use it too (`FrontControllerTest`, `ReleaseInServiceTest`) |
| `FakesTranslationApi` | `fakeTranslations()`: the Anthropic API answering every request with `<LOCALE> <value>`, a locale able to fail on demand; `sentKeysByLocale()` to assert what was asked | Lets a test say *which* keys were sent, for *which* locale — the question the translation commands are about |
| `AssertsLogs` | `spyOnLogs()`, then `assertLogged($level, $message, $context)`: the static message, the three mandatory keys of [Logging conventions](../logging-conventions.md), and the values the test names | The same `withArgs()` closure was being written test by test |

A process killed outright — a fatal error under a mutation pass — skips every hook: its temporary
directory stays in `/tmp`, named `lumina_test_…`. Removing them by hand after a pass is safe.

## Detections the tests apply — `tests/Support/`

A class the tests use rather than a trait they mix in. `Documentation\DocumentationChecks` holds the
detections that keep the documentation true — the index and its READMEs, the size of a document, the
files, links and anchors it names (LUMN-150). Each is a pure function, proven on fixed input by
`tests/Unit/Documentation/DocumentationChecksTest.php`, then applied to the repository by
`tests/Feature/Documentation/DocumentationIndexTest.php`. Run on the repository alone, a detection
never took its failing branch: one mechanical pass deleted the line recording a problem 35 times
without a test noticing. `tests/Support/Smoke/` holds the probes the smoke tests' own tests use.
