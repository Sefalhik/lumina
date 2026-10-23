# Deployment — cardascia-it.org

Reference for running this application somewhere other than a developer's machine.
Hosting is **alwaysdata**; the site is served by **Apache, with PHP over FastCGI** (`cgi-fcgi`, measured on 2026-10-11 — not PHP-FPM, as this file said until then), behind a
TLS-terminating front proxy.

**This file contains no secret values and never will.** It documents where each
value *comes from*, so that a new environment can be rebuilt without hunting.

---

## Environments

| | Address | Served by | Purpose |
|---|---|---|---|
| dev | `dev.cardascia-it.org` | FrankenPHP / Octane, local | Development |
| **preprod** | `preprod.cardascia-it.org` | alwaysdata, Apache | The new site, until it is validated |
| prod (current) | `cardascia-it.org` | one.com answers `308` to `cardascia-it.alwaysdata.net`, the hosting account's default address, where the previous site is served | Stays online and untouched |
| prod (target) | `cardascia-it.org` | alwaysdata, Apache | Not yet — see [Not done yet](#not-done-yet) |

The old site stays reachable while the new one is built at a temporary address. No
cutover happens until the editorial content is ready.

Measured on 2026-10-10: `cardascia-it.org` does not *serve* the old site, it redirects to it, and
a deep link loses its path on the way — `/fr/cv` lands on the root of the other address. This table
said "one.com, previous technology" until then. It is the starting point of LUMN-35, not something
to repair on the old site.

---

## Where a secret lives

Three homes, one per audience. **None of them is the repository.**

| Audience | Home |
|---|---|
| A human | A password manager. Not a file in `Documents`, not a note, not a chat message |
| The running application | `/home/cardascia-it/lumina/preprod/shared/.env` on the server |
| The pipeline | GitHub **environment** secrets — `preprod` today, `production` with LUMN-54 — readable only by a job that names the environment. See [The pipeline's access to the server](#the-pipelines-access-to-the-server) |

The three `JIRA_*` secrets stay at repository level: `jira-sync.yml` runs on pull-request events,
outside any environment, and they open nothing on a server.

The server-side `.env` sits **outside the document root** by construction. It lives in
`shared/`, outside every release, and reaches a release through a link at its root; Apache is
pointed at `current/public`, the `public/` directory of the release in service, so the link is
one level above anything the web server will serve. That is not a convenience — it is the whole
reason Laravel puts `index.php` in a subdirectory. See [How a site is laid out](#how-a-site-is-laid-out).

`.env` is listed in `.gitignore`. `.env.example` is versioned and holds **names only**,
never values. Between them: `.env.example` answers *which* variables exist,
this file answers *where each value comes from*.

---

## The environment file

Which variables exist, what each one means and where its value comes from:
**[Environment variables](environment-variables.md)** — including
[the ones that must never exist on a server](environment-variables.md#deliberately-absent-from-preprod-and-production).

They used to live here. They moved out on 2026-09-20, because the grouping is by *origin* and half
of it has nothing to do with deploying: `SMOKE_*` belongs to a command that runs from outside, and
documenting it inside a file called Deployment is what made someone reasonably ask whether it
belonged on the server.

What stays here is what a deployment does with that file:
[the preprod `.env` ready to fill](#the-preprod-env-ready-to-fill) and
[One driver everywhere](#one-driver-everywhere).

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

Create it at `/home/cardascia-it/lumina/preprod/shared/.env` — **not** inside a release. Every
`<…>` is a placeholder: fill it from the source named in [the tables](environment-variables.md), never from
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
| Root directory | `lumina/preprod/current/public` — the `public/` of the release in service. **Not** `current`, and not the default `www/` |
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

**This table no longer describes the current state — and the reason is the interesting part.**
Measured 2026-09-20, with the panel directives unchanged and correct:

| Request, no credentials | Then | Now |
|---|---|---|
| `/.well-known/acme-challenge/<a file that exists>` | `404` (nothing existed) | **`200`** |
| `/.well-known/acme-challenge/<a file that does not>` | `404` | **`401`** |

Read the emphasis in the line below: **before any code was deployed**. With no application
there was no `public/.htaccess`, so Apache looked for the file and answered `404`. Now the
front controller rewrites anything that is neither a file nor a directory to `index.php` —
an *internal redirect*, which Apache re-evaluates against the new URI. `/index.php` does not
match `<Location "/.well-known">`, so `<Location "/">` applies and Basic auth answers `401`.

The exemption itself works, which the first row proves: a challenge file that exists is
served without credentials, and that is what an ACME client fetches. The fix belongs to this
repository, not to the panel — see LUMN-61.

**The lesson is not that the check drifted; it is that it was verified in conditions that
stopped existing the same day**, and nothing replayed it for six days. `deploy:smoke` did
replay it, saw the anomaly, and attributed it to the wrong cause — which is its own lesson,
recorded in LUMN-61.

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

**The root directory is the one setting that must not be wrong.** Pointed at `current`
instead of `current/public`, Apache would serve the application root — which contains
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

## Cloning: the server uses a deploy key

**The repository is public** — verified 2026-09-17, `gh repo view` answers `PUBLIC`. This
section said the opposite until 2026-09-20, and justified the deploy key by the repository
being private. The justification was wrong; the key is not.

The server authenticates to GitHub with a **deploy key** — a key pair generated on the
server, whose public half is registered on the repository as read-only. It stays worth
having on a public repository: it is scoped to one repository, revocable on its own, and
carries no personal identity — so a compromised server exposes a key, not an account. It is
also what a private fork or a future private repository would need anyway.

A public repository has its own consequence, and it belongs to the pipeline rather than
here: no deployment workflow may trigger on `pull_request_target`, or on any event an
outside contribution can fire (LUMN-50).

```bash
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_github -N "" -C "<account>@alwaysdata deploy key"
# register ~/.ssh/id_ed25519_github.pub on the repository, read-only
printf '\nHost github.com\n    IdentityFile ~/.ssh/id_ed25519_github\n    IdentitiesOnly yes\n' >> ~/.ssh/config
git clone --bare git@github.com:<owner>/<repo>.git ~/lumina/preprod/repo
```

Read-only, scoped to one repository, revocable from its settings, and it never touches a
personal GitHub credential. `ssh -T git@github.com` answers `Hi <owner>/<repo>!` rather
than a username — that reply *is* the proof the key is repository-scoped.

The passphrase is empty on purpose: a deployment cannot type one. The exposure is bounded
by the scope — anyone able to read `~/.ssh` on this server already has the working copy and
the `.env`.

**The clone is bare**: nothing is ever checked out in it. A release is extracted from it into a
directory of its own — see [How a site is laid out](#how-a-site-is-laid-out). `.htpasswd` lives at
the account root, beside no code at all.

Note on SSH access to alwaysdata itself: the panel has **no field for SSH keys** outside
Cloud Privé offerings. The key goes into `~/.ssh/authorized_keys` via `ssh-copy-id`, which
needs password authentication enabled — so **do not disable it until key authentication is
proven**, or the door closes with the key still inside.

---

## Deploying

### How a site is laid out

Until LUMN-51 the server was one checkout, modified in place: for a few seconds a deployment
served new code with the previous assets, and nothing was kept to go back to. A release is now
built in a directory nobody is being served from, and put in service by changing one link.

```
/home/cardascia-it/lumina/preprod/
├── repo/                          the repository, bare: what the server knows of git
├── releases/
│   ├── 20261011-050129-794dbbf…/  one directory per release: <UTC moment>-<commit>
│   └── …
├── shared/
│   ├── .env                       linked into every release
│   ├── storage/                   linked into every release
│   ├── deploy.lock                held while a deployment or a rollback runs
│   └── last-deployment            what a rollback undoes
└── current -> releases/…          the release in service — the only thing a switch changes
```

Four scripts do the work. Each is run by `bash`, by name: the account's shell is fish, where a
loop or a `[ ]` test does not mean what it means in a script.

| Script | What it does | Who runs it |
|---|---|---|
| `scripts/deploy-gate.sh` | decides whether a request made with the pipeline's key is honoured | sshd |
| `scripts/release.sh <commit>` | extracts that commit into a new release directory | the gate, or a human |
| `scripts/deploy.sh` | builds the release it sits in, puts it in service, lets the oldest go | `release.sh` |
| `scripts/rollback.sh [<release>]` | puts back in service a release that was served | the gate, or a human |

They are reached through `current`, so they are always those of the release in service — with one
exception that matters: `release.sh` ends by running the `scripts/deploy.sh` **of the commit it has
just extracted**. How a release is built is written in that release.

### Releasing a commit

The pipeline asks through the gate — see
[The pipeline's access to the server](#the-pipelines-access-to-the-server). By hand:

```bash
# On the server
cd ~/lumina/preprod
git -C repo fetch origin main
bash current/scripts/release.sh FETCH_HEAD
php artisan db:seed --class=AdminSeeder          # first deployment of an environment only, from current/
```

`release.sh` fetches nothing and judges nothing: it releases a commit the repository already holds.
Deciding which commits may be deployed is the gate's work when the pipeline asks, and yours when
you type this.

`scripts/deploy.sh` runs, in this order:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan migrate --force --no-interaction
php artisan db:seed --class=HomepageContentSeeder --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`scripts/__tests__/deploy.test.js` fails the day that listing and the script stop agreeing. Around
it, the script does four things the listing does not show:

- **Before the first command**, it links `shared/.env` and `shared/storage` into the release.
- **After the build**, it adds to its compiled files those the release in service had built. Their
  names carry a hash and never collide. The pages the previous release is still sending, and the
  ones already open in a browser, name those files: they have to answer after the switch too.
- **After the last command**, it changes `current` in one rename. A request finds the previous
  release or the new one, never half of each — and the previous one stays on disk, untouched.
  The switch takes effect at the next request because `public/index.php` asks for it: see
  [OPcache does not follow the link](#opcache-does-not-follow-the-link).
- **Then** it records the deployment in `shared/last-deployment`, and removes old releases: five
  served releases are kept, the one in service included.

What follows from running in a directory of its own:

- **A step that fails removes the release it was building**, and the site goes on exactly as it
  was: same link, same files, same `X-Release`. So does a deployment that is interrupted — a
  connection that drops, a workflow that is cancelled. One that is killed outright tidies nothing,
  and nothing has to be: what it was building was never served, a rollback refuses it, and the next
  deployment removes it.
- **The release in service is never the one removed.** Whether a release was put in service is
  asked of the link, not remembered by the script: interrupted the instant after the switch, it
  leaves the new release where it is.
- **A disk that fills up changes nothing that is served.** Every write the script makes itself —
  the two links, the list and the copies of the compiled files, the marker, the new link, its
  rename — is failed in turn by the tests, as a full disk fails it. Before the switch, the site
  stays entirely on the release it was serving and the deployment fails; after it, the release is
  in service and the deployment succeeds, saying what it could not write.
- **A release keeps its commit in the repository.** `release.sh` records it under
  `refs/releases/<name>` for as long as the directory exists. A candidate is fetched without a
  branch to hold it, and git removes in time what nothing refers to: the gate could then no longer
  read the commit it serves, and would refuse everything.
- **Migrations run before the switch.** For a few seconds the release in service runs against the
  new schema, and after a rollback an older release does for good: a migration has to stay
  compatible with the release before it.
- **One deployment at a time.** The lock is held on a file descriptor, so it is released when the
  last process of the deployment ends, however it ends: there is no file to delete after a crash.
  A second deployment is refused at once — `a deployment is already running on this server`.
  A script that is told the lock is held checks that it is before it believes it.
- **`--force` on `db:seed`.** In production Laravel asks *"Are you sure you want to run this
  command?"*, and a session nobody is typing in answers no: `Command cancelled`, exit 1.
  `--no-interaction` leaves the seeder's own question on its default, which is to keep published
  content.
- **The release names itself.** `release.sh` writes the commit SHA into `RELEASE` when it extracts
  it; `config:cache` freezes it, and every response of that release carries it as `X-Release` —
  see [Smoke tests](smoke-tests.md).

Then, **from your own machine and not from the server** — it is the outside view that
matters:

```bash
SMOKE_BASIC_USER=… SMOKE_BASIC_PASSWORD=… \
  php artisan deploy:smoke --url=https://preprod.cardascia-it.org --expect-release=<the SHA deployed>
```

Nineteen probes, under a minute, read-only: it replays what the first deployment checked by
hand in `docs/smoke-tests.md`. Exit code 1 means the release must not be promoted. Until the
pipeline runs it (LUMN-53), running it by hand is the last step of a deployment, not an
optional extra.

`npm ci` and `npm run build` run **on the server**: `/public/build` is gitignored, so the
assets exist nowhere else. Measured: 6 seconds, on a host with 32 GB of memory and 2 TB
free. A release weighs about 260 MB with its `vendor/` and `node_modules/` (measured on
2026-10-11), so five of them are not a question either.

`HomepageContentSeeder` is safe to run on every deployment: its `confirm()` defaults to
`false`, so a non-interactive run populates an empty row and otherwise changes nothing.
Editing live content is the admin form's job.

**`config:cache` freezes `.env`.** After it runs, `env()` outside a config file returns
`null`. A change made to `shared/.env` takes effect at the next release or the next rollback:
both cache the configuration of the release they put in service.

### When a deployment fails

A deployment nobody watches has to say by itself what went wrong, what state it left, and where
the rest is written.

**What the scripts say.** The command that failed speaks first, in its own words. The script then
adds what that command cannot know:

```
Deployment of <sha> FAILED while running "npm run build" (exit status 1).
The site serves what it served before: <sha>, release <name>.
The release that was being built was removed: nothing is left to clean up.
```

"While …" names the step — one of the eight commands, or `preparing the release`, `keeping the
compiled files of the release in service`, `putting the release in service`. An interruption is
said as one (`exit status 143, interrupted by a signal`) and is a failure: left to itself, bash
would have ended with the status of the command it was waiting for, often 0. `release.sh` and
`rollback.sh` end the same way, and a script stopped the instant *after* a switch says that the
release is in service instead of announcing a failure that did not happen.

**What the server keeps**, in `shared/storage/logs/`, whoever asked and wherever their own log went:

| File | Written by | One line per |
|---|---|---|
| `deploy-gate.log` | the gate | request made with the pipeline's key: when, from which address, accepted or refused and why |
| `deployments.log` | `release.sh`, `deploy.sh`, `rollback.sh` | thing that happened to the site: `deployed <sha> as <release>, replacing <release>`, `failed <sha> while <step> (exit <n>)`, `rolled back to <sha> as <release>, leaving <release>` |
| `deployments/<release>.log` | `release.sh` | deployment: **everything it said**, from the name of the release to its last word. The last twenty are kept, failed ones included |

None of them is ever a reason to fail: a journal that cannot be written is said on the spot, and
the deployment stays what it was.

The third is the one to open when the log of the pipeline stops in the middle of a sentence. A
connection that drops takes that log with it; the file on the server goes on being written, by two
copies of the output — what was said, what went wrong — that ignore the signal a terminal sends
to everything it started when it hangs up. Each copy writes as it receives: where two steps meet,
the last lines a command sent to one stream can land under the heading of the next step. A
rollback says three lines and keeps no such file: its line in `deployments.log` is the whole of it.

**What the page of the run shows**, without opening a log: the request, the commit, and the
outcome — with an error annotation when it is not `done`.

| Exit status of the step | It means |
|---|---|
| `0` | done |
| `1` | refused by the gate, or failed on the server: the reason is in the log of the step |
| `75` | refused: another deployment was running |
| `129`, `130`, `141`, `143` | a script on the server was interrupted |
| `255` | nothing was asked: the connection to the server failed |

**What none of this says**: whether the site answers. That is `deploy:smoke --expect-release`,
which fails when the release expected is not the one serving — a switch that did not take effect
looks exactly like a success from the server's side.

### Rolling back

```bash
# On the server
bash ~/lumina/preprod/current/scripts/rollback.sh                    # undo the last deployment
bash ~/lumina/preprod/current/scripts/rollback.sh <release name>     # or go to a release of releases/
```

Going back is one link changing, towards a directory that was built and served before: no network,
no build, a few seconds. `rollback.sh` first runs `config:cache` in the release it goes to —
`.env` may have changed since that release was built — and then switches.

- **Without a name, it undoes the last deployment, and only that.** Once undone, or once another
  release has been put in service by hand, there is no "last deployment" left: going further back
  is a release to name, among those of `releases/`.
- **A release is named, never pointed at.** A path is refused, and so is a directory that was
  never served.
- **The database does not go back.** Migrations only go forward; the script says so every time.
- **Nothing is removed.** The release that was left can be put back the same way, by name.
- **When the link itself leads nowhere** — the directory of the release in service was removed by
  mistake — `current/scripts/rollback.sh` is gone with it, and every page answers an error. Run
  the script of any release that is left, and name the one to put in service:
  `bash ~/lumina/preprod/releases/<a release>/scripts/rollback.sh <release name>`.

Then run `deploy:smoke --expect-release=<the SHA the script printed>`: a rollback is a switch like
any other, and is verified like one.

The pipeline can undo too, within limits: `gh workflow run deploy.yml --ref main -f request=rollback`
asks the gate to undo the deployment of that commit, and the gate only agrees for half an hour —
see [What the pipeline's key can do](#what-the-pipelines-key-can-do).

### Deploying a candidate, before it reaches `main`

The sequence above deploys what is already released. A change that can only be *validated*
on a server — anything about proxies, TLS, paths, or interpreter versions — has to be
deployed before it is merged, or the pull request waits on a measurement the merge is a
precondition for. The pipeline's key cannot do it, on purpose: this is a gesture made by hand.

```bash
# 1. release the candidate
cd ~/lumina/preprod
git -C repo fetch origin <branch>
bash current/scripts/release.sh FETCH_HEAD

# 2. measure. Anything found goes back onto the same branch — the squash merge
#    still produces one commit, so the ticket keeps its single commit on main.

# 3. once merged, let main take the site back
git -C repo fetch origin main
bash current/scripts/release.sh FETCH_HEAD
# …and verify again on what is actually released.
```

**Step 3 is not optional.** A server left on a candidate serves code that is not `main` until
something deploys over it — and until LUMN-53, nothing does so by itself. The pipeline can do
step 3 as well: the merged commit is not older than the candidate, so the gate accepts it.

This is safe here because preprod sits behind HTTP Basic authentication: a candidate
carrying a known defect is unreachable while it is being measured.

### Measuring a switch

```bash
SMOKE_BASIC_USER=… SMOKE_BASIC_PASSWORD=… \
  bash scripts/watch-switch.sh https://preprod.cardascia-it.org/fr
```

Run from your own machine, started just before a deployment or a rollback. Over and over it asks
for the page, reads which release answered in `X-Release`, asks for every compiled file the page
names, and waits a fifth of a second — about one round a second against preprod, 300 rounds by
default. It prints a line each time the release changes or something is missing, and exits 1 if a
single answer would have shown a visitor a broken page.

Give it the SHA the switch should end on as a third argument, and it also fails when that release
never answered:

```bash
bash scripts/watch-switch.sh https://preprod.cardascia-it.org/fr 300 <the SHA deployed>
```

That second question is not a refinement. The first time this script watched a real switch, it
answered "no broken page" in all honesty while the deployment had changed nothing a visitor could
see: [OPcache does not follow the link](#opcache-does-not-follow-the-link).

### Setting up a site

Once per environment, by hand. The scripts need a repository, the two things every release
shares, and a first release to be reached through.

```bash
# On the server
mkdir -p ~/lumina/preprod/releases ~/lumina/preprod/shared
git clone --bare git@github.com:Sefalhik/lumina.git ~/lumina/preprod/repo

# shared/.env: filled as described in "The preprod .env, ready to fill"
# shared/storage: the directories Laravel expects, as the repository ships them
git -C ~/lumina/preprod/repo archive main storage | tar -x -C ~/lumina/preprod/shared

# The first release: the scripts of the commit are extracted, and they do the rest.
mkdir ~/lumina/preprod/releases/bootstrap
git -C ~/lumina/preprod/repo archive main | tar -x -C ~/lumina/preprod/releases/bootstrap
bash ~/lumina/preprod/releases/bootstrap/scripts/release.sh main
```

`bootstrap` is not a release: it was never served, and the first deployment removes it with
everything else that was not. Then point the site's root directory at
`lumina/preprod/current/public` in the alwaysdata panel, run the smoke test, and only then tie
the pipeline's key to `current/scripts/deploy-gate.sh` —
[Setting it up](#setting-it-up).

**Moving an environment that was a single checkout.** Preprod was one, in `~/preprod`, served from
`preprod/public`. Build the new site beside it and leave the old one untouched until the new one
is verified: if anything goes wrong, putting `preprod/public` back in the panel is the whole way
back. Two differences from a new site: `shared/.env` is a copy of `~/preprod/.env`
(`cp -p`), and `shared/storage` a copy of `~/preprod/storage` (`cp -a`), which carries the gate's
journal over. The line of the pipeline's key in `~/.ssh/authorized_keys` names the old path: it
is replaced last, once the new site answers, and the eight probes are run again.

---

## The pipeline's access to the server

Until LUMN-50 the link ran one way: the server fetches from GitHub with a read-only deploy key.
A pipeline needs the other direction — GitHub Actions opening an SSH session on the hosting
account — and that is the one credential in this project that reaches a machine holding the
`.env`. `.github/workflows/deploy.yml` uses it; LUMN-53 will make it run on every merge.

### What the pipeline's key can do

Left as it is generated, an SSH key opens a full shell on the account: whoever obtains it reads the
`.env`, so the database password and `APP_KEY`. Storing it in the right place is not enough. What
it *allows* is bounded on both sides.

**On the server**, by its line in `~/.ssh/authorized_keys`:

```
command="/usr/bin/env bash /home/cardascia-it/lumina/preprod/current/scripts/deploy-gate.sh",restrict ssh-ed25519 AAAA… github-actions deploy, preprod
```

- `restrict` refuses a terminal, port forwarding and agent forwarding.
- `command="…"` is a **forced command**: sshd runs `scripts/deploy-gate.sh` whatever the caller
  asked for, and hands the request over in `SSH_ORIGINAL_COMMAND`. It is named through `current`:
  the gate that answers is always the one of the release in service. It honours three requests —
  `php-version`, `deploy <sha>` and `rollback <sha>` — and refuses everything else, without ever
  repeating it. It decides and never builds: `release.sh` and `rollback.sh` do.
- `<sha>` has to be the full SHA of **a commit of `main`**. The shape alone would not do: the
  repository is public, and GitHub serves the commit of any fork by its SHA from this repository's
  address. The gate fetches `refs/heads/main` and nothing else, then asks git whether the commit
  is one of its ancestors.
- The commit must **not be older than the one being served**. A commit of `main` is not harmless
  because it was once reviewed: the one from before a fix is the flaw itself, and a stolen key
  would bring it back. Going back is a decision a human makes, by hand. Deploying again the commit
  being served is not going back, and stays possible — a deployment that failed halfway can be
  replayed.
- The commit also has to **carry the four deployment scripts**, each as a regular file — a
  directory or a link of that name would be found and could not be run. A commit that lacks one
  would become the release in service with nothing to answer the key any more: no gate, or a gate
  with nothing to hand the work to. Deploying such a commit is a human's decision. Whether a
  script *works* is not the gate's to judge: that is what the required checks of `main` are for.
- **What git cannot tell is refused.** "Is this commit older than the one being served?" has three
  answers: yes, no, and an error, when the repository has lost an object. The error is a refusal,
  never a "no". This rests on git 2.45 or newer, which reports such a loss; older versions answer
  "no" and say nothing. The server runs 2.45.1 (measured on 2026-10-11).
- All of this is measured against the commit being served, read in `current/RELEASE`. A server
  that cannot name it — no file, something that is not a SHA, a commit its repository does not
  hold — is **refused everything**: "not older than nothing" would let any commit of `main` through.
- **`rollback <sha>` undoes one deployment, the last, for half an hour.** `<sha>` has to be the
  commit being served, put there by a deployment less than thirty minutes ago, and the release it
  replaced has to be still on disk. Asked later, twice, or of anything but the last deployment, it
  would be the way back to an older commit that `deploy` refuses — and it is refused the same. The
  gate never names a release to `rollback.sh`: which one to go back to is recorded by the
  deployment itself. Going back asks nothing of the repository: the day a deployment went wrong
  because the repository did, the way back does not depend on it.
- **One request at a time.** The gate takes the deployment lock last, once it has decided, and
  hands it to the script it runs. A request made while a deployment is running is refused as
  `busy` — unless it would have been refused anyway, in which case it is told why.
- The shape of the SHA is checked against a list of characters, not a range: in a UTF-8 locale
  bash reads `a-f` by collation, `[0-9a-f]` then matches `é` and the digits of other scripts, and
  the locale is the one thing an SSH client is commonly allowed to send.
- One `ed25519` pair per environment. Revoking preprod's does not touch production's, nor any
  human access.

A stolen key can therefore move the server forward along `main`, or take back the step it has
just made, and do nothing else. The gate keeps one line per request in
`shared/storage/logs/deploy-gate.log`: when, from which address, what was decided.

**In GitHub**, by where the key lives and by what the workflow is allowed to be:

| | |
|---|---|
| The key | secret `DEPLOY_SSH_KEY` of the **environment** `preprod` — a job reads it only if it names the environment, and only `main` may deploy to it |
| The server's identity | variable `DEPLOY_SSH_KNOWN_HOSTS`: the host key, **pinned**. The connection uses `StrictHostKeyChecking=yes`; the workflow never runs `ssh-keyscan` and never accepts a key it meets |
| Where to connect | variables `DEPLOY_SSH_HOST` and `DEPLOY_SSH_USER` — not secrets, both are in this file |
| Third-party code | **none**. The workflow uses no action, not even one of GitHub's: `ssh` is already on the runner |
| What a caller controls | one input, a closed list of three words, read through an environment variable. The SHA is `github.sha` |
| Token | `permissions: {}` |

`scripts/__tests__/deploy-workflow.test.js` holds each line of that table, and runs the step of the
workflow against a stand-in for `ssh`: the key file is `600` while it exists and gone when the step
ends, whether the server answered or not.

**The pipeline cannot deploy a candidate branch**, on purpose: the gate only knows `main`.
[Deploying a candidate](#deploying-a-candidate-before-it-reaches-main) stays a gesture made by
hand until LUMN-53 decides otherwise.

### Setting it up

**1. The gate has to be on the server before the key is tied to it.** The site has to be serving
a release that contains the four scripts — see [Setting up a site](#setting-up-a-site).

**2. Generate the pair**, on your own machine:

```bash
ssh-keygen -t ed25519 -N "" -C "github-actions deploy, preprod" -f ~/.ssh/lumina-deploy-preprod
```

**3. Pin the host key.** Fetch it once, and compare its fingerprint **by eye** with the one the
alwaysdata panel displays under *Remote access → SSH* before storing it:

```bash
ssh-keyscan -t ed25519 ssh-cardascia-it.alwaysdata.net > /tmp/lumina-known-hosts
ssh-keygen -lf /tmp/lumina-known-hosts
```

`ssh-keyscan` is acceptable here and nowhere else: its answer is checked against a source the
network cannot forge before it is trusted. Run by the workflow, it would ask an attacker who the
server is and believe the reply.

**4. Authorise the public half on the server**, with its restrictions. Through your own access,
keep a copy of `~/.ssh/authorized_keys`, then append the line shown above, the key being the
content of `~/.ssh/lumina-deploy-preprod.pub`:

```bash
ssh <your access> 'cp ~/.ssh/authorized_keys ~/.ssh/authorized_keys.before-deploy-key'
printf '\ncommand="/usr/bin/env bash /home/cardascia-it/lumina/preprod/current/scripts/deploy-gate.sh",restrict %s\n' \
  "$(cat ~/.ssh/lumina-deploy-preprod.pub)" | ssh <your access> 'cat >> ~/.ssh/authorized_keys'
```

Appended, never edited in place: your own access is another line of that file, and the copy is the
way back. The leading `\n` is for a file whose last line does not end with one — the new entry
would otherwise be glued to the previous key and neither would work.

Then measure what the key can do, from your own machine, before GitHub ever sees it:

```bash
bash scripts/check-deploy-key.sh <user>@ssh-cardascia-it.alwaysdata.net ~/.ssh/lumina-deploy-preprod /tmp/lumina-known-hosts
```

One thing has to work and seven must not: `php-version` answers; a shell, a command, a terminal,
the sftp subsystem, a file copy, a tunnel through the server and a port opened on it are refused.
The script exits 1 as soon as one restriction is missing, and never prints what a probe received.

**This is the only test of the line typed in `authorized_keys`.** The gate's own tests prove what
it does with a request; they cannot prove that sshd hands it every request. A key that answers
`php-version` and also opens a tunnel to the database has `command="…"` and no `restrict`, and
nothing but this measurement would say so. A refusal only counts when it is one: the script
wants the gate's own word, or sshd's, because an allowed tunnel also fails when nothing listens
at its far end.

Two probes never hear the gate, and are judged on what does come back. A terminal turned down
ends the session: `ssh -tt` takes the refusal for fatal, says so and leaves with 255 before the
command is sent. And sshd discards the standard error of a subsystem: the gate refusing `sftp`
comes back as its exit status, 1, without a word — where a real sftp server, its input ending at
once, leaves with 0 just as silently. Both were measured on preprod on 2026-10-10 and 11, after a
first version of the probe had waited for the gate's word on each and failed a key that was
restricted as it should be. Under a ✘ the script now says what it wanted and what it observed: an
exit status and the names of the sentences it looks for, never the answer.

**Measured on preprod on 2026-10-11**, OpenSSH 9.2 on the server and fish as the account's shell:
the eight probes answer as described, and `php-version` finds PHP 8.5.11 in a session with no
terminal.

**5. Create the environment and fill it:**

```bash
gh api -X PUT repos/Sefalhik/lumina/environments/preprod \
  -F 'deployment_branch_policy[protected_branches]=false' \
  -F 'deployment_branch_policy[custom_branch_policies]=true'
gh api -X POST repos/Sefalhik/lumina/environments/preprod/deployment-branch-policies -f name=main -f type=branch

gh secret set DEPLOY_SSH_KEY --env preprod < ~/.ssh/lumina-deploy-preprod
gh variable set DEPLOY_SSH_KNOWN_HOSTS --env preprod < /tmp/lumina-known-hosts
gh variable set DEPLOY_SSH_HOST --env preprod --body ssh-cardascia-it.alwaysdata.net
gh variable set DEPLOY_SSH_USER --env preprod --body <user>
```

`< file` rather than a paste: a key pasted through a terminal or a web form can pick up Windows
line endings, and ssh then rejects it as `invalid format`.

**6. Delete the private half from your machine**: `rm ~/.ssh/lumina-deploy-preprod`. It now exists
in one place, where nobody can read it back. Losing it costs a rotation, nothing more.

**7. Ask from GitHub:** `gh workflow run deploy.yml --ref main -f request=php-version`.

### Rotating or revoking the key

**Revoking** is one line: delete the key's entry from `~/.ssh/authorized_keys` on the server. It
takes effect at once, and no human access goes through that entry. Then remove the secret:
`gh secret delete DEPLOY_SSH_KEY --env preprod`.

**Rotating** is steps 2, 4, 5 (the secret alone) and 6 again — `check-deploy-key.sh` included,
since a new line in `authorized_keys` is a line typed again. The old line is removed once the new
key has answered `php-version` from GitHub. Rotate after any doubt, and when a machine that ever
held the private half is retired.

When alwaysdata changes a server's host key, the connection fails — which is the pinning doing its
job. Read the new fingerprint in the panel and redo step 3, then the `DEPLOY_SSH_KNOWN_HOSTS`
line of step 5.

### What this does not cover

- **No short-lived credential.** The reference practice is to store no durable secret at all —
  OIDC federation, or SSH certificates valid for minutes. Shared hosting offers neither: the key
  lives long, and it is what the key may do that is bounded.
- **No filtering by address.** GitHub-hosted runners leave from a range too wide and too changing
  for a `from=` option.
- **The GitHub account is the root of trust.** Whoever controls it approves deployments and
  replaces secrets. Its two-factor authentication is on (read through the API on 2026-10-10); the
  hosting account's has to be checked in its own panel.
- **The database.** A rollback puts the code back, never the schema: that is the twin ticket on
  reversible migrations, and until it exists a migration that an older release cannot live with
  makes that release impossible to go back to.
- **Half an hour is a judgement.** It is long enough for a smoke test to fail and ask for the
  rollback, short enough that a key stolen later finds nothing left to undo.

Measured on 2026-10-11, the day of the first deployment asked from GitHub: a session opened by the
pipeline's key finds the PHP and the Node the panel selects — `php-version` answered 8.5.11, and
`npm ci` and `npm run build` ran in the `deploy` request that followed.

---

## Traps this file exists because of

Four defects, all found on 2026-09-14 before the first deployment, none of which can
occur on a development machine. A fifth was found the same way on 2026-10-11, by the first
switch between two releases: [OPcache does not follow the link](#opcache-does-not-follow-the-link).

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

### OPcache does not follow the link

Found on 2026-10-11, the first time a release was put in service by moving `current` (LUMN-51).
The deployment ended with `Deployed d54ea16…`. Ten minutes and 450 requests later, every page was
still answered by the release it had replaced, `cecb136`.

Nothing was broken, which is what made it easy to miss. Apache followed the link at once: the new
stylesheet answered, and so did the old one, because a release keeps the compiled files of the one
it replaces. The pages simply never changed.

What a diagnostic page placed in `public/` showed, in the web process:

| | |
|---|---|
| `SCRIPT_FILENAME` | `…/current/public/…` — the link, as given |
| `__FILE__` of a new script | `…/releases/…-d54ea16…/public/…` — the link resolves to the new release |
| `opcache.validate_timestamps`, `revalidate_freq` | `1`, `2` — the cache was revalidating, 490 times |
| `opcache.revalidate_path` | `0` |
| the `index.php` in the cache | `…/releases/…-cecb136…/public/index.php` |

OPcache finds a script by the path it is handed — `…/current/public/index.php`, which never
changes — and then checks the date of the file it compiled the first time, in the release that was
replaced. That file has not moved. With `revalidate_path` off, which is the default, it never looks
at where the link leads now. This is not a delay: without something else happening, the previous
release answers until PHP restarts. Sixteen minutes were measured before the cache was reset by
hand; the next request came from the new release.

**The fix is in the application, not in the host.** `public/index.php` begins by asking
`App\Services\ReleaseInService` which release the link names — one `readlink` per request. When
it is not its own, it makes PHP forget (`clearstatcache(true)`, `opcache_reset()`) and hands the
request to the front controller of the release in service. The switch then takes effect at the
next request, for a deployment and for a rollback alike, with no setting to keep in a panel and no
secret to store.

Two alternatives were set aside. `opcache.revalidate_path=1` in the panel is a setting outside the
repository, leaves the previous release answering for up to `realpath_cache_ttl` — 120 seconds
here, more than the minute a rollback is allowed — and was not measured on this host. A reset
address called by the pipeline is one more entry to protect and one more secret.

**Those lines of `public/index.php` are not boilerplate.** A framework upgrade that replaces the
file removes them silently; `tests/Unit/FrontControllerTest.php` runs the real file in a site of
two releases and fails when a request is no longer handed over.

**A release that predates the fix cannot hand anything over.** Going from such a release to a
newer one needs the cache reset once, by restarting the site in the panel.

## What the first deployment established

Recorded so the next environment does not re-derive it. Everything below was measured on
`preprod.cardascia-it.org`, 2026-09-14, not assumed.

Most of these checks are now a command rather than a session at a terminal: `deploy:smoke`
(LUMN-49) exists because this table was produced by hand, and an automatic deployment has
nobody watching. Six of its probes come straight from the four defects below.

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

## What the first switches established

Measured on `preprod.cardascia-it.org` on 2026-10-11 (LUMN-51), not assumed. Two releases were
switched back and forth: the candidate, `c63fbcb`, and a throwaway commit, `bf44bfb`, that changes
one CSS rule — so that a page of one names a stylesheet the other never built, and a page served
with the wrong files would have shown. `watch-switch.sh` ran from a laptop the whole time, about
one round a second.

| | |
|---|---|
| A deployment, from the command to `Deployed` | 50 s, of which `npm ci` 16 s and `vite build` 4 s |
| A switch forward takes effect | at the next request: put in service at 12:42:54Z, and the round of :55 was answered by the new release. PHP was not restarted |
| A rollback | 1.4 s on the server, 1.8 s asked over SSH. Rolled back at 12:43:26Z, and the round of :27 was answered by the release before |
| Pages that would have been broken | none — 750 rounds across a deployment and its rollback, 400 across an earlier rollback |
| Compiled files of the release replaced, missing from the one in service | 0 of 4 |
| `current/.env`, `current/storage` | the same two inodes before and after both switches: links into `shared/`, nothing copied |
| A second deployment asked for while one runs | refused, exit status 75, and no directory made |
| A deployment killed outright during `composer install` — `SIGKILL` to everything it had started | lock free within a tenth of a second; the site still on the release it was serving; one directory left, never served |
| That directory | removed by the next deployment |
| Releases on disk afterwards | five, all of them served; one weighs about 261 MB |
| `deploy:smoke --expect-release` | 19 probes passed after a switch, and again after each rollback |

The killed deployment is the one case no trap can tidy up, which is why it was run for real: the
first one was started in a session of its own (`setsid`), the second asked for three seconds into
its first step, and the first then sent `kill -KILL` by process group.

**The same round trip, asked by the pipeline.** It could only be made after the merge: the
`preprod` environment only runs from `main`, and `main` did not offer `rollback` before. Three
runs of `deploy.yml` the same day, through the key once it was rotated onto the new gate:

| | |
|---|---|
| `deploy` | accepted; 66 s from the request to `Deployed`. The new release answered 8 s before that last word: what follows the switch is the removal of the oldest release |
| `rollback`, two minutes later | accepted; 4 s from the request to `Rolled back`, and the release before answered at the next round |
| `deploy` again | accepted, in 63 s — the commit of `main` is not older than the candidate that was back in service |
| Pages that would have been broken | none, in 500 rounds across the three |
| `deploy:smoke --expect-release` | 19 probes passed after each |

## Not done yet

- **No deployment on merge.** The pipeline can release a commit and undo it on request (LUMN-50,
  LUMN-51), but someone still has to ask. Deploying every merge is LUMN-53.
- **Rate limiting covers login and two-factor only.** Since LUMN-36, `/{lang}/login` and both
  two-factor forms refuse further attempts after repeated failures — see
  [Rate limiting](authentication.md#rate-limiting), and since LUMN-91 a code is accepted once —
  [A code is accepted once](authentication.md#a-code-is-accepted-once). One gap remains, closed by
  HTTP Basic authentication on preprod: `/up` has no ceiling although each call queries the
  database (LUMN-92). **It must be closed before `cardascia-it.org` points here** — it blocks
  LUMN-54.
- **No security headers**, and `robots.txt` allows everything — preprod would be indexed if
  it were reachable. (LUMN-37)
- **Nameserver delegation to alwaysdata** is not done; `cardascia-it.org` still resolves
  through one.com, which redirects to the previous site, untouched. It depends on settling the
  `contact@cardascia-it.org` mailbox first.
- **No backup of the preprod database.**
- **Nothing distinguishes preprod from production in the logs**, since both run
  `APP_ENV=production` deliberately. A dedicated variable is needed. (LUMN-40)
