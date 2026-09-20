# Smoke tests

Delivered by LUMN-49. `php artisan deploy:smoke` answers one question: **does this deployment, in
this environment, work?** It runs right after a deployment, and its exit code decides whether the
release is promoted.

## What a smoke test is, and is not

It does **not** test features — the E2E suite does that in CI, with hundreds of assertions. It tests
**the deployment**: every way a deployment can break. "Exhaustive" means exhaustive in failure modes,
not in features. A smoke test that tries to check everything becomes slow and flaky, and a flaky gate
ends up bypassed.

This command is the view **from the outside**, the one a visitor has. Three sibling tickets complete
it:

| Layer | Ticket |
|---|---|
| Outside HTTP probes — this document | LUMN-49 |
| Checks on the server, on the new release, **before** switching to it | LUMN-58 |
| A real browser: Vue islands start, no console error | LUMN-59 |
| An observation window after deploying, and continuous monitoring | LUMN-60 |

## Principles

1. **Check the served release first.** If the switch to the new release failed, every other probe
   tests the old one — and passes.
2. **No side effect.** No write, no login attempt: it must be safe against production at any time. A
   wrong password would even eat into the login rate limit (LUMN-36).
3. **Fast and deterministic.** Under a minute. No retry, except a warm-up on the first request.
4. **The same suite everywhere**, parameterised by the URL.
5. **Two levels.** Blocking (exit code 1) or warning (printed, exit code 0).
6. **Every probe has a reason to exist**, and every failure says what to do.

## Running it

```bash
# preprod is behind Basic auth: credentials come from the environment, never from an option
SMOKE_BASIC_USER=… SMOKE_BASIC_PASSWORD=… \
  php artisan deploy:smoke --url=https://preprod.cardascia-it.org

php artisan deploy:smoke --url=https://cardascia-it.org --expect-release=<sha> --format=junit
```

| Option | Effect |
|---|---|
| `--url` | base URL of the environment. A URL carrying `user:password@` is **refused**, not stripped |
| `--expect-release` | commit SHA the environment must serve. Without it, the release probe is skipped |
| `--format` | `text` (default), `json`, or `junit` for CI annotations |

Credentials are read from `SMOKE_BASIC_USER` / `SMOKE_BASIC_PASSWORD` (`config/smoke.php`) and never
rendered, in any format or log. Thresholds and the warm-up live in `config/smoke.php`.

## The probes

| # | Probe | Level | Why it exists |
|---|---|---|---|
| 1 | `X-Release` equals `--expect-release` | blocking | a failed switch leaves the old release serving |
| 2 | `/up` answers 200 — **database included** | blocking | the most likely failure of a deployment |
| 3 | every public page answers 200 — **the list is derived, see below** | blocking | the missing `.htaccess` and the fatal Telescope provider of 2026-09-14 |
| 4 | no placeholder text (`fallback_*` of `lang/`) on the homepages | blocking | the content seeder never ran |
| 5 | each locale's biography differs from the French one | blocking | translations not shipped |
| 6 | the canonical URL is exactly the URL queried | blocking | the `trustProxies` defect of 2026-09-14 |
| 7 | the compiled CSS and JS the page loads answer 200 with the right type | blocking | a missing or stale build; a leftover `public/hot` |
| 8 | the **three** `/e2e/*` helper routes answer 404 | blocking | the E2E backdoor of 2026-09-14 |
| 9 | `/telescope` answers 404 | blocking | the same `APP_ENV` drift, publishing every query, exception and session |
| 10 | `/.env`, `/.git/HEAD`, `/composer.json`, `/vendor/autoload.php` answer 403 or 404 | blocking | a document root on the project instead of `public/` |
| 11 | a missing page answers 404, **and** a refused method shows no stack trace | blocking | `APP_DEBUG=true` in production |
| 12 | `/fr/admin` redirects to `/fr/login` | blocking | the auth middleware chain |
| 13 | `/fr/login` answers 200 with a session cookie, an `XSRF-TOKEN` cookie and a CSRF field | blocking | the `database` session store |
| 14 | `/` with `Accept-Language: de` redirects to `/de` | blocking | `LocaleResolver` |
| 15 | `http://` redirects permanently (301 or 308) to `https://` | blocking | a temporary redirect is not cached; plain HTTP must never serve |
| 16 | certificate: under 3 days blocks, under 14 warns | mixed | a renewal that stopped working |
| 17 | `/.well-known/acme-challenge/…` answers 404, **never 401** | blocking | the Basic auth trap of 2026-09-14 |
| 18 | `/api/geo` returns a location, not `{"error": true}` | warning | the one **outbound** call of the deployment: egress rules, DNS, the upstream's rate limit |
| 19 | homepage response time over the budget | warning | caches not warm |

