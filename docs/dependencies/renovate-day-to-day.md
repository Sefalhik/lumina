# Dependency updates — Renovate — installing it, and day to day

Part of [Dependency updates](README.md).

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

**Enabled is not enough: the alert has to be open.** Renovate reads open alerts and nothing else,
so two cases produce no fix and no message (LUMN-73):

- an alert GitHub dismissed on its own — its preset for development dependencies did exactly that,
  before any notification was sent;
- an advisory for which GitHub never raised an alert at all.

And an open alert is still not a pull request when the vulnerable package is transitive and its
parent pins it to an exact version: no lockfile update can move it. What watches the tree
regardless of alerts is the advisory audit —
[Known vulnerabilities](../quality/ci-and-vulnerabilities.md#known-vulnerabilities).

**The dashboard lists known vulnerabilities** (`dependencyDashboard.osvVulnerabilitySummary`), read
from OSV.dev rather than from GitHub. Renovate documents two limits: **direct dependencies only**,
and the option is experimental. An empty section does not mean a clean tree. The `local` platform
stops before the dashboard is built, so this cannot be tried in a dry run: it is read on the
Dependency Dashboard issue itself.

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
| `Update github actions` | same — a major included, see [GitHub Actions are routine](renovate.md#github-actions-are-routine-majors-included) |
| `Migrate config renovate.json5` | **never merge it**: Renovate rewrites the whole file and drops every comment. Port the renamed option by hand, under a ticket, keeping its comment; `renovate-config-validator --strict` then stops reporting `Config migration necessary`. Close the PR without merging it (LUMN-149) |
| a major of a library, after approval | open a ticket first — it is a decision, not routine. Move the ticket to *En review* by hand, and put its key in the PR title before merging: the squash commit takes that title, and `jira-sync.yml` reads it on merge. Or do the bump on a branch of your own, named after the ticket, when the PR has to carry anything else |

**Logs and manual runs**: the Mend developer portal, **https://developer.mend.io/** (sign in with
GitHub), lists the installed repositories, shows every job's log, and can trigger a run without
waiting for the next visit. That is where to look when a PR that should exist does not.

### An alert does not wake Renovate up

A security fix skips the schedule and the release age, but it still waits for Renovate to run, and
**the opening of a Dependabot alert starts no job**. The hosted app runs on a push to `main`, on its
own visit — once a day at best on an inactive repository, at a time it chooses — and when a run is
asked for from the Mend portal.

Measured on 2026-10-22 (LUMN-85). The first vite alert opened on 21/10 at 09:34 UTC, its fix
already published. No job ran for 32 hours. The next one started two minutes after a merge to
`main`, read the three open alerts, and built the security rules as configured — `[SECURITY]`,
`at any time`, no release age — then found nothing left to fix: the routine PR just merged had
already moved vite past the fix. Renovate had not failed; it had not run. The same job shows why
`shell-quote` never got a PR either: its rule asked for `< 1.11.0`, and `concurrently` pinned 1.9.0
exactly, out of reach of any lockfile update.

What reacted the same morning was the advisory audit: `security-audit.yml` failed on its daily
run, at 10:16 UTC — see [Known vulnerabilities](../quality/ci-and-vulnerabilities.md#known-vulnerabilities). When it
turns red on a direct dependency that has a fix, **ask for a run from the Mend portal** rather than
wait for the visit.

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
