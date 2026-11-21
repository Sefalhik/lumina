# Quality tooling — The audit, the linters and static analysis

Part of [Quality tooling](README.md).

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

**Prerequisite for E2E** : the `cardascia_it_e2e` template database must exist, and the database role must be allowed to create databases — one copy per Playwright worker is made from it on every run. See [The databases](../testing/playwright-environments.md#the-databases).

### E2E preflight — the audit refuses rather than lies (LUMN-12)

The Playwright step runs `node scripts/e2e-preflight.js` first. It **detects and never repairs**:
each refusal names the exact command to run, and the suite does not start.

| Precondition | Refused when | Fix it names |
|---|---|---|
| by default — whether or not Vite runs | `public/build/manifest.json` is missing, or older than any file in `resources/`, `vite.config.js` or `package-lock.json` | `npm run build` |
| `E2E_DEV_SERVER=1` | `public/hot` is absent, or nothing listens at the URL it holds — a Vite that died leaves the file behind, and every page would be tested without CSS | `npm run dev`, or unset `E2E_DEV_SERVER` |
| `E2E_DEV_SERVER` set to anything but `1` | always: a misspelt mode would test one thing while its author believes it tests the other | set it to `1`, or unset it |
| always | the browser build the installed Playwright expects is not in `~/.cache/ms-playwright` | `npx playwright install chromium` |

Why those inputs: **Blade views** change the compiled CSS, because Tailwind scans them; the
**lockfile** changes the bundle without touching a source file — which is what every dependency
update, Renovate's included, does.

Why the build by default, since LUMN-106: the suite tests what a visitor receives, and the servers
ignore `public/hot` in that mode — see
[What the suite tests: the build](../testing/playwright-environments.md#what-the-suite-tests-the-build). Until then a
running Vite was enough to pass this check, and it hid a build older than 29 of its sources on
2026-11-21. The dev server mode is the explicit `E2E_DEV_SERVER=1`, announced at the start of every
run.

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

| Measure, `app/` | Largest on 2026-11-14 | Largest on 2026-11-15, after LUMN-110 | Threshold |
|---|---|---|---|
| One method | 14 (`SecurityHeaders::validated()`) | 14, the same | **15** |
| One class (sum of its methods) | 37 (`CmsTranslate`) | 34 (`SecurityHeaders`) — `CmsTranslate` down to 29 | **40** |

**PHPMD was measured too, and set aside.** It counts length in lines, comments included:
`TranslationCache` came out at 385 lines, next to the real outlier, because it is documented. In a
repository that documents its code at length, a length threshold would push to delete
explanations.

**No exception.** `I18nTranslate` measured 29 for `translatePhpFiles()` and 62 for the class, far
above everything else, and sat in `phpstan.neon` as the one dated exception. LUMN-110 rewrote it on
2026-11-15: its logic moved into `app/Services/Translation/`, the command now measures 28, and the
two `ignoreErrors` entries are gone. `ComplexityThresholdsTest` fails the day one comes back.

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
[PHP — a deprecation fails the suite](../testing/phpunit-and-vitest.md#php--a-deprecation-fails-the-suite).

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
[Environment variables](../environment-variables.md#deliberately-absent-from-preprod-and-production).