Security headers and `noindex` on preprod are not probed yet: LUMN-37 adds them, and their probes
with them.

### Five probes that are easy to get wrong

**Probe 10 cannot rely on a 404.** Laravel ships a page for 404 and renders it even with
`APP_DEBUG=true` — measured on 2026-09-19: a 404-only probe passed on a machine in debug mode. A 405
has no dedicated page, so debug mode prints the whole stack trace. The probe sends `PATCH /fr`, which
the router refuses before any controller runs: still read-only.

**Probe 16 is sent without the Basic credentials**, while every other probe sends them. It exists to
prove the challenge path is exempted from Basic auth; with the credentials it would pass even with the
exemption gone. A test asserts both halves.

**Probe 2 has to attribute its failure before reporting it.** The listener makes the database the
first suspect of a **500** — but it explains nothing about a 502/503/504 served by Apache alone, a
401 from the Basic auth in front of the site, or a 404 from a document root pointing at the wrong
directory. Sending the reader to `DB_HOST` when PHP never ran costs more than saying nothing, so the
probe branches on the status family and each branch names a different place to look. The warm-up
retries a 5xx twice first, so what reaches these messages is a persistent failure, not a cold
release.

*Measured on 2026-09-20, and not yet used:* a 500 rendered by the application still carries
`X-Release` — the exception is converted inside the pipeline, so the response leaves through the
global middleware. Once LUMN-50/51 write a `RELEASE` file on every environment, the header's
**absence** becomes a far better discriminator than the status code: no `X-Release`, no PHP. It
cannot be used before then, because preprod legitimately has no release file today and the probe
would blame the web server for it.

**Probe 3 writes down no list of pages.** `DeployedSite::publicPaths()` derives it: the homepage in
every indexable locale, then every other route of `config/seo.php` → `public_routes`, in French. That
allowlist has to be updated for canonical and `hreflang` anyway, so a new public page is probed the
day it is added rather than the day someone remembers. Two lists would drift, and the one that would
be forgotten is this one — nothing fails when a page merely goes unprobed. Routes needing more than
`{lang}` are skipped: `blog.show` has no legitimate slug to invent. `DeployedSitePathsTest` asserts
the derivation rather than the result.

**Probe 5 needs a stable marker.** Comparing whole pages proves nothing: the interface strings differ
between locales even when the biography stayed in French. The homepage's hook paragraph carries
`data-smoke="bio"`, and `SmokeMarkerTest` fails if the template loses it.

## What the application adds for it

- **`/up` checks the database.** `App\Listeners\CheckDatabaseOnHealthDiagnosis` runs `select 1` on
  Laravel's `DiagnosingHealth` event; any exception turns `/up` into a 500. Before, `/up` answered 200
  with the database down. The route belongs to the framework — `withRouting(health: '/up')` — and the
  event is its documented extension point, so there is no second endpoint to keep in sync and a
  future dependency check is one more listener.
  **Nothing registers this listener**: Laravel discovers classes in `app/Listeners` from the
  type-hint of `handle()`. Move the file or rename the method and it stops working in silence, so
  `HealthDatabaseTest` is the only thing standing between that and a health check that cannot fail.
  It holds: neutralising the listener's body fails two of its three tests (measured 2026-09-20).
  `select 1` stays deliberately trivial — LUMN-60 will poll `/up` continuously, and a health
  endpoint running an expensive query becomes the outage it was meant to detect. The depth lives
  elsewhere: probes 3 to 5 read the homepage, which cannot render without a migrated, seeded
  database. Migrations *pending* are not observable from the outside at all — that is LUMN-58.
