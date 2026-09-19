# Deployment — cardascia-it.org

Reference for running this application somewhere other than a developer's machine.
Hosting is **alwaysdata**; the site is served by **Apache + PHP-FPM**, behind a
TLS-terminating front proxy.

**This file contains no secret values and never will.** It documents where each
value *comes from*, so that a new environment can be rebuilt without hunting.

---

## Environments

| | Address | Served by | Purpose |
|---|---|---|---|
| dev | `dev.cardascia-it.org` | FrankenPHP / Octane, local | Development |
| **preprod** | `preprod.cardascia-it.org` | alwaysdata, Apache | The new site, until it is validated |
| prod (current) | `cardascia-it.org` | one.com, previous technology | Stays online and untouched |
| prod (target) | `cardascia-it.org` | alwaysdata, Apache | Not yet — see [Not done yet](#not-done-yet) |

The old site keeps its address while the new one is built at a temporary one. No
cutover happens until the editorial content is ready.

---

## Where a secret lives

Three homes, one per audience. **None of them is the repository.**

| Audience | Home |
|---|---|
| A human | A password manager. Not a file in `Documents`, not a note, not a chat message |
| The running application | `/home/cardascia-it/preprod/.env` on the server |
| The pipeline | GitHub repository *secrets*, encrypted, injected at deploy time |

The server-side `.env` sits **outside the document root** by construction: Apache is
pointed at `preprod/public`, so `preprod/.env` is one level above anything the web
server will serve. That is not a convenience — it is the whole reason Laravel puts
`index.php` in a subdirectory.

`.env` is listed in `.gitignore`. `.env.example` is versioned and holds **names only**,
never values. Between them: `.env.example` answers *which* variables exist,
this file answers *where each value comes from*.

---

## The environment file, variable by variable

51 variables. Grouped by where the value originates.

### Decided per environment

| Variable | Preprod value | Notes |
|---|---|---|
| `APP_ENV` | `production` | **See the warning below.** Not `preprod` |
| `APP_DEBUG` | `false` | A stack trace on a public URL names paths, packages and queries |
| `APP_URL` | the site's own https URL | Used by every generated absolute URL and by the sitemap |
| `LOG_LEVEL` | `warning` | `debug` on a public site writes a lot, and writes things worth not writing |
| `SESSION_DOMAIN` | the site's hostname | A mismatch here is a silent "login does nothing" |
| `SESSION_SECURE_COOKIE` | `true` | The host sets `HTTPS=on` itself; no trusted proxy is needed — see [Traps](#traps-this-file-exists-because-of) |

> ⚠️ **`APP_ENV` must be `production` on every internet-facing host, preprod included.**
> `routes/e2e.php` defines `GET /e2e/admin-auth`, which creates an admin account, sets
> the 2FA session flag and logs the caller in — no password, no TOTP. It is loaded on
> an **allowlist** of `local` and `testing` (`bootstrap/app.php`). Any other value keeps
> it off, but `production` is the one that also turns off debug output and matches what
> the framework itself expects. Naming the environment after its role instead of its
> exposure is how a backdoor ends up on a public URL.

### Generated on the machine

| Variable | How |
|---|---|
| `APP_KEY` | `php artisan key:generate` — **once per environment**, never copied between them. Changing it invalidates every session and every encrypted column |

### From the alwaysdata admin panel

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

### Chosen once, and the same everywhere

| Variable | Value | Why |
|---|---|---|
| `SESSION_DRIVER` | `database` | See [One driver everywhere](#one-driver-everywhere) |
| `CACHE_STORE` | `database` | Same |
| `QUEUE_CONNECTION` | `sync` | There is not one job in this application |
| `APP_MAINTENANCE_DRIVER` | `file` | Maintenance mode is often engaged *because* the database is unavailable. A maintenance page that needs the database to render is a maintenance page that will not render |

### Personal, from the password manager

| Variable | Notes |
|---|---|
| `ADMIN_EMAIL`, `ADMIN_NAME` | Read by `AdminSeeder` |
| `ADMIN_PASSWORD` | Read by `AdminSeeder` at first seed. **Change it after the first login** — it stays in the file otherwise |

### Deliberately absent from preprod and production

| Variable | Why |
|---|---|
| `ANTHROPIC_API_KEY` | Since LUMN-29 the translated content ships **with the deployment**, in `database/data/homepage-content.php`. Neither `i18n:translate` nor `cms:translate` runs on a server. A key that is never used can only be leaked |
| `GEO_DEV_FALLBACK_IP` | Substitutes a public IP for a loopback address. There are no loopback visitors in production |
| `OCTANE_SERVER`, `OCTANE_HTTPS` | Octane is the development server. alwaysdata serves through Apache |
| `PHP_INI_SCAN_DIR` | Points at a local FrankenPHP build |

---

## One driver everywhere

Until 2026-09-14 this project ran **three different session drivers** at once:
`redis` in development, `file` in CI, `array` under PHPUnit — with a fourth still to be
chosen for preprod. Redis was never a requirement: there are no jobs, no queues, and no
call to the `Redis` facade anywhere in `app/`. It stored sessions and a cache, nothing
else.

It was not free. Redis writes the session in `StartSession::terminate()`, *after* the
response is sent, so `routes/e2e.php` needed an explicit `session()->save()` before a
redirect the browser would follow too fast. And `ci.yml` had to override the driver,
with a comment saying so. Two workarounds for a dependency serving no purpose.

alwaysdata offers RabbitMQ but not Redis, which forced the question. RabbitMQ is a
message broker: it cannot hold a session or a cache, and it would only replace the queue
— the part with nothing in it. A `QUEUE_CONNECTION` pointing at a broker with no
supervised worker is worse than none, because jobs then pile up in silence.

So the alignment goes **downward, onto PostgreSQL**, which is already mandatory and whose
`sessions`, `cache`, `cache_locks` and `jobs` tables were already migrated.

| | `SESSION_DRIVER` | `CACHE_STORE` | `QUEUE_CONNECTION` |
|---|---|---|---|
| dev, CI E2E, preprod, prod | `database` | `database` | `sync` |
| PHPUnit (`phpunit.xml`) | `array` | `array` | `sync` |

**The PHPUnit line is not drift.** In-memory doubles inside a single process are what a
unit harness is supposed to use, and they have no behaviour a real driver lacks. The
divergence that cost this project was Redis's *asynchronous write*, which only exists in
one implementation. The rule is therefore: **the same driver everywhere a browser or a
deployment is involved; in-memory doubles in the test harness, on purpose.**

Re-enabling Redis later means restoring `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PASSWORD`,
`REDIS_PORT`, `REDIS_DB` and `REDIS_CACHE_DB`; the connection definitions were left
untouched in `config/database.php`, `config/session.php`, `config/cache.php` and
`config/queue.php`. Nothing was deleted — it simply stopped being designated.

---

## The preprod `.env`, ready to fill

Create it at `/home/cardascia-it/preprod/.env` — **not** inside `preprod/public`. Every
`<…>` is a placeholder: fill it from the source named in [the tables](#the-environment-file-variable-by-variable), never from
another environment's file.

```dotenv
APP_NAME="cardascia-it"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://preprod.cardascia-it.org

APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
APP_FAKER_LOCALE=fr_FR

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=daily
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=postgresql-cardascia-it.alwaysdata.net
DB_PORT=5432
DB_DATABASE=cardascia-it_preprod
DB_USERNAME=cardascia-it_preprod
DB_PASSWORD=<from the alwaysdata panel>

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=preprod.cardascia-it.org
SESSION_SECURE_COOKIE=true

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync

CACHE_STORE=database

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="contact@cardascia-it.org"
MAIL_FROM_NAME="${APP_NAME}"

VITE_APP_NAME="${APP_NAME}"

# Geo API (server-side proxy for the boot sequence)
GEO_API_BASE_URL=http://ip-api.com/json
GEO_FETCH_TIMEOUT=3
GEO_CACHE_TTL=86400
GEO_FAILURE_CACHE_TTL=60

# Admin account — used by AdminSeeder (php artisan db:seed)
ADMIN_EMAIL=<the admin address>
ADMIN_NAME="Laurent Bernard-Cardascia"
ADMIN_PASSWORD=<from the password manager — change it after first login>
```

Then, once and only once:

```bash
php artisan key:generate
chmod 600 .env
```

**Differences from `.env.example`, and why each one is deliberate:**

| | Dev | Preprod |
|---|---|---|
| `APP_ENV` | `local` | `production` — anything else publishes `/e2e/admin-auth` |
| `APP_DEBUG` | `true` | `false` |
| `LOG_LEVEL` | `debug` | `warning` |
| `DB_PORT` | `5433` | `5432` — the local cluster runs on a non-default port, alwaysdata does not |
| `DB_USERNAME` | the account user | a user dedicated to this environment alone |

And four blocks are **absent on purpose**: `ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL` (no
translation command runs on a server), `GEO_DEV_FALLBACK_IP` (there are no loopback
visitors), `OCTANE_*` and `PHP_INI_SCAN_DIR` (both describe the local FrankenPHP setup).

---

## Server configuration (alwaysdata)

| Setting | Value |
|---|---|
| Site | `preprod.cardascia-it.org` |
| Root directory | `preprod/public` — **not** `preprod`, and not the default `www/` |
| PHP | 8.5 |
| Force HTTPS | on |
| WAF | basic |
| HTTP cache | disabled — Laravel sets its own cache headers |
| TLS certificate | Let's Encrypt, issued automatically |

### Preprod is behind HTTP Basic authentication

Preprod is on the public internet under a name anyone can resolve, and it will run the
same admin login as production — with, until LUMN-36 ships, no limit on password or TOTP
attempts. A `401` in front of everything closes that, and closes indexing with it: a
crawler never reaches a page.

The password file lives at **`/home/cardascia-it/.htpasswd`**, at the account root rather
than inside `preprod/`, for two reasons: it is outside the document root either way, and
`preprod/` has to stay clonable — `git clone` refuses a non-empty directory.

```bash
cd ~
printf 'laurent:' > .htpasswd
openssl passwd -apr1 >> .htpasswd    # prompts; never echoes, never hits shell history
chmod 600 .htpasswd
```

The rules go in the site's **Apache directives** field in the alwaysdata panel — never in
`public/.htaccess`, which is versioned and would carry them into production:

```apache
<Location "/">
    AuthType Basic
    AuthName "Preproduction"
    AuthUserFile /home/cardascia-it/.htpasswd
    Require valid-user
</Location>

<Location "/.well-known">
    Require all granted
</Location>
```

**Two details that are the whole point:**

- **`<Location>`, not `<Directory>`.** A `<Directory>` block names a filesystem path, so it
  matches nothing until code is deployed — meaning the protection could not be verified
  until after the site was already exposed. `<Location>` matches a URL and applies to an
  empty site, so the `401` was confirmed *before* the first deployment.
- **The `.well-known` exemption is not optional.** Let's Encrypt renews by fetching a file
  under `/.well-known/acme-challenge/`. Behind Basic auth it gets a `401`, renewal fails
  silently, and the certificate expires roughly 90 days later with nothing having reported
  a problem. In Apache 2.4 a `<Location>` overrides another `<Location>`, so the exemption
  wins.

Verified 2026-09-14, before any code was deployed:

| Request | Expected | Got |
|---|---|---|
| `https://…/` | `401` | `401`, realm `Preproduction` |
| `https://…/.well-known/acme-challenge/x` | not `401` | `404` — auth skipped, file looked for |
| `https://…/` with wrong credentials | `401` | `401` |
| `http://…/` | `301` before any challenge | `301` → `https`, then `401` |

That last row failed on the first attempt: *Force HTTPS* was still unticked, so the server
asked for a password over cleartext HTTP. Basic authentication transmits credentials as
base64 — encoding, not encryption. **A password prompt on plain HTTP leaks the password**,
and the protection would have been worth nothing. The redirect has to come first.

To remove the protection at production cutover, delete the two `<Location>` blocks from the
panel. The `.htpasswd` file can stay; nothing reads it once no directive names it.

**The root directory is the one setting that must not be wrong.** Pointed at `preprod`
instead of `preprod/public`, Apache would serve the application root — which contains
`.env`, `composer.json`, `storage/` and `vendor/`. The panel's default is `www/`, which
is wrong in a different way: it 404s, loudly, which is the harmless failure.

"Certificats auto-générés" in the alwaysdata panel means *automatically issued*
(Let's Encrypt), not *self-signed*. Issuance requires the domain to already resolve to
alwaysdata over plain HTTP, so the certificate cannot exist before DNS points there —
which reads as a circular dependency and is not one: point the DNS, wait, then tick
*Force HTTPS*, in that order.

---

## The account environment — set this before anything else

**This section is why [the deployment sequence](#deploying) is short.** The commands are bare
(`php artisan …`, `npm run build`) and that only works because the account's default
interpreters are correct. They are not, out of the box.

Measured on a fresh account, 2026-09-14:

```
php   → 7.4.33     composer.json requires ^8.5
node  → v6.17.1    Vite 8 will not start
php -m → almost nothing; no php.ini loaded at all
```

The fix is not a wrapper script or absolute paths. It is **Admin → Environment**, at the
*account* level:

| Setting | Value | Why |
|---|---|---|
| PHP | **8.5** | Pick the *major*, not `8.5.10` — the panel then tracks the latest minor, so security fixes land without action |
| Node.js | **24** | Same. `package.json` declares `"node": ">=24"` |
| Python, Ruby, Elixir, Java, Deno, .NET | **leave alone** | Nothing in this project executes them. Changing a runtime nothing uses is risk without benefit |
| Custom `php.ini` | **leave empty** (besides what the panel put there) | See below |

**The custom `php.ini` field is a trap worth naming.** The bare binary at
`/usr/alwaysdata/php/8.5/bin/php` loads no configuration, so `php -m` on it lists almost
nothing and suggests every extension is missing. It is not: selecting PHP 8.5 in the panel
makes the `php` on the `PATH` load `~/admin/config/php/php.ini`, which already provides
`pdo`, `pdo_pgsql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`,
`bcmath`, `curl` and `intl` — everything Laravel needs.

Adding them by hand produces `Warning: Module "X" is already loaded` **on stdout, before
anything else**, which truncates the output of every command that reads `php`'s — Composer
first among them. Diagnose the `php` on the `PATH`, never the versioned binary.

The account's SSH shell is **fish**, not bash. Loops and `$(…)` in a deployment snippet
need to be written accordingly, or run through `bash -c`.

---

## Cloning: the repository is private

The server authenticates to GitHub with a **deploy key** — a key pair generated on the
server, whose public half is registered on the repository as read-only.

```bash
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_github -N "" -C "<account>@alwaysdata deploy key"
# register ~/.ssh/id_ed25519_github.pub on the repository, read-only
printf '\nHost github.com\n    IdentityFile ~/.ssh/id_ed25519_github\n    IdentitiesOnly yes\n' >> ~/.ssh/config
git clone git@github.com:<owner>/<repo>.git ~/preprod
```

Read-only, scoped to one repository, revocable from its settings, and it never touches a
personal GitHub credential. `ssh -T git@github.com` answers `Hi <owner>/<repo>!` rather
than a username — that reply *is* the proof the key is repository-scoped.

The passphrase is empty on purpose: a deployment cannot type one. The exposure is bounded
by the scope — anyone able to read `~/.ssh` on this server already has the working copy and
the `.env`.

**Clone into `~/preprod`, not into an existing directory.** `git clone` refuses a non-empty
target, which is why `.htpasswd` lives at the account root rather than beside the code.

Note on SSH access to alwaysdata itself: the panel has **no field for SSH keys** outside
Cloud Privé offerings. The key goes into `~/.ssh/authorized_keys` via `ssh-copy-id`, which
needs password authentication enabled — so **do not disable it until key authentication is
proven**, or the door closes with the key still inside.

---

## Deploying

```bash
# On the server, in /home/cardascia-it/preprod
git pull
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan db:seed --class=AdminSeeder          # first deployment only
php artisan db:seed --class=HomepageContentSeeder
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`npm ci` and `npm run build` run **on the server**: `/public/build` is gitignored, so the
assets exist nowhere else. Measured: 6 seconds, on a host with 32 GB of memory and 2 TB
free — the "will the build fit" question has an answer, and it is yes.

`HomepageContentSeeder` is safe to run on every deployment: its `confirm()` defaults to
`false`, so a non-interactive run populates an empty row and otherwise changes nothing.
Editing live content is the admin form's job.

**`config:cache` freezes `.env`.** After it runs, `env()` outside a config file returns
`null`. Change a variable, and nothing takes effect until `config:cache` runs again.

### Deploying a candidate, before it reaches `main`

The sequence above deploys what is already released. A change that can only be *validated*
on a server — anything about proxies, TLS, paths, or interpreter versions — has to be
deployed before it is merged, or the pull request waits on a measurement the merge is a
precondition for.

```bash
# 1. deploy the candidate
git fetch origin && git checkout <branch>
composer install --no-dev --optimize-autoloader && npm ci && npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache

# 2. measure. Anything found goes back onto the same branch — the squash merge
#    still produces one commit, so the ticket keeps its single commit on main.

# 3. once merged, bring the server back
git checkout main && git pull
# …then the normal sequence, and verify again on what is actually released.
```

**Step 3 is not optional.** A server left on a merged branch quietly stops receiving
anything: the next `git pull` updates a branch nobody pushes to any more.

This is safe here because preprod sits behind HTTP Basic authentication: a candidate
carrying a known defect is unreachable while it is being measured.

---

## Traps this file exists because of

Four defects, all found on 2026-09-14 before the first deployment, none of which can
occur on a development machine.

### `public/.htaccess` was missing

Development runs on FrankenPHP, which routes every request to `public/index.php` itself
and never reads that file. Apache does not. Without it, `/` resolves and **every other
route 404s** — a failure that looks like broken routing rather than a missing file.

### `trustProxies` was configured, and had to be removed

**This entry is the one that was wrong.** It was written on 2026-09-14 alongside the
change it describes, on the assumption that the host terminated TLS upstream and handed
PHP a plain HTTP request. The first deployment measured the host, and the assumption did
not survive it.

What the probe returned, from a real browser-side request:

```
HTTPS                   = on
REMOTE_ADDR             = <the visitor's own public IP>
HTTP_X_FORWARDED_PROTO  = https
```

And with the caller deliberately sending forged headers:

| Header sent by the caller | What PHP receives | |
|---|---|---|
| `X-Forwarded-Proto: http` | `https` | the proxy overwrites it — safe |
| — | `REMOTE_ADDR` = the real visitor | `mod_remoteip` resolves it — safe |
| `X-Forwarded-For: 1.2.3.4` | **`1.2.3.4`** | passed through verbatim |
| `X-Forwarded-Host: evil.example` | **`evil.example`** | passed through verbatim |

**Apache already does the work.** `HTTPS=on` is set by the host, so Laravel knows the
request is secure without trusting anyone — which also means the correct `canonical` seen
on the first deployment proved nothing about `trustProxies`. It was right *without* it.

**And there is no proxy address left to trust.** `mod_remoteip` runs upstream and has
already rewritten `REMOTE_ADDR` to the visitor. From PHP's side the connecting peer *is*
the visitor, so `trustProxies(at: '*')` designates the visitor as a trusted proxy and hands
them two things:

- `$request->ip()` — whatever they put in `X-Forwarded-For`. Any rate limiter keyed on the
  IP is then bypassed by changing a header (see LUMN-36, which keys the 2FA limiter on the
  user id for exactly this reason).
- `$request->getHost()` — whatever they put in `X-Forwarded-Host`. **This is host header
  poisoning**, and it is the serious one: every absolute URL the application builds —
  `canonical`, `hreflang`, redirects, a future password reset link — would carry a host the
  attacker chose.

There is no value of `at:` that is correct here. The middleware is gone, not narrowed.

`tests/Feature/Deployment/TrustedProxyTest.php` now asserts the measured behaviour: forged
`X-Forwarded-*` headers change nothing, and a request the server marks secure is still
detected as secure. That last one is the counterpart — without it, "ignore every forwarded
header" would be satisfied by an application that never detects HTTPS at all.

**Before enabling `trustProxies` on any other host, run the probe.** Two sessions of
reasoning produced the wrong answer; one HTTP request produced the right one.

```php
<?php // public/_probe.php — delete immediately afterwards
header('Content-Type: text/plain');
foreach ($_SERVER as $k => $v) {
    if ($k === 'REMOTE_ADDR' || $k === 'HTTPS' || str_starts_with($k, 'HTTP_X_')) {
        echo "$k = $v\n";
    }
}
```

Send it twice: once plainly, once with `-H "X-Forwarded-For: 1.2.3.4" -H "X-Forwarded-Host:
evil.example"`. The second request is the one that answers the question.

### Telescope made `--no-dev` fatal

`laravel/telescope` is a `require-dev` package, but `App\Providers\TelescopeServiceProvider`
— which extends a class from it — was listed unconditionally in `bootstrap/providers.php`.
A `composer install --no-dev` deployment therefore died at boot with
`Class "Laravel\Telescope\TelescopeApplicationServiceProvider" not found`, before a single
route was matched. It is now registered from `AppServiceProvider::register()`, guarded by
both `class_exists()` and an environment check.

### The E2E backdoor was on a denylist

`routes/e2e.php` was loaded whenever the environment was **not** `production`. Every
environment name nobody had thought of — `preprod` first among them — published
`GET /e2e/admin-auth` on the public internet. It is now an allowlist of `local` and
`testing`, the same reasoning `config/seo.php` already applies to `public_routes`: a
forgotten denylist entry exposes something, a forgotten allowlist entry hides something.

---

## What the first deployment established

Recorded so the next environment does not re-derive it. Everything below was measured on
`preprod.cardascia-it.org`, 2026-09-14, not assumed.

| | |
|---|---|
| PHP on the `PATH`, once the panel is set | 8.5.10, with all of Laravel's extensions |
| Node / npm | 24.20.0 / 11.19.0 |
| `composer install --no-dev` | succeeds — **and discovers packages without Telescope**, which is precisely where the unguarded provider used to be fatal |
| `npm run build` | 6 s; host has 32 GB RAM, 2 TB free |
| 13 migrations on a `C.UTF-8` database | all applied |
| `/fr`, `/fr/cv` | `200` |
| `canonical` | `https://preprod.cardascia-it.org/fr` |
| **`/e2e/admin-auth`** | **`404`** — the allowlist guard, confronted with the machine it protects |
| `ANTHROPIC_API_KEY` | absent from the running configuration |
| `REMOTE_ADDR` | the visitor's own address; `mod_remoteip` runs upstream |
| `HTTPS` | `on`, set by the host |
| Forged `X-Forwarded-For` / `-Host` | reach PHP verbatim, and Laravel ignores them |

## Not done yet

- **No deployment pipeline.** Everything above is manual, and that is now a specification
  rather than a guess: each corrected line of this file is a line the pipeline will carry.
- **No rate limiting anywhere.** `grep -rn throttle routes/ app/Http/` returns nothing, and
  `LoginRequest` does not call `ensureIsNotRateLimited()`. `/{lang}/login` accepts unlimited
  password attempts, and the six-digit TOTP challenge behind it accepts unlimited codes —
  which is the more serious of the two, since a TOTP's entire security rests on a small
  number of attempts. HTTP Basic authentication closes both on preprod. **This must be
  closed before `cardascia-it.org` points here.** (LUMN-36)
- **No security headers**, and `robots.txt` allows everything — preprod would be indexed if
  it were reachable. (LUMN-37)
- **Nameserver delegation to alwaysdata** is not done; `cardascia-it.org` still resolves
  through one.com and still serves the previous site, untouched. It depends on settling the
  `contact@cardascia-it.org` mailbox first.
- **No backup of the preprod database.**
- **Nothing distinguishes preprod from production in the logs**, since both run
  `APP_ENV=production` deliberately. A dedicated variable is needed. (LUMN-40)
