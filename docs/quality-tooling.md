# Quality tooling

## Local quality audit

`npm run check:full` (or `bash scripts/check.sh`) runs all 8 checks in sequence with a colored summary:

```
  ESLint                    ✔  2s
  Stylelint                 ✔  1s
  Pint                      ✔  1s
  PHPStan (app)             ✔  6s
  PHPStan (tests)           ✔  5s
  PHPUnit                   ✔  6s
  Vitest                    ✔  3s
  Playwright E2E            ✔  21s

  All 8 checks passed  in 45s
```

Exits 0 on success, 1 on any failure (shows the relevant output for the failing step).

**Prerequisite for E2E** : the `cardascia_it_e2e` template database must exist, and the database role must be allowed to create databases — one copy per Playwright worker is made from it on every run. See [The databases](testing-conventions.md#the-databases).

### E2E preflight — the audit refuses rather than lies (LUMN-12)

The Playwright step runs `node scripts/e2e-preflight.js` first. It **detects and never repairs**:
each refusal names the exact command to run, and the suite does not start.

| Precondition | Refused when | Fix it names |
|---|---|---|
| `public/hot` exists | nothing listens at the URL it holds — a Vite that died leaves the file behind, and every page would be tested without CSS | `npm run dev`, or delete `public/hot` |
| `public/hot` absent | `public/build/manifest.json` is missing, or older than any file in `resources/`, `vite.config.js` or `package-lock.json` | `npm run build` |
| always | the browser build the installed Playwright expects is not in `~/.cache/ms-playwright` | `npx playwright install chromium` |

Why those inputs: **Blade views** change the compiled CSS, because Tailwind scans them; the
**lockfile** changes the bundle without touching a source file — which is what every dependency
update, Renovate's included, does.

Why the browser check: nothing ties Playwright's npm version to the binary on disk. A dependency
PR that moves Playwright passes CI — whose E2E job installs the browser itself — and breaks the
next local run, possibly days later.

The comparison is on modification times, so a file touched without being changed (switching
branches back and forth) makes it refuse. That errs the safe way: the cost is a 6-second build.
Measured cost when everything is current: about half a second, almost all of it
`playwright install --dry-run`.

CI is untouched: its E2E job builds and installs the browser explicitly, and does not run `check.sh`.

## Linting — ESLint

Config file: `eslint.config.js` (ESLint v9 flat config).

### Scope
`resources/js/**/*.{js,vue}` — source files and Vitest unit tests at the same level (no separate config for tests).

`scripts/**/*.js` — dev tooling, with Node globals instead of browser ones. Added by LUMN-12: until then
the tooling that decides whether the audit can be trusted was the one JS nobody linted.

### Rule sets (in order)
1. `@eslint/js` — `eslint:recommended`
2. `eslint-plugin-vue` — `flat/recommended` (Vue 3 rules)
3. `eslint-plugin-vuejs-accessibility` — `flat/recommended` (static WCAG a11y checks on Vue templates)
4. `eslint-config-prettier` — disables formatting rules that conflict with Prettier

Custom rules on top:
- `vue/component-api-style: ['error', ['script-setup']]` — enforces `<script setup>`, rejects Options API
- `no-var: error`, `prefer-const: error`
- `complexity`, `max-lines`, `max-lines-per-function` — see [Size and complexity](#size-and-complexity)

### Accessibility static analysis (`eslint-plugin-vuejs-accessibility`)
Checks Vue templates at lint time for ARIA and semantic HTML issues — complements the runtime axe-core scan in `tests/e2e/accessibility.spec.js`.

Key rules enforced by `flat/recommended`:
- `form-control-has-label` — every `<input>`, `<select>`, `<textarea>` must have an accessible label (via `<label>`, `aria-label`, or `aria-labelledby`)
- `anchor-has-content` — `<a>` tags must have text or an `aria-label`
- `interactive-supports-focus` — interactive elements must be keyboard-reachable

These checks fire at commit time (lint-staged) and in CI — a missing `aria-label` blocks the commit.

### Globals
Browser globals (`window`, `document`, `sessionStorage`, `fetch`…) are declared via the `globals` package (`globals.browser`).
They apply to `resources/js/` only; `scripts/` gets Node's (`globals.node`). Until LUMN-62 the tooling
had both: the base config of `eslint-plugin-vuejs-accessibility` sets the browser's globals on
**every** file, so `window` passed unnoticed in Node scripts. Its configs are now scoped to `.vue`
files, and `eslint-config.test.js` checks each side knows its own globals and not the other's.
`route` is declared as a custom global — it's the Ziggy helper injected by the `@routes` Blade directive.

### Pre-commit integration
`lint-staged` runs `eslint` (check only, no `--fix`) after `prettier --write` on staged `.js` and `.vue` files. The commit is blocked if ESLint reports errors.

## Formatting PHP — Pint

`laravel/pint`, Laravel's preset, no `pint.json`. It was installed and run by **nobody** until
LUMN-62: no audit, no CI step, no hook — the formatting of PHP was checked by no one, and three
files had drifted by 2026-09-20.

| Where | What it does |
|---|---|
| `npm run check:full` | `./vendor/bin/pint --test`: a file out of format fails the step |
| CI, `php` job | the same command, before PHPStan |
| `lint-staged` | `vendor/bin/pint` on the staged `.php` files: it **formats** them and the commit carries the result, as `prettier --write` does for JavaScript. A hook that refuses what it could fix only costs a second commit |

## Size and complexity

A class of 654 lines went through every check without a word, in September 2026: nothing measured
the size or the complexity of the code. LUMN-62 added the measures — and **measured before choosing
any threshold**. A threshold taken from an article turns the repository red on the first day, and
the first reaction is to switch the rule off. Each threshold below sits at, or just above, the
largest value the code had on 2026-11-14: a ratchet, like the 400-line ceiling of `CLAUDE.md`.

### PHP — cognitive complexity, inside PHPStan

`tomasvotruba/cognitive-complexity`, a PHPStan extension: no extra check in the chain, and the
same failure output. Cognitive complexity measures how hard code is to *follow* — nesting, breaks
in the flow — and ignores comments and blank lines.

| Measure, `app/` | Largest on 2026-11-14 | Threshold |
|---|---|---|
| One method | 14 (`SecurityHeaders::validated()`) | **15** |
| One class (sum of its methods) | 37 (`CmsTranslate`) | **40** |

**PHPMD was measured too, and set aside.** It counts length in lines, comments included:
`TranslationCache` came out at 385 lines, next to the real outlier, because it is documented. In a
repository that documents its code at length, a length threshold would push to delete
explanations.

**One exception, dated and named.** `I18nTranslate` measures 29 for `translatePhpFiles()` and 62
for the class — far above everything else. LUMN-110 rewrites that command next, and must remove the
two `ignoreErrors` entries of `phpstan.neon`: one of its acceptance criteria. Each entry matches
once, and only in that file: a second excess anywhere fails the analysis.

**`tests/` is not held to it yet.** Three test methods measure 17 to 21, and
`DocumentationIndexTest` 85 as a class: it carries a real Markdown link resolver. That is logic
living in a test, a debt of its own, not a threshold to set at 85.

### JavaScript — ESLint

| Rule | Scope | Largest on 2026-11-14 | Threshold |
|---|---|---|---|
| `complexity` | code **and tests** | 20 — `npmAdvisories()`, `update-frankenphp.test.js` | **20** |
| `max-lines` (no blank lines, no comments) | code | 244 — `scripts/audit-advisories.js` | **250** |
| `max-lines-per-function` (same) | code | 104 — `createDeadChannel()`, a controller written as one closure | **105** |

Not in the tests: a test file grows with the cases of what it tests, and a `describe()` is a long
function by nature. Complexity applies to them all the same — logic in a test is a smell.

`resources/js/utils/boot-origin.js` escapes `max-lines`: it is data, one verified address block per
line (LUMN-122), and its own test pins its size at 334 lines, so that it changes on purpose, with a
new RIPEstat check.

`scripts/__tests__/eslint-config.test.js` holds the whole configuration where it is decided: the
project's configuration lints code built to break each rule, or to sit exactly on each limit and
then one past it — the presets included, each through one rule it brings, the globals of each side,
and what is never linted.

## Static analysis (PHPStan)

### Dual-config setup
Package: `larastan/larastan` (replaced the abandoned `nunomaduro/larastan` in May 2026 — update `phpstan.neon` and `phpstan-tests.neon` include paths accordingly).

Two separate configs run in sequence via `composer analyse`:

| Config | File | Scope | Level |
|--------|------|-------|-------|
| App | `phpstan.neon` | `app/` (excl. `app/Providers/`) | 8 |
| Tests | `phpstan-tests.neon` | `tests/` | 5 |

Controllers and models are **included** in the app analysis (no `excludePaths` shortcut).

### Deprecated code is an error

`phpstan/phpstan-deprecation-rules` is included in both configs: calling a method, instantiating a
class or reading a constant annotated `@deprecated` fails `composer analyse`. Most packages
deprecate with that annotation alone, which raises nothing at runtime — no test can see it.

Its first run, in LUMN-71, found two calls to PHPUnit's `expectExceptionMessage()`, deprecated in
favour of `expectExceptionMessageIsOrContains()`; nothing had reported them. The runtime half is in
[PHP — a deprecation fails the suite](testing-conventions.md#php--a-deprecation-fails-the-suite).

### Stub file
`phpstan-stubs.php` overrides `artisan()` to return `PendingCommand` (not `PendingCommand|int`) — the framework annotation is misleading; the implementation always wraps in `PendingCommand`.

### Type-narrowing conventions
- `$request->user()` returns `Authenticatable|null` — always narrow with `assert($user instanceof User)` in methods guaranteed by auth middleware
- Annotate `public array $translatable` on translatable models with `/** @var list<string> */`
- Never use `@phpstan-ignore` without a written justification comment

### PHP_INI_SCAN_DIR
PHPStan's ReactPHP worker processes don't inherit the conf.d scan path from the parent process.
The `analyse` script in `composer.json` always exports `PHP_INI_SCAN_DIR=/etc/php/8.5/cli/conf.d`
so that `phar.so` (and other extensions) are loaded in child processes too.
Without this, child workers fail with `Class "Phar" not found`.

It points at a local FrankenPHP build and has no business on a server:
[Environment variables](environment-variables.md#deliberately-absent-from-preprod-and-production).

## Continuous integration (GitHub Actions)

Workflow: `.github/workflows/ci.yml` — triggered on every PR targeting `main`, on every push to
`main`, and by hand (`workflow_dispatch`).

**One run per push, not two** (LUMN-63). The workflow used to listen to `push` on every branch as
well, so a push to a branch with an open PR fired both events and ran the suite twice on the same
code. The `pull_request` run is the one that counts: it tests the branch merged with `main`, and
the ruleset's strict mode already requires the branch to be up to date. `push` is kept on `main`
because a cache written there is readable from every branch, while a cache written by a PR serves
that PR alone. A branch pushed **without** a PR therefore runs nothing — start the workflow by hand
from the Actions tab, or with `gh workflow run ci.yml --ref <branch>`.

`php`, `js` and `dependency-review` run in parallel, `e2e` runs after `php` passes:

| Job | Steps |
|-----|-------|
| `PHP — PHPStan + PHPUnit` | setup-php 8.5 (pcov) → composer install → key:generate → Pint → PHPStan app (level 8, cognitive complexity included) → PHPStan tests (level 5) → `composer test:coverage` (80% threshold enforced) |
| `JS — ESLint + Stylelint + Vitest` | Node from `.nvmrc` → npm ci → ESLint → Stylelint → Vitest |
| `E2E — Playwright` | setup-php 8.5 + Node from `.nvmrc` → composer + npm install → `npm run build` → Playwright Chromium → key:generate → `npm run test:e2e` |
| `Security — Dependency review` | pull requests only → fails when the PR introduces a package with a known vulnerability, severity high or worse |

**PostgreSQL** : each PHP-dependent job spins up its own `postgres:16` service. The PHP job uses `cardascia_it_test`; the E2E job uses `cardascia_it_e2e`. The workflow sets `DB_PORT: 5432` which overrides the phpunit.xml default (5433).

**`PHP_INI_SCAN_DIR`** : set to `/etc/php/8.5/cli/conf.d` in the PHP job env — same issue as local. GitHub Actions runners use Ubuntu with an APT PHP install (`conf.d` layout); PHPStan workers fail with `Class "Phar" not found` without this variable. Not needed in the E2E job (PHPStan doesn't run there).

**Coverage** : `coverage: pcov` in the PHP job + `composer test:coverage` enforces the 80% line coverage threshold in CI, not just locally.

**E2E drivers** : the job used to force `SESSION_DRIVER: file`, `CACHE_STORE: file`, `QUEUE_CONNECTION: sync` and `APP_MAINTENANCE_DRIVER: file`, because `.env.example` put them all on Redis and this job has no Redis server — which made CI the one place running a different session driver from development. Since 2026-09-14 all four read `database`/`database`/`sync`/`file` straight from `.env.example`, and the overrides were removed rather than updated: restating a value that already matches is how the two drift apart again. Only `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `APP_URL`, the `DB_*` block and `ANTHROPIC_API_KEY` are still overridden.

**`needs: [php]`** on the E2E job : no point running the full browser suite if the backend is already broken.

**`permissions: contents: read`** : declared at the top of the workflow. It is already the
repository default, but that default is a setting, changeable without a commit. `jira-sync.yml`
declares `permissions: {}` — it never uses the GitHub token, only its own JIRA secrets. CodeQL's
first analysis, on 2026-10-03, reported exactly this: four `actions/missing-workflow-permissions`
results, one per job, and nothing else.

### Repository settings for Actions

Four settings live in the repository rather than in a file. Three were tightened on 2026-10-10 with
LUMN-50 — the day a workflow started holding a key to a server.

| Setting | Value | Why |
|---|---|---|
| Actions allowed | GitHub's own, plus an explicit list: `shivammathur/setup-php` | a workflow cannot start running an action nobody chose. It was "all" |
| Pinning by full commit SHA | required | a tag can be moved onto other code. Every action was already pinned, by Renovate: this makes it a rule rather than a habit |
| Workflows from fork pull requests | approval for every external contributor | it was first-time contributors only |
| Default token | read-only, and cannot approve a pull request | unchanged |

```bash
gh api repos/Sefalhik/lumina/actions/permissions                               # allowed_actions, sha_pinning_required
gh api repos/Sefalhik/lumina/actions/permissions/selected-actions              # the explicit list
gh api repos/Sefalhik/lumina/actions/permissions/fork-pr-contributor-approval
gh api repos/Sefalhik/lumina/actions/permissions/workflow                      # the default token
```

**Adding an action to a workflow now takes two changes**: the workflow, and the list. An action
from another publisher than GitHub that is not on the list is refused by GitHub, whatever the
workflow says. `deploy.yml` uses no action at all — see
[The pipeline's access to the server](deployment.md#the-pipelines-access-to-the-server).

Measured the same day under these settings: `security-audit.yml` and the `ci.yml` run of `main`
both pass. CodeQL's default setup is a workflow GitHub manages, which cannot be started by hand:
it ran under these settings on pull request #57 that evening, and passed, as did the dependency
review.

## Known vulnerabilities

Five things watch for them, and **three of the five are repository settings, not code** — no test in
this project can tell that one was switched off. The commands below read their state.

| What | Where it lives | What it covers |
|---|---|---|
| Dependabot alerts | repository setting | the flaws GitHub matches against `composer.lock`, `package-lock.json` and the workflows; also the only thing that lets Renovate raise a security fix. **Not every known flaw** — see [What the alerts did not say](#what-the-alerts-did-not-say-lumn-73) |
| `Security — Known advisories` | `security-audit.yml`, daily, **not** a required check | every advisory `npm audit` and `composer audit` report on the whole tree, development and transitive dependencies included, less the ones accepted in `accepted-advisories.json` |
| `Security — Dependency review` | `ci.yml`, a [required check](git-workflow.md#required-checks) | what a pull request *introduces* |
| CodeQL, default setup | repository setting | JavaScript and the GitHub Actions workflows, on pull requests and weekly |
| Secret scanning with push protection | repository setting | a credential committed by mistake, refused at push |

```bash
gh api repos/Sefalhik/lumina/dependabot/alerts --jq 'length'             # 403 when alerts are off
gh api 'repos/Sefalhik/lumina/dependabot/alerts?state=auto_dismissed' --jq 'length'   # 0, or a preset is dismissing alerts
gh api repos/Sefalhik/lumina/code-scanning/default-setup --jq '.state'   # configured
gh api repos/Sefalhik/lumina --jq '.security_and_analysis'               # secret scanning
```

The Dependency Dashboard lists known vulnerabilities too, for direct dependencies only — a
convenience, not a watch: [Dependency updates](dependency-updates.md#installing--once).

**PHP has no security analysis.** CodeQL does not support it, and PHPStan checks types, not flaws.
Whether Psalm's taint analysis or Semgrep is worth adding is LUMN-66.

**The job log names only the first vulnerable package.** The dependency review stops printing after
the first package it finds, even though it fails on any of them: fix that one and the next appears.
Found during the negative control of LUMN-65, where a PR adding `lodash@4.17.20` and
`dompdf/dompdf:1.2.0` reported dompdf alone; with dompdf removed, the same job failed on lodash.
Both lockfiles are therefore covered, and a red job with one name in it may hide others.

### What the alerts did not say (LUMN-73)

On 2026-10-07 an `npm install` printed "12 high, 2 critical". Nothing had announced them: no mail,
no open alert, no line on the Dependency Dashboard. They were two advisories, both on development
tooling, neither exploitable here — and neither had reached anyone, for two different reasons.

| Advisory | Package | What GitHub did with it |
|---|---|---|
| `GHSA-vfj7-8cjw-p6xm`, high | `braces` 3.0.3, through `stylelint` | raised an alert on 2026-10-03 at 18:03:48 UTC and dismissed it **one second later**, on its own |
| `GHSA-pqg4-j6r4-53mv`, critical | `shell-quote` 1.9.0, through `concurrently` | **never raised an alert**, though its dependency graph lists that exact version |

**The first is a GitHub preset**, "Dismiss low impact issues for development-scoped dependencies".
It is on by default for public repositories, applies to npm only, and runs *before* notifications
are sent — so an alert it dismisses is one nobody is ever told about. It was switched off on
2026-10-07 (Settings → Advanced Security → Dependabot rules → GitHub presets) and the alert
reopened at once. Development tooling runs where the secrets are, on a developer's machine and in
CI: whether a flaw there is tolerable is a decision, and a decision carries a reason.

**The second is unexplained.** The preset cannot account for it: it would have left a dismissed
alert behind. More than a day after the advisory was published, there was still none.

Neither could have become a Renovate pull request either — see
[Installing — once](dependency-updates.md#installing--once). `braces` has no fixed version, and
`concurrently` pins `shell-quote` to an exact version, in its latest release too.

### The advisory audit

`node scripts/audit-advisories.js` runs `npm audit` and `composer audit`, reduces what they print to
the distinct advisories, and compares those with `accepted-advisories.json`. It is the one watch
that does not depend on GitHub raising an alert. Five outcomes:

| Situation | Result |
|---|---|
| an advisory that is not in the accepted list | **failure**, naming the advisory and its package |
| an acceptance whose review date has passed | **failure**, quoting the reason that was given |
| an audit that did not answer — no JSON, npm's `{"error": …}` when the registry is unreachable, a command missing or still running after two minutes | **failure**: an audit that cannot be read has not found nothing |
| an answer the script does not understand — another `auditReportVersion`, an advisory with no identifier, vulnerable packages counted and no advisory named | **failure**, for the same reason: it must not read as a clean tree |
| an acceptance no audit reports any more | a warning on the run: the entry is to be removed |

**Accepting an advisory** is adding an entry with five fields, all required — `id`, `package`,
`reason`, `acceptedOn`, `reviewBy`. A malformed list fails the audit before anything runs: an
acceptance without a reason is the silent dismissal this replaces. The identifier is written
exactly as the audit prints it — `GHSA-vfj7-8cjw-p6xm`, not a URL and not in another case: one
spelled differently would accept nothing while looking as if it did, so it is refused. Both dates
are real calendar dates, and the review cannot come before the acceptance.

**`reviewBy` is the last day the acceptance holds.** Until then the advisory is silent; from the
next day the audit fails on it every morning, until the entry is removed — the flaw is fixed — or
given a new date, which means someone looked again. It is what keeps the list from becoming the
place where things are forgotten. An entry that waits for an upstream fix gets a short date; one
with no fix in sight gets a longer one.

**It runs daily and is not a required check.** An advisory published overnight on a package already
on `main` must not block every merge; a red run is the signal. It also runs on a pull request that
touches a lockfile, the list, the script or the workflow — so a red `Security — Known advisories`
on a dependency pull request is about the tree, not necessarily about that pull request.

**Both audits read the lockfiles alone.** Nothing is installed in the job. The exit code of
`npm audit` and `composer audit` is ignored on purpose: it is non-zero as soon as they find a flaw,
accepted or not.

Two ways this watch can still go quiet, neither closed:

- **GitHub disables scheduled workflows on a public repository after 60 days without activity.**
  Renovate's weekly pull requests keep this one alive only while they are being merged.
- **The mail for a failed scheduled run was not proven.** The failure itself was, on the pull
  request of LUMN-73. Proving the scheduled path would take a red `main`.

**Why `npm audit` and `composer audit` are not required checks.** They report the whole tree, so
they fail on flaws nobody can fix. `npm audit` also counts one advisory once per package that
depends on it: the "12 high severity vulnerabilities" of 2026-10-03 were a single advisory on
`braces`, with no fixed version, reached through `stylelint`. Read the distinct advisories before
reading the total, and never run `npm audit fix --force` without reading what it proposes — that day
it was a downgrade of `stylelint-order` from 8 to 0.2.2. The advisory audit is what makes them
usable: it keeps their coverage and drops their noise.
