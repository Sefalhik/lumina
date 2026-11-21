# Dependency updates — Renovate — what it does, and why

Part of [Dependency updates](README.md).

## What it does

| What | Behaviour |
|---|---|
| npm minor + patch | one grouped PR per week |
| Composer minor + patch | one grouped PR per week |
| Any major of a library | held until approved from the **Dependency Dashboard** issue |
| GitHub Actions | one grouped PR for every update, **majors included**, no approval — see [GitHub Actions are routine](#github-actions-are-routine-majors-included) |
| **Node majors** | **once LTS**, held until approved from the dashboard — read from `.nvmrc`, see [Node: the next LTS, never a release before it](#node-the-next-lts-never-a-release-before-it) |
| **PostgreSQL majors in CI** | **disabled** — see [Runtime majors](#runtime-majors) |
| **`"php"` constraint of `composer.json`** | **disabled** — same reason |
| GitHub Actions, CI Docker images | pinned to a SHA / digest, digests kept current |
| Lockfiles | refreshed weekly |
| Release age | 3 days for npm and for GitHub Actions; npm also in `.npmrc` — see [Release age](#release-age) |
| Vulnerability fixes | at Renovate's next run, bypassing schedule and release age — **only for an open Dependabot alert**, see [Installing — once](renovate-day-to-day.md#installing--once), and **an alert does not trigger that run**: see [An alert does not wake Renovate up](renovate-day-to-day.md#an-alert-does-not-wake-renovate-up) |
| Known vulnerabilities | listed on the dashboard, **direct dependencies only** — see [Installing — once](renovate-day-to-day.md#installing--once) |
| Abandoned packages | flagged on the dashboard (`abandonments:recommended`) |

**Schedule: all day Monday, Europe/Paris.** At most 3 open PRs, 2 created per hour.

**One dependency is out of Renovate's sight**: the `./frankenphp` binary, updated by a script of
this repository under the same release age — see [The FrankenPHP binary](frankenphp.md#the-frankenphp-binary).

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

`scripts/update-frankenphp.sh` applies the same delay to the FrankenPHP binary and is not a third
copy: it reads the number from `.npmrc` — see [The FrankenPHP binary](frankenphp.md#the-frankenphp-binary).

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
[A ticked box, a green job, no PR](renovate-day-to-day.md#a-ticked-box-a-green-job-no-pr). That path was deduced from
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

Renovate can only change the version written in the repository. Accepting "postgres 19" would
move CI alone while production stays on the version its host offers — CI would then test a runtime
production does not run. A runtime major is a coordinated change across every environment, with its
own ticket (LUMN-55 for PostgreSQL 18). Patches and image digests still flow.

PostgreSQL majors stay disabled: the version this project runs is the one production runs, and
production takes the latest major its host offers — a rule Renovate cannot express. See
[The PostgreSQL version](../deployment/postgresql-version.md).

The `"php"` constraint is disabled for the same reason. `^8.5` already admits 8.6, so nothing would
be proposed today — but only as a side effect of the range strategy, which is not a guarantee.
Renovate does not read `setup-php`'s `php-version` at all.

### Node: the next LTS, never a release before it

Node is the exception, since LUMN-97: its majors are **proposed once they reach LTS**, and held for
approval on the dashboard like any other major.

**The version is written once, in `.nvmrc`** — a bare major, `24`, so that its patches flow. Every
`actions/setup-node` step reads it with `node-version-file`, nvm reads it on the developer machine,
and Renovate tracks it with its `nvm` manager. `scripts/__tests__/node-version.test.js` fails when a
workflow writes a version of its own, or when the alwaysdata row of `docs/deployment/README.md` names a
number instead of pointing to the file. That row is the one copy left: the panel is set by hand.

**Why only LTS lines are proposed.** Renovate's `node` versioning treats a major as unstable until
its line reaches LTS, and `ignoreUnstable` — on by default — keeps unstable releases out. Measured
on 2026-11-07 with `.nvmrc` set to `24`: `nodejs/node` listed v25.0.0, v26.0.0 and
v27.0.0-alpha.0.0.0; the dry run proposed `major:26` alone, on `renovate/node-26.x`. Set to `26`,
it proposed nothing. Node 25 never reached LTS; 27 is the first major of the yearly schedule, and
will be proposed when it does.

**Still a coordinated change.** The pull request changes `.nvmrc`, which moves CI and the
developer machine at once — but not alwaysdata, where `npm ci` and `npm run build` run on every
deployment with the version the panel selects. Approving the update means a ticket that moves the
panel, deploys, and checks the build there.

**Where it stands, 2026-11-07: Node 24, with 26 waiting on the dashboard.** Node 26 is LTS since
2026-10-28, but alwaysdata's panel offers Node 24 alone — as it already did on 2026-11-05. Moving
CI and the developer machine ahead of it would test a runtime the preproduction does not run, the
very thing this section forbids. `.nvmrc` therefore stays at `24`, and Renovate proposes
`renovate/node-26.x` for approval: approve it the day the panel offers 26, under LUMN-99, which
moves the panel first.

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
deployment workflow alone would be updated without ever being run before the merge.

The deployment workflow exists since LUMN-50, and the rule did not have to be narrowed:
`deploy.yml` uses **no action at all**, and `scripts/__tests__/deploy-workflow.test.js` fails the
day it does. That was decided for another reason first — no third-party code next to the SSH key,
see [The pipeline's access to the server](../deployment/pipeline.md#the-pipelines-access-to-the-server) — and
it keeps this rule true as a consequence. **Narrow the rule the day a deployment workflow needs an
action** (LUMN-51 to 54).

The rule matches `depType: action`, which leaves out the `postgres` service image: see
[Runtime majors](#runtime-majors). The Node version is no longer in the workflows at all — see
[Node: the next LTS, never a release before it](#node-the-next-lts-never-a-release-before-it).

### No auto-merge

Every PR waits for a human merge. The repository's *Allow auto-merge* setting is
off, so even a misconfigured rule cannot merge anything. Enabling it is a separate decision, to take
after observing a few cycles. **The day it is enabled, the npm release age goes to 14 days in the
same change** — see [Release age](#release-age).
