# Testing conventions — Playwright — environments

Part of [Testing conventions](README.md).

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
- `storageState` — from the `adminSession` option, see [one admin session per spec file](playwright.md#playwright--one-admin-session-per-spec-file).

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
`SESSION_SECURE_COOKIE=false` (HTTP — not HTTPS like the dev server), `APP_ENV=testing` and
`TELESCOPE_ENABLED=false` — see [What the suite tests: the build](#what-the-suite-tests-the-build).

### What the suite tests: the build
**The pages under test are the compiled build, served by an application that knows it is under
test** (LUMN-106) — locally as in CI, whether or not `npm start` keeps a Vite dev server running.

| Setting | Where | What it does |
|---|---|---|
| `APP_ENV=testing` | `serverEnv()`, `helpers/workers.js` | the application ignores `public/hot` (`AppServiceProvider`, a hot file that never exists) and serves `public/build/`; nothing meant for a developer's machine runs |
| `TELESCOPE_ENABLED=false` | the environment shared by the servers and the commands that prepare the databases | Telescope's own provider is discovered in every environment: `testing` alone does not stop it |
| `reuseExistingServer: false` | `playwright.config.js` | a server already on a slot's port keeps the environment it was started with; a busy port now stops the run instead |

**Why.** Until 2026-11-21 the servers ran in `local`. Wherever a Vite dev server ran, the pages
loaded their scripts from it, module by module, over the network. A single import lost to
`net::ERR_NETWORK_CHANGED` — Chromium drops requests in flight when a network interface changes,
which Docker does all day — failed the whole of `app.js`, and no Vue island mounted. Two specs
failed that way, at random, for no fault of the code they test (`refused-storage`, `motion`); taken
alone they passed 40 times out of 40. CI, which has no dev server, never saw it. Telescope, on the
same servers, recorded about 3,400 entries per slot database in one run; now none.

**The dev server, on purpose only.** `E2E_DEV_SERVER=1` runs the servers in `local` against the dev
server, to iterate without rebuilding, and says so at the start of the run. Any other value is
refused. `tests/e2e/tested-build.spec.js` proves the default from the browser — the environment
through `/e2e/environment`, every script and stylesheet from `/build/` — and skips itself in that
mode.

The cost is a build: the preflight asks for `npm run build` whenever the build is older than its
sources — see [E2E preflight](../quality/audit-and-analysis.md#e2e-preflight--the-audit-refuses-rather-than-lies-lumn-12).
`npm run test:e2e` runs no preflight: after changing the front end, build first.

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
