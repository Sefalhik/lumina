# Deployment — The server

Part of [Deployment](README.md).

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

### Security headers, set by the application

Every response carries them — pages, errors, `/up`, the API — from the `SecurityHeaders`
middleware, first of the global stack (LUMN-37). Nothing is set in the panel: what the application
sends is versioned and tested, what the panel sends is neither. One consequence of that choice:
**static files Apache serves itself (`build/`, favicons) carry none of them**, since PHP never sees
those requests. They are not documents, and the headers that matter — frame, sniffing, HSTS — are
held by the pages that load them.

| Header | Why it matters on this host |
|---|---|
| `Strict-Transport-Security` | HTTPS only. **Its duration climbs by steps** — see below |
| `X-Frame-Options: DENY` | Forbids showing the site inside another page's frame. It also forbids pointing the domain at the site through a framing redirect, as one.com's "web relay" once did: the domain moves by delegating its nameservers (LUMN-96), never by a frame |
| `X-Robots-Tag: noindex, nofollow` | Sent while `SITE_INDEXABLE` is not `true` — see [Indexing is decided by one variable](../seo-conventions.md#indexing-is-decided-by-one-variable) |
| `Content-Security-Policy-Report-Only` | Reports, blocks nothing yet. Each inline script carries a per-request nonce, and so does every style Vite injects. `content-security-policy.spec.js` requires zero violations in the three themes, 404 page included, but one: vuedraggable's eval probe, until LUMN-90 removes it. Error pages render through Laravel's own layout, republished in `resources/views/errors/` for the nonce alone |
| `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` | The usual three; the site uses no camera, microphone or geolocation |

**Raising the HSTS duration.** A browser that has seen `max-age` refuses plain HTTP for that long,
and with `includeSubDomains` on every subdomain of the host that sent it — for the apex, every name
under `cardascia-it.org`, `ftp.` included. Nothing takes it back before it expires. The duration is
`security-headers.hsts.max_age` in `config/security-headers.php`: **one day** (`86400`) until the
production domain is live and every subdomain in the zone is known to answer over HTTPS, then one
year (`31536000`), through a pull request like any other change.

## The account environment — set this before anything else

**This section is why [the deployment sequence](deploying.md#deploying) is short.** The commands are bare
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
| Node.js | the major written in `.nvmrc` | Same. `.nvmrc` is the one place the project writes its Node version: CI and nvm read it, Renovate proposes its next LTS — [Node: the next LTS, never a release before it](../dependencies/renovate.md#node-the-next-lts-never-a-release-before-it). This panel is the one copy that cannot read it: set it by hand when `.nvmrc` changes. `package.json` declares `"node": ">=24"`, a floor, not the version run |
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
directory of its own — see [How a site is laid out](deploying.md#how-a-site-is-laid-out). `.htpasswd` lives at
the account root, beside no code at all.

Note on SSH access to alwaysdata itself: the panel has **no field for SSH keys** outside
Cloud Privé offerings. The key goes into `~/.ssh/authorized_keys` via `ssh-copy-id`, which
needs password authentication enabled — so **do not disable it until key authentication is
proven**, or the door closes with the key still inside.

---

## Setting up a site

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
[Setting it up](pipeline.md#setting-it-up).

**Moving an environment that was a single checkout.** Preprod was one, in `~/preprod`, served from
`preprod/public`. Build the new site beside it and leave the old one untouched until the new one
is verified: if anything goes wrong, putting `preprod/public` back in the panel is the whole way
back. Two differences from a new site: `shared/.env` is a copy of `~/preprod/.env`
(`cp -p`), and `shared/storage` a copy of `~/preprod/storage` (`cp -a`), which carries the gate's
journal over. The line of the pipeline's key in `~/.ssh/authorized_keys` names the old path: it
is replaced last, once the new site answers, and the eight probes are run again.

---
