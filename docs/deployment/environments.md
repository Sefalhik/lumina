# Deployment — Environments, secrets and drivers

Part of [Deployment](README.md).

## Environments

| | Address | Served by | Purpose |
|---|---|---|---|
| dev | `dev.cardascia-it.org` | FrankenPHP / Octane, local | Development |
| **preprod** | `preprod.cardascia-it.org` | alwaysdata, Apache | The new site, until it is validated |
| prod (current) | `cardascia-it.org` | one.com answers `308` to `cardascia-it.alwaysdata.net`, the hosting account's default address, where the previous site is served | Stays online and untouched |
| prod (target) | `cardascia-it.org` | alwaysdata, Apache | Not yet — see [Not done yet](README.md#not-done-yet) |

The old site stays reachable while the new one is built at a temporary address. The cutover
does not wait for the editorial content: the site goes live with its homepage alone, **out of
search engines' index**, and is opened to indexing by one line of the production `.env` once its
content deserves it (decision of 2026-11-07, LUMN-96) — see
[Indexing is decided by one variable](../seo-conventions.md#indexing-is-decided-by-one-variable).

Measured on 2026-10-10: `cardascia-it.org` does not *serve* the old site, it redirects to it, and
a deep link loses its path on the way — `/fr/cv` lands on the root of the other address. This table
said "one.com, previous technology" until then. It is the starting point of LUMN-35, not something
to repair on the old site.

---

## The domain and its mail

`cardascia-it.org` is registered at one.com (reseller of Ascio Technologies) until **2027-02-26**,
and its nameservers are still one.com's. Its **mail lives at alwaysdata** since 2026-11-07
(LUMN-95): the site and the mail at one provider, simpler to hold — and an outage or a suspension
of the account takes both at once, which is accepted.

| Mailbox | Notes |
|---|---|
| `contact@cardascia-it.org` | The site's public address, and `MAIL_FROM_ADDRESS` |
| one personal mailbox | Not named here: this repository is public |

Both were imported from one.com by IMAP (*Emails → Addresses → Modify*, server `imap.one.com:993`).
The catch-all one.com delivered to `noreply@` was **not** recreated: the application sends no mail
(`MAIL_MAILER=log`), and a catch-all mostly collects spam. No mail reached one.com after the MX
moved — checked by hand once the old MX's two-hour TTL had run out.

**The records the zone carries** — in one.com's zone today, and to be carried into alwaysdata's
**before** the nameservers move (LUMN-96), checked with `dig` against the new servers:

| Type | Name | Value |
|---|---|---|
| `MX` | `@` | `10 mx1.alwaysdata.com`, `20 mx2.alwaysdata.com` |
| `TXT` | `@` | `v=spf1 include:_spf.alwaysdata.com ~all` |
| `TXT` | `alwaysdata._domainkey` | `v=DKIM1; k=rsa; p=…` — the key from the alwaysdata panel |
| `TXT` | `_dmarc` | `v=DMARC1; p=none; rua=mailto:<an address outside the domain>` |

A message sent from `contact@` passed SPF, DKIM and DMARC at Gmail on 2026-11-07. Three traps met
on the way:

- **The DKIM key is not published as the panel shows it.** The panel gives a PEM block; the record
  wants its base64 alone, after `v=DKIM1; k=rsa; p=`, under the name `<selector>._domainkey`. The
  selector is `alwaysdata` — it is the `s=` of the `DKIM-Signature` header on a sent message.
- **one.com's two nameservers disagreed for seven and a half minutes** after a change, while
  announcing the same SOA serial: the zone's refresh time says nothing about how long that lasts.
  Query both before concluding.
- **The webmail (Roundcube) writes the sender's own IP address** into every message it sends. A mail
  client sending through alwaysdata's SMTP does not.

**Two rules that outlive the move:**

- **Every account's contact address lives outside the domain** — alwaysdata, one.com, the domain's
  registrant. An address on `cardascia-it.org` cannot receive the warning that the domain or the
  account is about to be lost. They were moved on 2026-11-07; alwaysdata's invoices used to arrive
  on the domain it hosts.
- **The domain is transferred before one.com is cancelled.** At one.com the domain is part of the
  package: ending the package ends the registration. The transfer takes six to eight days and must
  land before 2027-02-26; it moves the nameservers too, so it follows LUMN-96 or is part of it.

---

## Where a secret lives

Three homes, one per audience. **None of them is the repository.**

| Audience | Home |
|---|---|
| A human | A password manager. Not a file in `Documents`, not a note, not a chat message |
| The running application | `/home/cardascia-it/lumina/preprod/shared/.env` on the server |
| The pipeline | GitHub **environment** secrets — `preprod` today, `production` with LUMN-54 — readable only by a job that names the environment. See [The pipeline's access to the server](pipeline.md#the-pipelines-access-to-the-server) |

The three `JIRA_*` secrets stay at repository level: `jira-sync.yml` runs on pull-request events,
outside any environment, and they open nothing on a server.

The server-side `.env` sits **outside the document root** by construction. It lives in
`shared/`, outside every release, and reaches a release through a link at its root; Apache is
pointed at `current/public`, the `public/` directory of the release in service, so the link is
one level above anything the web server will serve. That is not a convenience — it is the whole
reason Laravel puts `index.php` in a subdirectory. See [How a site is laid out](deploying.md#how-a-site-is-laid-out).

`.env` is listed in `.gitignore`. `.env.example` is versioned and holds **names only**,
never values. Between them: `.env.example` answers *which* variables exist,
this file answers *where each value comes from*.

---

## The environment file

Which variables exist, what each one means and where its value comes from:
**[Environment variables](../environment-variables.md)** — including
[the ones that must never exist on a server](../environment-variables.md#deliberately-absent-from-preprod-and-production).

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
`<…>` is a placeholder: fill it from the source named in [the tables](../environment-variables.md), never from
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

And three blocks are **absent on purpose**: `ANTHROPIC_API_KEY` (no
translation command runs on a server), `OCTANE_*` and `PHP_INI_SCAN_DIR` (both describe the local
FrankenPHP setup).

---

## What protects the site from a flood of requests

**The application limits attempts, not load.** Since LUMN-36, `/{lang}/login` and both two-factor
forms refuse further attempts after repeated failures, and since LUMN-91 a code is accepted once —
[Rate limiting](../authentication.md#rate-limiting). Nothing in Laravel caps how often a page may be
requested, and that is a decision, not a gap.

**`/up` has no ceiling, decided on 2026-11-05 (LUMN-92).** A limit on it was planned because it is
public and queries the database. Measured that day with the drivers production runs — sessions and
cache in the database:

| Route | Queries per request |
|---|---|
| `/up` | **1** — `select 1`, no session |
| `/{lang}` | 5 |
| `/{lang}/cv` | 4 |
| `/{lang}/login` | 2 |

Every page reads and writes its session; `/up` sits outside the `web` group and does neither. It is
the cheapest route of the site: a loop meant to load the database runs on a page instead, which a
ceiling on `/up` alone never reaches. The planned limiter, counting in the default cache, would have
made it worse — **7 queries instead of 1 on every call, a row lock among them**, refused calls
included.

Only the first line of the table is held by a test; the others are that day's measurement — take it
again before relying on them, with a `DB::listen` around a request and both drivers set to
`database`. `HealthRouteCostTest` holds two things:

- `/up` makes one query: put it in the `web` group, or give it a limiter counting in the database,
  and the test fails;
- `/up` answers a hundred calls in a row: a limiter counting in a file or in memory costs no query,
  and only this one sees it. A ceiling above a hundred a minute would pass — it would contradict
  the decision without refusing the supervision, which polls far less often.

Either failure means this decision has to be taken again.

A ceiling on every route would not stop a flood either: each request boots PHP before it can be
refused, and a determined sender changes address. What stands in front is the host's, as alwaysdata
describes it:

- layer 3/4 anti-DDoS on every server, absorbing medium attacks without intervention, with
  protection at the network provider beyond that;
- Fail2ban, banning an address for ten minutes after about fifty failed connections;
- the WAF, set to `basic` — protocol compliance and malicious robots. It offers no per-address
  request rate for a site.

If floods ever become a real problem, the lever is in front of the host — a proxy or CDN that rate
limits — not in Laravel. It would change what `$request->ip()` means, and with it the keys of the
login limiters: re-read
[`trustProxies` was configured, and had to be removed](history.md#trustproxies-was-configured-and-had-to-be-removed)
before adding one. Re-measure the table above as well: it changes the day a page adds a query.

---