- **`X-Release` on every response.** The deployment script writes the commit SHA into a `RELEASE` file
  at the root of the release (LUMN-50/51). `config/app.php` reads it through `App\Services\Release`, so
  `config:cache` freezes it — no file read per request. No file, no header: never an empty one.
  `RELEASE` is gitignored, and a test fails if one is ever committed. The SHA is no secret here: the
  repository is public. On a private repository, the header would be reserved to authenticated
  requests.

## What is instrumented, and what deliberately is not

`logging-conventions.md` says "every service, controller and job — no exceptions". Applied here that
gives three answers, not one.

| Component | Logs | Why |
|---|---|---|
| `DeploySmoke` | **yes** — one line per failed check, plus a summary | The command is what a pipeline reads; when a deployment is refused at three in the morning, these lines are what remains |
| `DeployedSite` | **yes**, on a connection failure | A request that never reached the site is the one thing a probe cannot describe on its own |
| The probes | **no**, on purpose | A probe returns its result; logging it too would duplicate every line the command already writes, at seventeen times the volume |
| `AddReleaseHeader` | **no**, on purpose | It reads a cached config value and sets a header: no I/O, no failure mode, and it runs on **every** request — one line per response would be noise at request rate |
| `CheckDatabaseOnHealthDiagnosis` | **no** | Its failure *is* the log: the exception it lets through is reported by the framework, with its stack trace, and turns `/up` into a 500 |

**The level follows the severity**, per the decision tree of the conventions: a blocking failure is
an operation failure (`error`), a warning is an expected, handled one (`warning`), and the closing
summary is a business event (`info`). Credentials never reach a log: they travel in an
`Authorization` header, never in a URL, and `SmokeTarget` refuses a URL that carries them.

## Where the code lives

One probe, one class, one test file beside it. The catalogue is the only place that lists them, and
it is what reads at a glance.

The folders follow a probe's signature, `check(DeployedSite $site): SmokeCheck`: `Site/` is what a
probe is handed, `Report/` is what it gives back. The three files left at the root are the three to
read first — what is checked, how it runs, and what a probe must implement.

| Path | Role |
|---|---|
| `app/Services/Smoke/SmokeCatalogue.php` | the 19 probes, **in the order they run** — the release first |
| `app/Services/Smoke/SmokeTestService.php` | walks the catalogue; 38 lines |
| `app/Services/Smoke/SmokeProbe.php` | the contract: `id()`, `label()`, `check()` — implemented 19 times |
| `app/Services/Smoke/Probes/` | one class per probe, each carrying the failure it exists for |
| `app/Services/Smoke/Site/DeployedSite.php` | the environment under test: HTTP client, credentials, warm-up, the public paths derived from `config/seo.php`, and the responses several probes share — fetched once |
| `app/Services/Smoke/Site/SmokeTarget.php` | the target; refuses credentials in the URL |
| `app/Services/Smoke/Site/Html.php` | canonical, compiled assets, `data-smoke` markers — pure functions |
| `app/Services/Smoke/Site/CertificateInspector.php` | interface — the TLS probe opens a raw socket, which `Http::fake()` cannot intercept |
| `app/Services/Smoke/Site/StreamCertificateInspector.php` | the real reader: SNI and peer verification on |
| `app/Services/Smoke/Report/SmokeCheck.php` | one result: severity, status, detail, remedy |
| `app/Services/Smoke/Report/SmokeReport.php` | text, JSON and JUnit rendering |
| `app/Enums/SmokeSeverity.php` | `Blocking` stops a deployment, `Warning` is printed and does not |
| `app/Enums/SmokeStatus.php` | `Passed`, `Failed`, `Skipped` — skipped is not a pass: the probe could not apply, and the report says why |
| `app/Console/Commands/DeploySmoke.php` | options, orchestration, exit code, logging |
| `app/Http/Middleware/AddReleaseHeader.php` | adds `X-Release` to every response, when a release is known |
| `app/Listeners/CheckDatabaseOnHealthDiagnosis.php` | makes `/up` query the database |
| `tests/Concerns/FakesDeployedSite.php` | a healthy deployed site faked at the HTTP layer; each test breaks one route |
| `tests/Unit/Services/Smoke/Site/DeployedSitePathsTest.php` | asserts the page list stays derived from `config/seo.php`, never written down |
| `tests/Feature/Documentation/SmokeCoverageTest.php` | fails when the deployment gains a capability — mail, a broker, storage — that no probe watches |

