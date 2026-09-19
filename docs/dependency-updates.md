# Dependency updates — Renovate

Delivered by LUMN-21. Configuration: `renovate.json5` at the repository root — JSON5 rather than
JSON so every rule carries its reason in place.

Renovate runs as the hosted **Mend Renovate GitHub App** (free "Community Cloud" tier), installed on
this repository only. Nothing runs in CI: the app reads the repository on Mend's servers, pushes its
own `renovate/*` branches and opens pull requests like any contributor. Those PRs go through the
same ruleset as every other one — three required checks, squash only, no bypass.

## What it does

| What | Behaviour |
|---|---|
| npm minor + patch | one grouped PR per week |
| Composer minor + patch | one grouped PR per week |
| Any major | held until approved from the **Dependency Dashboard** issue |
| **Node and PostgreSQL majors in CI** | **disabled** — see [Runtime majors](#runtime-majors) |
| **`"php"` constraint of `composer.json`** | **disabled** — same reason |
| GitHub Actions, CI Docker images | pinned to a SHA / digest, digests kept current |
| Lockfiles | refreshed weekly |
| Vulnerability fixes | immediately, bypassing schedule and release age |
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

`config:best-practices` holds npm releases for 14 days: a malicious version is
usually unpublished within hours, so waiting means never installing it. `minimumReleaseAgeBehaviour:
timestamp-required` treats a release with no publication date as too young. **Composer has no such
delay** — the preset targets npm only. Extending it needs Packagist to expose publication dates
first; without them, `timestamp-required` would hold every PHP update forever. Not decided yet.

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
times. A major keeps a human ticket, because it calls for a decision.

### No auto-merge

Every PR waits for a human merge. The repository's *Allow auto-merge* setting is
off, so even a misconfigured rule cannot merge anything. Enabling it is a separate decision, to take
after observing a few cycles.

## Installing — once

1. Open **https://github.com/apps/renovate** → **Install**.
2. Choose the `Sefalhik` account.
3. **Only select repositories** → `lumina`. Never *All repositories*.
4. Accept the permissions. *Workflows* write access is required: pinning actions by SHA edits
   `.github/workflows/*.yml`.

Because `renovate.json5` is already on `main`, Renovate skips its generic onboarding PR and starts
with this configuration. Install **after** a configuration change is merged, never before, or the
onboarding PR arrives with defaults and no JIRA key.

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
| a major, after approval | open a ticket first — it is a decision, not routine |

**Logs and manual runs**: the Mend developer portal, **https://developer.mend.io/** (sign in with
GitHub), lists the installed repositories, shows every job's log, and can trigger a run without
waiting for the next visit. That is where to look when a PR that should exist does not.

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
