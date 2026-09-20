# Environment variables

`.env.example` declares **which** variables exist. This file says what each one means and, for the
ones a server needs, **where its value comes from** — never the value itself.

51 variables, grouped by where the value originates rather than by the feature that reads them.
That grouping is the useful one in practice: "what do I have to put in this file, and who gives it
to me" is the question being asked, and it is also what makes the last table — the variables that
must **never** exist on a server — a group rather than a footnote.

`tests/Feature/Documentation/EnvironmentVariablesTest.php` fails on a variable declared in
`.env.example` and missing here, and on a credential carrying a value in the example file.

Related: [Deployment](deployment.md) for the sequence and the server settings,
[the preprod `.env` ready to fill](deployment.md#the-preprod-env-ready-to-fill), and
[One driver everywhere](deployment.md#one-driver-everywhere) for why session, cache and queue are
the same in every environment that serves a browser.

## Decided per environment

| Variable | Preprod value | Notes |
|---|---|---|
| `APP_ENV` | `production` | **See the warning below.** Not `preprod` |
| `APP_DEBUG` | `false` | A stack trace on a public URL names paths, packages and queries |
| `APP_URL` | the site's own https URL | Used by every generated absolute URL and by the sitemap |
| `LOG_LEVEL` | `warning` | `debug` on a public site writes a lot, and writes things worth not writing |
| `SESSION_DOMAIN` | the site's hostname | A mismatch here is a silent "login does nothing" |
| `SESSION_SECURE_COOKIE` | `true` | The host sets `HTTPS=on` itself; no trusted proxy is needed — see [Traps](deployment.md#traps-this-file-exists-because-of) |

> ⚠️ **`APP_ENV` must be `production` on every internet-facing host, preprod included.**
> `routes/e2e.php` defines `GET /e2e/admin-auth`, which creates an admin account, sets
> the 2FA session flag and logs the caller in — no password, no TOTP. It is loaded on
> an **allowlist** of `local` and `testing` (`bootstrap/app.php`). Any other value keeps
> it off, but `production` is the one that also turns off debug output and matches what
> the framework itself expects. Naming the environment after its role instead of its
> exposure is how a backdoor ends up on a public URL.

## Generated on the machine

| Variable | How |
|---|---|
| `APP_KEY` | `php artisan key:generate` — **once per environment**, never copied between them. Changing it invalidates every session and every encrypted column |

## From the alwaysdata admin panel

| Variable | Where |
|---|---|
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST`, `DB_PORT` | *Databases → PostgreSQL* |
| `DB_HOST` | `postgresql-cardascia-it.alwaysdata.net` — resolves to the account's PostgreSQL server |
| `DB_DATABASE` | **`cardascia-it_preprod`** — the forced prefix is the *account name*, hyphen included. Not `cardascia_it_`: that spelling cost a `database does not exist` on the first deployment |
| `DB_USERNAME` | **One user per environment** — `cardascia-it_preprod` here, its own for production. A leaked preprod `.env` then grants nothing on production, and revoking one touches neither the other nor the account's own user |
| `DB_PASSWORD` | Set from the panel; it is not displayed, only replaced |

The database was created with locale **`C.UTF-8`** rather than a language-specific one.
The site serves 24 languages, so no single collation is the right one for its content,
and `C.UTF-8` is the only choice immune to the glibc collation-version breakage that
silently corrupts indexes when the host upgrades its C library. Sorting for display is
the application's job, not the database's.

## Chosen once, and the same everywhere

| Variable | Value | Why |
|---|---|---|
| `SESSION_DRIVER` | `database` | See [One driver everywhere](deployment.md#one-driver-everywhere) |
| `CACHE_STORE` | `database` | Same |
| `QUEUE_CONNECTION` | `sync` | There is not one job in this application |
| `APP_MAINTENANCE_DRIVER` | `file` | Maintenance mode is often engaged *because* the database is unavailable. A maintenance page that needs the database to render is a maintenance page that will not render |

## Personal, from the password manager

| Variable | Notes |
|---|---|
| `ADMIN_EMAIL`, `ADMIN_NAME` | Read by `AdminSeeder` |
| `ADMIN_PASSWORD` | Read by `AdminSeeder` at first seed. **Change it after the first login** — it stays in the file otherwise |

## The application's own settings, the same everywhere

These are this project's knobs rather than the framework's. None is secret, and none differs between
environments — a value that never varies has no business being decided per environment.

| Variable | Default | What it does |
|---|---|---|
| `GEO_API_BASE_URL` | `http://ip-api.com/json` | Base URL of the geolocation API the boot overlay reads through `/api/geo` |
| `GEO_FETCH_TIMEOUT` | `3` | Seconds before that outbound call gives up. It sits in front of a page render, so it stays short |
| `GEO_CACHE_TTL` | `86400` | How long a successful lookup is kept |
| `GEO_FAILURE_CACHE_TTL` | `60` | How long a failure is kept — a negative cache, so an API outage costs one call a minute rather than one per visitor. It is also why a smoke run can report the geo probe red for a minute after the API recovers |
| `ANTHROPIC_MODEL` | `claude-haiku-4-5-20251001` | Model used by `i18n:translate` and `cms:translate`. Only ever read on a developer's machine, like the key beside it |

## Left at Laravel's default, on purpose

`APP_NAME`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE`, `BCRYPT_ROUNDS`, `LOG_CHANNEL`,
`LOG_DEPRECATIONS_CHANNEL`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`,
`BROADCAST_CONNECTION`, `FILESYSTEM_DISK`, `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `VITE_APP_NAME`.

They are listed here rather than omitted so that this file answers for **every** line of
`.env.example` — an undocumented variable and a deliberately-default one look identical otherwise,
and only one of the two is fine.

Two of them are worth a sentence anyway:

- **`APP_LOCALE` decides nothing on a public page.** `SetLocale` reads the `{lang}` route prefix, and
  `LocaleResolver` reads `Accept-Language` on `/`. The framework default only shows through for
  something rendered outside a request, such as a console command.
- **`MAIL_MAILER=log` is the current state of the mail story**: nothing is sent, and mail is written
  to the log. The day it changes, `SmokeCoverageTest` fails and asks for a probe — see
  [Smoke tests](smoke-tests.md#when-a-new-capability-needs-a-probe).

## Deliberately absent from preprod and production

| Variable | Why |
|---|---|
| `ANTHROPIC_API_KEY` | Since LUMN-29 the translated content ships **with the deployment**, in `database/data/homepage-content.php`. Neither `i18n:translate` nor `cms:translate` runs on a server. A key that is never used can only be leaked |
| `GEO_DEV_FALLBACK_IP` | Substitutes a public IP for a loopback address. There are no loopback visitors in production |
| `OCTANE_SERVER`, `OCTANE_HTTPS` | Octane is the development server. alwaysdata serves through Apache |
| `PHP_INI_SCAN_DIR` | Points at a local FrankenPHP build |
| `SMOKE_BASIC_USER`, `SMOKE_BASIC_PASSWORD`, `SMOKE_TIMEOUT` | `deploy:smoke` looks at a deployment **from the outside**, so it runs on a developer's machine or a CI runner — never on the server it probes. Putting the Basic credentials in the server's `.env` would store the password to the door inside the room it guards, for a command that host never runs. Same reasoning as `ANTHROPIC_API_KEY` above. In CI they are GitHub environment secrets |
