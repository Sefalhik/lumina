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
| prod (target) | `cardascia-it.org` | alwaysdata, Apache | Not yet — see *Not done yet* |

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
| `SESSION_SECURE_COOKIE` | `true` | Requires `trustProxies` — see *Traps* |

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
| `DB_DATABASE` | `cardascia_it_preprod` — alwaysdata forces the `cardascia_it_` prefix |
| `DB_USERNAME` | `cardascia-it` |
| `DB_PASSWORD` | Set from the panel; it is not displayed, only replaced |

The database was created with locale **`C.UTF-8`** rather than a language-specific one.
The site serves 24 languages, so no single collation is the right one for its content,
and `C.UTF-8` is the only choice immune to the glibc collation-version breakage that
silently corrupts indexes when the host upgrades its C library. Sorting for display is
the application's job, not the database's.

### Chosen once, and the same everywhere

| Variable | Value | Why |
|---|---|---|
| `SESSION_DRIVER` | `database` | See *One driver everywhere* |
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
`<…>` is a placeholder: fill it from the source named in the tables above, never from
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
DB_HOST=<alwaysdata PostgreSQL host>
DB_PORT=5432
DB_DATABASE=cardascia_it_preprod
DB_USERNAME=cardascia-it
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

## Deploying

```bash
# On the server, in /home/cardascia-it/preprod
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=AdminSeeder          # first deployment only
php artisan db:seed --class=HomepageContentSeeder
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Built assets are **not versioned** (`/public/build` is in `.gitignore`), so
`npm run build` has to run somewhere before the site can render a page. Until the
pipeline exists, that is a manual step.

`HomepageContentSeeder` is safe to run on every deployment: its `confirm()` defaults to
`false`, so a non-interactive run populates an empty row and otherwise changes nothing.
Editing live content is the admin form's job.

**`config:cache` freezes `.env`.** After it runs, `env()` outside a config file returns
`null`. Change a variable, and nothing takes effect until `config:cache` runs again.

---

## Traps this file exists because of

Four defects, all found on 2026-09-14 before the first deployment, none of which can
occur on a development machine.

### `public/.htaccess` was missing

Development runs on FrankenPHP, which routes every request to `public/index.php` itself
and never reads that file. Apache does not. Without it, `/` resolves and **every other
route 404s** — a failure that looks like broken routing rather than a missing file.

### `trustProxies` was not configured

alwaysdata terminates TLS at a front proxy, so PHP only ever sees plain HTTP. The visible
symptom is `http://` links on an `https://` page; the expensive one is
`SESSION_SECURE_COOKIE=true` producing a cookie the browser refuses to send back — which
presents as an endless login loop, not as a configuration problem.

`at: '*'` trusts any proxy, which is the workable choice when the front end's address is
not contractually stable. The cost: `X-Forwarded-For` becomes caller-controlled, so
`$request->ip()` is **not evidence**. Its only reader today is `GeoController`, which
resolves a location for the caller's own boot sequence. Anything that gates access or
counts attempts per IP must not rely on it as it stands.

**Narrowing `at:` is not as simple as reading the panel.** alwaysdata publishes three
ranges — `185.31.40.0/22`, `188.72.70.0/24`, `2a00:b6e0::/32` — but the page frames them
as *"les plages d'adresses IP que les applications peuvent autoriser pour fonctionner"*:
ranges to allowlist **at a third party** so an alwaysdata-hosted application can reach it.
That is the outbound direction, and it says nothing about what Apache receives.

*Admin → Advanced → Server status* is no better, for a subtler reason. It lists the site's
HTTP server as `http14.paris1` at `185.31.40.24` / `2a00:b6e0:1:20:15::1`, and that is
exactly what the site resolves to:

```
preprod.cardascia-it.org → cardascia-it.alwaysdata.net → 185.31.40.24
dig -x 185.31.40.24      → http14.paris1.alwaysdata.com
```

So the proxy and Apache are the **same machine**, and that address is where clients connect
*to* — not the address Apache sees requests coming *from*, which is then almost certainly
a loopback or private address. The column is labelled "IP"; it does not say which direction.
Putting the public range into `trustProxies` would match nothing useful, and a `trustProxies`
that matches nothing also stops honouring `X-Forwarded-Proto` — which silently breaks HTTPS
detection, the very thing it was added for.

**It has to be measured, not read.** Once anything at all is deployed:

```php
<?php // public/_probe.php — delete immediately afterwards
header('Content-Type: text/plain');
foreach ($_SERVER as $k => $v) {
    if ($k === 'REMOTE_ADDR' || $k === 'HTTPS' || str_starts_with($k, 'HTTP_X_')) {
        echo "$k = $v\n";
    }
}
```

If `REMOTE_ADDR` comes back as the **visitor's own public IP**, alwaysdata applies
`mod_remoteip` upstream — their GeoIP guide blocks countries from a plain `.htaccess`, which
only works if Apache already holds the real client address. In that case `at: '*'` is wrong
in the opposite direction: Laravel would treat the visitor as a trusted proxy and read the
`X-Forwarded-For` they sent themselves. Whatever the probe returns, record it here and
narrow `at:` to it — or write down why it stayed `'*'`.

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

## Not done yet

- **No deployment pipeline.** Everything above is manual.
- **No rate limiting anywhere.** `grep -rn throttle routes/ app/Http/` returns nothing, and
  `LoginRequest` does not call `ensureIsNotRateLimited()`. `/{lang}/login` accepts unlimited
  password attempts. 2FA still stands between a correct password and the admin, but
  password guessing is currently free and unobserved. This must be closed before
  `cardascia-it.org` points here.
- **Nameserver delegation to alwaysdata** is not done; `cardascia-it.org` still resolves
  through one.com. It depends on settling the `contact@cardascia-it.org` mailbox first.
- **No backup of the preprod database.**
