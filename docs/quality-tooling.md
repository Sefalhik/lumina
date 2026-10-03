# Quality tooling

## Local quality audit

`npm run check:full` (or `bash scripts/check.sh`) runs all 7 checks in sequence with a colored summary:

```
  ESLint                    ✔  2s
  Stylelint                 ✔  1s
  PHPStan (app)             ✔  6s
  PHPStan (tests)           ✔  5s
  PHPUnit                   ✔  6s
  Vitest                    ✔  3s
  Playwright E2E            ✔  21s

  All 7 checks passed  in 44s
```

Exits 0 on success, 1 on any failure (shows the relevant output for the failing step).

**Prerequisite for E2E** : the `cardascia_it_e2e` database must exist — see [Playwright — dedicated E2E database](testing-conventions.md#playwright--dedicated-e2e-database).

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

### Accessibility static analysis (`eslint-plugin-vuejs-accessibility`)
Checks Vue templates at lint time for ARIA and semantic HTML issues — complements the runtime axe-core scan in `tests/e2e/accessibility.spec.js`.

Key rules enforced by `flat/recommended`:
- `form-control-has-label` — every `<input>`, `<select>`, `<textarea>` must have an accessible label (via `<label>`, `aria-label`, or `aria-labelledby`)
- `anchor-has-content` — `<a>` tags must have text or an `aria-label`
- `interactive-supports-focus` — interactive elements must be keyboard-reachable

These checks fire at commit time (lint-staged) and in CI — a missing `aria-label` blocks the commit.

### Globals
Browser globals (`window`, `document`, `sessionStorage`, `fetch`…) are declared via the `globals` package (`globals.browser`).
`route` is declared as a custom global — it's the Ziggy helper injected by the `@routes` Blade directive.

### Pre-commit integration
`lint-staged` runs `eslint` (check only, no `--fix`) after `prettier --write` on staged `.js` and `.vue` files. The commit is blocked if ESLint reports errors.

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
| `PHP — PHPStan + PHPUnit` | setup-php 8.5 (pcov) → composer install → key:generate → PHPStan app (level 8) → PHPStan tests (level 5) → `composer test:coverage` (80% threshold enforced) |
| `JS — ESLint + Stylelint + Vitest` | Node 24 → npm ci → ESLint → Stylelint → Vitest |
| `E2E — Playwright` | setup-php 8.5 + Node 24 → composer + npm install → `npm run build` → Playwright Chromium → key:generate → `npm run test:e2e` |
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

## Known vulnerabilities

Four things watch for them, and **three of the four are repository settings, not code** — no test in
this project can tell that one was switched off. The commands below read their state.

| What | Where it lives | What it covers |
|---|---|---|
| Dependabot alerts | repository setting | every known flaw in `composer.lock`, `package-lock.json` and the workflows; also what lets Renovate raise security fixes |
| `Security — Dependency review` | `ci.yml`, a [required check](git-workflow.md#required-checks) | what a pull request *introduces* |
| CodeQL, default setup | repository setting | JavaScript and the GitHub Actions workflows, on pull requests and weekly |
| Secret scanning with push protection | repository setting | a credential committed by mistake, refused at push |

```bash
gh api repos/Sefalhik/lumina/dependabot/alerts --jq 'length'             # 403 when alerts are off
gh api repos/Sefalhik/lumina/code-scanning/default-setup --jq '.state'   # configured
gh api repos/Sefalhik/lumina --jq '.security_and_analysis'               # secret scanning
```

**PHP has no security analysis.** CodeQL does not support it, and PHPStan checks types, not flaws.
Whether Psalm's taint analysis or Semgrep is worth adding is LUMN-66.

**The job log names only the first vulnerable package.** The action stops printing after the first
package it finds, even though it fails on any of them: fix that one and the next appears. Found
during the negative control of LUMN-65, where a PR adding `lodash@4.17.20` and
`dompdf/dompdf:1.2.0` reported dompdf alone; with dompdf removed, the same job failed on lodash.
Both lockfiles are therefore covered, and a red job with one name in it may hide others.

**Why no `npm audit` or `composer audit` in CI.** They report the whole tree, so they duplicate the
alerts and fail on flaws nobody can fix. `npm audit` also counts one advisory once per package that
depends on it: the "12 high severity vulnerabilities" of 2026-10-03 were a single advisory on
`braces`, with no fixed version, reached through `stylelint`. Read the distinct advisories before
reading the total, and never run `npm audit fix --force` without reading what it proposes — that day
it was a downgrade of `stylelint-order` from 8 to 0.2.2.