The enums stay in `app/Enums/` rather than moving here: that family is a project-wide convention, and
`DocumentationIndexTest` guards it. Breaking one rule to satisfy another is not tidying.

**A probe missing from the catalogue never runs**, and it would still have its own passing tests
beside it — nothing else would say so. `SmokeCatalogueTest` fails on exactly that, and on a
catalogue entry whose class no longer exists.

## When the tool itself breaks

A smoke suite is a diagnostic instrument, and an instrument that dies on its own bug is worse than
one that is missing: the pipeline learns nothing, and the stack trace it prints looks like an
outage.

- **A probe that throws is caught, once, in `SmokeTestService`.** It becomes a blocking check
  reading *"The probe itself failed"*, and the run continues. Blocking and not skipped: the
  deployment may well be healthy, but this run cannot say so, and a release is not promoted on an
  incomplete report. Resolution failures — a probe the container cannot build — are caught
  separately, because they happen before the probe can say its own name; the report then falls back
  to the class the catalogue names.
- **That catch is the only one of its kind, and it belongs there.** A probe's own unit test calls
  `check()` directly and keeps seeing raw exceptions: catching lower down would hide a bug in the one
  place it is looked for.
- **The exception message is redacted** through `SmokeTarget::redact()`. Every line this suite
  composes is credential-free by construction; the message of an exception a probe threw is composed
  by nobody and lands in the report like any other detail.
- **A `SmokeCheck` has three shapes and no fourth.** The constructor is private: `pass()`, `fail()`
  and `skip()` are the only ways in, and `fail()` refuses an empty remedy — a failure that says
  nothing about what to do is the thing this class exists to prevent, and it was a convention rather
  than a rule until 2026-09-20.

What was checked and needed nothing: JUnit rendering escapes hostile detail text (`DOMDocument`
handles `<`, `&`, quotes and drops invalid control characters), duplicate probe ids fail
`SmokeCatalogueTest`, and no probe writes.

## When a new capability needs a probe

Everything above probes what the site does **today**. The list is short because the site is small:
no mail is sent, no job is queued, nothing is uploaded. Those are not oversights, and probing a
transport nothing uses proves nothing.

The failure mode is the day one of them arrives and this file is not reopened. Three questions
answer it, and the second is the one that decides:

1. **Does the new capability cross the process boundary?** A service, a disk, a socket, a broker, a
   third party. Pure application logic is the E2E suite's job, not this one.
2. **Can it be configured correctly on a development machine and wrongly on the server?** Every
   probe here exists for a yes: a driver, a credential, a path, an egress rule, a document root. A
   no means the CI suite already covers it.
3. **Would its failure be silent?** `/api/geo` answers 200 either way; `/up` used to answer 200 with
   the database down. A capability that fails loudly on the homepage is already probed by probe 3.

Two yeses and a maybe: write the probe. What the answers should be, for what is coming:

| Capability | Probe, when it lands | Reads |
|---|---|---|
| Outgoing mail | yes | the transport is reachable and authenticated — **never** by sending to a real address |
| A queue on a broker (RabbitMQ…) | yes | the broker answers, and **a worker is consuming**: a full queue with no consumer is the silent failure |
| Disk storage, uploads | yes | `public/storage` resolves — `storage:link` is the classic forgotten step |
| A scheduler | no, not here | nothing of it is observable from outside — it belongs to LUMN-58, on the server |
| A cache or session driver change | already covered | probe 13 reads a real session cookie |

**`tests/Feature/Documentation/SmokeCoverageTest.php` is what keeps this honest**, rather than the paragraph above: it fails when a
capability appears in the codebase and no probe mentions it. Its limit is worth stating — it only
sees what leaves a mechanical trace, a directory, a config value, a call. "We now depend on someone
else's API" leaves none, and stays a question to ask.

## Adding a probe

1. Name the failure it catches — ideally one that happened. A probe without a reason is noise; its
   docblock is where that reason lives.
2. Add the class in `Probes/`, implementing `SmokeProbe`, and keep it read-only.
3. **List it in `SmokeCatalogue::PROBES`**, at the position it should run. A test fails otherwise.
4. Write its test beside it in `tests/Unit/Services/Smoke/Probes/`: it passes on the healthy fake
   site, and fails on its one breakage.
5. Add its row to the catalogue table above.
