# Smoke tests — What they are, and how to run them

Part of [Smoke tests](README.md).

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

**Those variables belong on the machine that probes, never on the one being probed** — this command
is the outside view, so it runs from a developer's machine or a CI runner. See
[Deliberately absent from preprod and production](../environment-variables.md#deliberately-absent-from-preprod-and-production).

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
