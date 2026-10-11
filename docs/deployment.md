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
| The running application | `/home/cardascia-it/preprod/.env` on the server |
| The pipeline | GitHub **environment** secrets — `preprod` today, `production` with LUMN-54 — readable only by a job that names the environment. See [The pipeline's access to the server](#the-pipelines-access-to-the-server) |

The three `JIRA_*` secrets stay at repository level: `jira-sync.yml` runs on pull-request events,
outside any environment, and they open nothing on a server.

The server-side `.env` sits **outside the document root** by construction: Apache is
pointed at `preprod/public`, so `preprod/.env` is one level above anything the web
server will serve. That is not a convenience — it is the whole reason Laravel puts
`index.php` in a subdirectory.

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

Create it at `/home/cardascia-it/preprod/.env` — **not** inside `preprod/public`. Every
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

The sequence is a script, `scripts/deploy.sh`, since LUMN-50. It deploys **the commit that is
checked out** and never chooses one:

```bash
# On the server, in /home/cardascia-it/preprod
git fetch origin
git checkout --detach origin/main
bash scripts/deploy.sh
php artisan db:seed --class=AdminSeeder          # first deployment of an environment only
```

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

`scripts/__tests__/deploy.test.js` fails the day that listing and the script stop agreeing. Between
the seeder and `config:cache` the script also writes the commit SHA into `RELEASE`, which is what
`X-Release` and the first smoke probe read — see [Smoke tests](smoke-tests.md).

Four things the script does that a sequence typed by hand never had to:

- **It is run by `bash`, by name.** The account's shell is fish; a loop or a `[ ]` test typed
  there does not mean what it means in the script.
- **`--force` on `db:seed`.** In production Laravel asks *"Are you sure you want to run this
  command?"*, and a session nobody is typing in answers no: `Command cancelled`, exit 1. Typed by
  hand on 2026-09-14 the question was simply answered, so the sequence looked complete without
  the flag. `--no-interaction` leaves the seeder's own question on its default, which is to keep
  published content.
- **A detached checkout.** The server no longer follows a branch: it sits on the commit it serves,
  and `git pull` has nothing to pull into.
- **It stops at the first command that fails**, and `RELEASE` then keeps the previous SHA: the
  environment goes on announcing the release it was serving, and a smoke test expecting the new
  one fails.

Then, **from your own machine and not from the server** — it is the outside view that
matters:

```bash
SMOKE_BASIC_USER=… SMOKE_BASIC_PASSWORD=… \
  php artisan deploy:smoke --url=https://preprod.cardascia-it.org --expect-release=<the SHA deployed>
```

`--expect-release` has something to compare with since the script writes `RELEASE`. Without the
option, the probe that checks which release answered is skipped.

Nineteen probes, under a minute, read-only: it replays what the first deployment checked by
hand in `docs/smoke-tests.md`. Exit code 1 means the release must not be promoted. Until the
pipeline runs it (LUMN-53), running it by hand is the last step of a deployment, not an
optional extra.

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
git fetch origin && git checkout --detach origin/<branch>
bash scripts/deploy.sh

# 2. measure. Anything found goes back onto the same branch — the squash merge
#    still produces one commit, so the ticket keeps its single commit on main.

# 3. once merged, bring the server back
git fetch origin && git checkout --detach origin/main
bash scripts/deploy.sh
# …and verify again on what is actually released.
```

**Step 3 is not optional.** A server left on a candidate serves code that is not `main` until
something deploys over it — and until LUMN-53, nothing does so by itself.

This is safe here because preprod sits behind HTTP Basic authentication: a candidate
carrying a known defect is unreachable while it is being measured.

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
command="/usr/bin/env bash /home/cardascia-it/preprod/scripts/deploy-gate.sh",restrict ssh-ed25519 AAAA… github-actions deploy, preprod
```

- `restrict` refuses a terminal, port forwarding and agent forwarding.
- `command="…"` is a **forced command**: sshd runs `scripts/deploy-gate.sh` whatever the caller
  asked for, and hands the request over in `SSH_ORIGINAL_COMMAND`. The gate honours two requests —
  `php-version`, and `deploy <sha>` — and refuses everything else, without ever repeating it.
- `<sha>` has to be the full SHA of **a commit of `main`**. The shape alone would not do: the
  repository is public, and GitHub serves the commit of any fork by its SHA from this repository's
  address. The gate fetches `refs/heads/main` and nothing else, then asks git whether the commit
  is one of its ancestors.
- The commit must **not be older than the one being served**. A commit of `main` is not harmless
  because it was once reviewed: the one from before a fix is the flaw itself, and a stolen key
  would bring it back. Going back is a decision a human makes, by hand. Deploying again the commit
  being served is not going back, and stays possible — a deployment that failed halfway can be
  replayed.
- The commit also has to **carry the deployment scripts**. One from before they existed would be
  checked out with nothing to deploy it, and would take the gate away with it: the key would then
  answer nothing until someone logged in by hand. Deploying such a commit is a human's decision.
- The shape of the SHA is checked against a list of characters, not a range: in a UTF-8 locale
  bash reads `a-f` by collation, `[0-9a-f]` then matches `é` and the digits of other scripts, and
  the locale is the one thing an SSH client is commonly allowed to send.
- One `ed25519` pair per environment. Revoking preprod's does not touch production's, nor any
  human access.

A stolen key can therefore move the server forward along `main`, and do nothing else. The gate keeps one
line per request in `storage/logs/deploy-gate.log`: when, from which address, what was decided.

**In GitHub**, by where the key lives and by what the workflow is allowed to be:

| | |
|---|---|
| The key | secret `DEPLOY_SSH_KEY` of the **environment** `preprod` — a job reads it only if it names the environment, and only `main` may deploy to it |
| The server's identity | variable `DEPLOY_SSH_KNOWN_HOSTS`: the host key, **pinned**. The connection uses `StrictHostKeyChecking=yes`; the workflow never runs `ssh-keyscan` and never accepts a key it meets |
| Where to connect | variables `DEPLOY_SSH_HOST` and `DEPLOY_SSH_USER` — not secrets, both are in this file |
| Third-party code | **none**. The workflow uses no action, not even one of GitHub's: `ssh` is already on the runner |
| What a caller controls | one input, a closed list of two words, read through an environment variable. The SHA is `github.sha` |
| Token | `permissions: {}` |

`scripts/__tests__/deploy-workflow.test.js` holds each line of that table, and runs the step of the
workflow against a stand-in for `ssh`: the key file is `600` while it exists and gone when the step
ends, whether the server answered or not.

**The pipeline cannot deploy a candidate branch**, on purpose: the gate only knows `main`.
[Deploying a candidate](#deploying-a-candidate-before-it-reaches-main) stays a gesture made by
hand until LUMN-53 decides otherwise.

### Setting it up

**1. The gate has to be on the server before the key is tied to it.** Deploy by hand, once, a
commit that contains `scripts/deploy-gate.sh` — see [Deploying](#deploying).

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
printf '\ncommand="/usr/bin/env bash /home/cardascia-it/preprod/scripts/deploy-gate.sh",restrict %s\n' \
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
- **Not measured yet, on 2026-10-11**: that a session opened by the pipeline's key finds the Node
  the panel selects. The key has answered `php-version`; the first scripted deployment, on
  2026-10-10, went through a human access. The first `deploy` request from GitHub answers it.
- **Two deployments at once.** The workflow never runs two, but the server itself does not refuse
  a second request while a first is running. A lock belongs with LUMN-51, which rebuilds how a
  release is switched.

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

## Not done yet

- **No deployment on merge.** The sequence is a script and the pipeline can run it on request
  (LUMN-50), but someone still has to ask. Deploying every merge is LUMN-53; switching releases
  atomically, and going back, is LUMN-51.
- **No rate limiting anywhere.** `grep -rn throttle routes/ app/Http/` returns nothing, and
  `LoginRequest` does not call `ensureIsNotRateLimited()`. `/{lang}/login` accepts unlimited
  password attempts, and the six-digit TOTP challenge behind it accepts unlimited codes —
  which is the more serious of the two, since a TOTP's entire security rests on a small
  number of attempts. HTTP Basic authentication closes both on preprod. **This must be
  closed before `cardascia-it.org` points here.** (LUMN-36)
- **No security headers**, and `robots.txt` allows everything — preprod would be indexed if
  it were reachable. (LUMN-37)
- **Nameserver delegation to alwaysdata** is not done; `cardascia-it.org` still resolves
  through one.com, which redirects to the previous site, untouched. It depends on settling the
  `contact@cardascia-it.org` mailbox first.
- **No backup of the preprod database.**
- **Nothing distinguishes preprod from production in the logs**, since both run
  `APP_ENV=production` deliberately. A dedicated variable is needed. (LUMN-40)
