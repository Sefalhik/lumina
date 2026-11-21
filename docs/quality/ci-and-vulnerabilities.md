# Quality tooling — Continuous integration and known vulnerabilities

Part of [Quality tooling](README.md).

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

**PostgreSQL** : each PHP-dependent job spins up its own `postgres:18` service — the version production runs, see [The PostgreSQL version](../deployment/postgresql-version.md). The PHP job uses `cardascia_it_test`; the E2E job uses `cardascia_it_e2e`. The workflow sets `DB_PORT: 5432` which overrides the phpunit.xml default (5433).

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
[The pipeline's access to the server](../deployment/pipeline.md#the-pipelines-access-to-the-server).

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
| `Security — Dependency review` | `ci.yml`, a [required check](../git-workflow.md#required-checks) | what a pull request *introduces* |
| CodeQL, default setup | repository setting | JavaScript and the GitHub Actions workflows, on pull requests and weekly |
| Secret scanning with push protection | repository setting | a credential committed by mistake, refused at push |

```bash
gh api repos/Sefalhik/lumina/dependabot/alerts --jq 'length'             # 403 when alerts are off
gh api 'repos/Sefalhik/lumina/dependabot/alerts?state=auto_dismissed' --jq 'length'   # 0, or a preset is dismissing alerts
gh api repos/Sefalhik/lumina/code-scanning/default-setup --jq '.state'   # configured
gh api repos/Sefalhik/lumina --jq '.security_and_analysis'               # secret scanning
```

The Dependency Dashboard lists known vulnerabilities too, for direct dependencies only — a
convenience, not a watch: [Dependency updates](../dependencies/renovate-day-to-day.md#installing--once).

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
[Installing — once](../dependencies/renovate-day-to-day.md#installing--once). `braces` has no fixed version, and
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

**A reason that rests on a fact gets a test of that fact.** The two acceptances of 2026-11 rest on
`@vue/test-utils` bundling the code of `js-beautify` and never loading the installed package, whose
dependencies carry the flaws. `scripts/__tests__/advisory-premises.test.js` reads every built file of
`@vue/test-utils` and fails the day one loads `js-beautify` — so the update that breaks the premise
also says the reasons need reviewing (LUMN-152).

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
