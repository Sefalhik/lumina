# Dependency updates — Renovate

Delivered by LUMN-21. Configuration: `renovate.json5` at the repository root — JSON5 rather than
JSON so every rule carries its reason in place.

Renovate runs as the hosted **Mend Renovate GitHub App** (free "Community Cloud" tier), installed on
this repository only. Nothing runs in CI: the app reads the repository on Mend's servers, pushes its
own `renovate/*` branches and opens pull requests like any contributor. Those PRs go through the
same ruleset as every other one — the [required checks](git-workflow.md#required-checks), squash
only, no bypass.

## What it does

| What | Behaviour |
|---|---|
| npm minor + patch | one grouped PR per week |
| Composer minor + patch | one grouped PR per week |
| Any major of a library | held until approved from the **Dependency Dashboard** issue |
| GitHub Actions | one grouped PR for every update, **majors included**, no approval — see [GitHub Actions are routine](#github-actions-are-routine-majors-included) |
| **Node and PostgreSQL majors in CI** | **disabled** — see [Runtime majors](#runtime-majors) |
| **`"php"` constraint of `composer.json`** | **disabled** — same reason |
| GitHub Actions, CI Docker images | pinned to a SHA / digest, digests kept current |
| Lockfiles | refreshed weekly |
| Release age | 3 days for npm and for GitHub Actions; npm also in `.npmrc` — see [Release age](#release-age) |
| Vulnerability fixes | immediately, bypassing schedule and release age — **only while Dependabot alerts are enabled**, see [Installing — once](#installing--once) |
| Abandoned packages | flagged on the dashboard (`abandonments:recommended`) |

**Schedule: all day Monday, Europe/Paris.** At most 3 open PRs, 2 created per hour.

## Why the configuration looks the way it does

### The schedule window is a whole day

The free app visits an *active* repository every 4 hours
and an *inactive* one once a day, at a time it chooses. A window narrower than a day — the 4–7am
first drafted in LUMN-21, or the 0–4am of the `:maintainLockFilesWeekly` preset — would usually be
missed, and no PR would ever be opened. Both schedules are set to the whole of Monday. Never narrow
them below 24 hours.

### Release age

**A release of an npm package or of a GitHub Action waits 3 days**, and the number is written twice
because nothing can read it from one place:

| Where | Setting | What it governs |
|---|---|---|
| `renovate.json5` | `minimumReleaseAge: '3 days'`, on the `npm` and `github-tags` datasources | what Renovate proposes |
| `.npmrc` | `min-release-age=3` | what an `npm install` or an `npx` typed by hand resolves |

A malicious version is usually pulled from the registry within hours, so waiting means never
installing it; three days also cover the 72 hours during which npm lets an author unpublish.
`minimumReleaseAgeBehaviour: timestamp-required` treats a release with no publication date as too
young. Security fixes are exempt: see [Renovate does not read `.npmrc`](#renovate-does-not-read-npmrc).

**Why 3 and not 14.** This document said 14 days from LUMN-21 until LUMN-64, and credited
`config:best-practices` with it. The preset sets 3: it was read in a job log on 2026-10-03, which is
the only place the applied value can be read. Fourteen days is what Renovate recommends *when
updates are automerged*, to make up for the reviewer who is not there — and nothing is automerged
here. Three days is also Dependabot's default; pnpm and Yarn ship one. The value is restated in
`renovate.json5` so that it no longer depends on what a preset decides.

**Two copies, one test.** Renovate's documentation recommends setting the delay in both places and
states that it cannot derive one from the other. `tests/Feature/Documentation/ReleaseAgeParityTest.php`
therefore fails when `.npmrc`, `renovate.json5` and the table above stop agreeing. Change the number
in all three, or the suite says which one was forgotten.

**Actions wait too, since LUMN-68.** A compromised release of an action runs with access to the
repository and its secrets, and CI cannot tell a compromised action from a healthy one — only
waiting can. Two different attacks, two different guards: a **newly published** malicious release
is what the delay is for; an **existing tag moved** to malicious code, as happened to
`tj-actions/changed-files` in March 2025, is what pinning by SHA is for. Renovate cannot see the
second — for a tag it reads the commit date, not the day the tag was pushed.

**Composer has no such delay.** Extending it needs Packagist to expose publication dates first;
without them, `timestamp-required` would hold every PHP update forever. Not decided yet.

Sources: [Renovate — minimum release age](https://docs.renovatebot.com/key-concepts/minimum-release-age/),
[Renovate — upgrade best practices](https://docs.renovatebot.com/upgrade-best-practices/) for the
14 days tied to automerge, and
[GitHub — the case for a cooldown](https://github.blog/security/supply-chain-security/the-case-for-a-cooldown-why-dependabot-now-waits-before-issuing-version-updates/).

### What the delay does to an install typed by hand

Measured on 2026-10-03 with npm 12.0.2 and 12.2.0:

| Command | Result |
|---|---|
| `npm ci`, or `npm install` on an up-to-date lockfile | unaffected — locked versions are not re-checked, even with a delay of ten years |
| a range, `npm install eslint@^10` | installs the newest version older than three days |
| an exact version younger than three days, for a package nothing else depends on | refused, `ETARGET` |
| the same for a package others peer-depend on (`eslint@10.12.0` that day) | **npm never answers** — interrupt it |
| `npx <package>@latest` | runs the newest version older than three days |

CI only runs `npm ci`, so it does not see the delay, and a security PR carrying a one-day-old fix
installs there as usual. To install a release that is too young once it has been read, pass
`--min-release-age=0` to that one command.

### Renovate does not read `.npmrc`

`renovate.json5` sets an `npmrc` option, and any string there **replaces** the repository file for
Renovate's own npm runs (`npmrcMerge` is `false` by default).

Without it, npm would apply `min-release-age` while Renovate rebuilds the lockfile — including for a
security fix, which Renovate deliberately exempts from the release age. A fix published the day
before would be refused by npm, or send it spinning, and the job would end like the one described in
[A ticked box, a green job, no PR](#a-ticked-box-a-green-job-no-pr). That path was deduced from
Renovate's source and from npm's measured behaviour; it cannot be exercised before a real alert.

Nothing is lost for routine updates: Renovate passes `--before=<now − 3 days>` to npm itself, which
protects transitive dependencies exactly as `min-release-age` would. The job log shows it — `Repo
.npmrc file is ignored due to config.npmrc`, then `Setting npm --before based on
minimumReleaseAge`.

**A setting added to `.npmrc` later — a private registry, for instance — has to be repeated in
that option**, or Renovate will not see it.

### Lock files are rebuilt by a full install

`skipInstalls: false` makes Renovate run a real `npm install` in its temporary clone instead of
`npm install --package-lock-only`. Only the lockfile is committed.

It works around [npm/cli#9800](https://github.com/npm/cli/issues/9800). npm 12 refuses by default
any dependency served from a URL (`allow-remote=none`), and wrongly takes a registry tarball for one
when the package declares `bundleDependencies` — but only in the modes that install nothing.
`@tailwindcss/oxide-wasm32-wasi`, an optional dependency of Tailwind 4, is such a package, so from
2026-10-03 every npm branch failed, without an error anywhere.

Two other ways out were rejected. Pinning Renovate to npm 11 with `constraints` ages if it is
forgotten, and has the bot write the lockfile with another npm than the developer machine.
`allow-remote=all` switches a protection off to get around a false positive.

The lockfile keeps the form a developer machine produces. Compared under npm 12.2.0, the two modes
differ by 7 entries out of 489: a full install does not record the six dependencies bundled inside
that wasm32-only package, which no real machine installs. **Remove the setting once #9800 is
fixed** — the first Renovate PR afterwards will add those six entries, and that is expected.

The workaround stops at Renovate: `npm install --package-lock-only` typed by hand still fails under
npm 12.

### Runtime majors

Renovate can only change the version written in `ci.yml`. Accepting "Node 26" or
"postgres 19" would move CI alone while the developer machine and alwaysdata stay behind — CI would
then test a runtime production does not run. A runtime major is a coordinated change across every
environment, with its own ticket (LUMN-55 for PostgreSQL 18). Patches and image digests still flow.

The `"php"` constraint is disabled for the same reason. `^8.5` already admits 8.6, so nothing would
be proposed today — but only as a side effect of the range strategy, which is not a guarantee.
Renovate does not read `setup-php`'s `php-version` at all.

### `:pinDevDependencies` is ignored

The preset pins devDependencies to exact versions; this project
keeps every constraint on `^` because the committed lockfile and `npm ci` already fix what is
installed.

### Routine updates carry no JIRA key

`jira-sync.yml` finds none in a Renovate branch or title and
exits cleanly. Removing those tickets is the point: LUMN-2, 8, 16 and 20 were one task filed four
times. A major of a library keeps a human ticket, because it calls for a decision.

### GitHub Actions are routine, majors included

Every update of an action arrives in one grouped pull request, `renovate/github-actions`, without
approval and without a ticket — a major like a patch, and straight to the latest major rather than
one at a time.

An action runs nowhere but in CI, and the pull request that updates it runs the new version: a
breaking change is a red check before the merge. LUMN-68 read the five majors that were waiting —
`checkout` 6 and 7, `setup-node` 6 and 7, `cache` 6 — and none changed anything here. They were
internal rewrites, and there was no decision for a ticket to record.

**This holds only while every action is exercised by a pull request.** That is true today: every
job of `ci.yml` runs on pull requests, and `jira-sync.yml` uses no action. An action used by a
deployment workflow alone would be updated without ever being run before the merge — **narrow the
rule the day one exists** (LUMN-50 to 54).

The rule matches `depType: action`, which leaves out the `postgres` service image and the Node
version: see [Runtime majors](#runtime-majors).

### No auto-merge

Every PR waits for a human merge. The repository's *Allow auto-merge* setting is
off, so even a misconfigured rule cannot merge anything. Enabling it is a separate decision, to take
after observing a few cycles. **The day it is enabled, the npm release age goes to 14 days in the
same change** — see [Release age](#release-age).

## Installing — once

1. Open **https://github.com/apps/renovate** → **Install**.
2. Choose the `Sefalhik` account.
3. **Only select repositories** → `lumina`. Never *All repositories*.
4. Accept the permissions. *Workflows* write access is required: pinning actions by SHA edits
   `.github/workflows/*.yml`.

Because `renovate.json5` is already on `main`, Renovate skips its generic onboarding PR and starts
with this configuration. Install **after** a configuration change is merged, never before, or the
onboarding PR arrives with defaults and no JIRA key.

**Dependabot alerts have to be enabled on the repository**, or Renovate never raises a security
fix: GitHub's alerts are the only place it learns that a vulnerability exists. This document
promised immediate fixes from LUMN-21 to LUMN-65 while the alerts were off, and nothing said so
but one debug line in a job log — `No vulnerability alerts enabled for repo`.

```bash
gh api repos/Sefalhik/lumina/dependabot/alerts          # 403 "disabled" when they are off
gh api -X PUT repos/Sefalhik/lumina/vulnerability-alerts # turns them on
```

Leave *Dependabot security updates* **off**: it opens its own pull requests, which would duplicate
Renovate's.

To change the repository selection or uninstall: GitHub → *Settings* → *Applications* →
*Installed GitHub Apps* → *Renovate* → *Configure*.

## Day to day

**The Dependency Dashboard** is an issue Renovate keeps up to date. It lists pending, held and
abandoned dependencies. Ticking the box next to a held major approves it: the PR is created at the
next visit, not immediately.

| A PR titled… | What to do |
|---|---|
| `Update npm (minor and patch)` / `Update composer (minor and patch)` | CI green → squash merge. No ticket. Run `npm run check:full` locally after pulling — the E2E preflight (LUMN-12) will ask for `npm run build` or a Playwright browser if needed |
| `Pin dependencies` / `Lock file maintenance` | same |
| labelled `security` | read the advisory, then same — may arrive any day |
| `Update github actions` | same — a major included, see [GitHub Actions are routine](#github-actions-are-routine-majors-included) |
| a major of a library, after approval | open a ticket first — it is a decision, not routine |

**Logs and manual runs**: the Mend developer portal, **https://developer.mend.io/** (sign in with
GitHub), lists the installed repositories, shows every job's log, and can trigger a run without
waiting for the next visit. That is where to look when a PR that should exist does not.

### A ticked box, a green job, no PR

Renovate reports a lockfile it could not rebuild at **debug** level. When `package.json` does not
change — the usual case, since the `^` ranges already admit the new versions — there is then
nothing to commit: the branch result is `no-work`, the job exits 0, and the dashboard moves the
update to *Other Branches* without a word.

Download the job log from the Mend portal and search for `lock file error`; the npm error is in the
`stderr` of that entry. This is how LUMN-64 was found, after several ticks that produced nothing.

## Checking a configuration change before merging it

```bash
npx --yes --package renovate -- renovate-config-validator --strict   # syntax and options
git add renovate.json5                                               # see the first trap below
GITHUB_COM_TOKEN=$(gh auth token) npx --yes renovate --platform=local --dry-run=lookup
```

The dry run reads the repository and queries the registries; it writes nothing and pushes nothing.
Add `LOG_LEVEL=debug LOG_FORMAT=json` and look for the `packageFiles with updates` and
`Branch is not pending` messages to see what it would open.

Three traps, all met while writing LUMN-21:

- **An untracked `renovate.json5` is silently ignored.** Renovate lists files with git; without
  `git add` it falls back to onboarding defaults with no warning. The first dry run proposed
  `postgres-18.x` and one branch per package — the configuration had not been read at all.
- **Without the token**, every GitHub-hosted lookup (actions, Node, PHP) is skipped behind a single
  warning.
- **The `local` platform stops before branch processing**, even with `--dry-run=full`. Approval
  gating and PR creation can only be observed on the real repository.
