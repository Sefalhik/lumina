# Deployment — What the first deployments taught

Part of [Deployment](README.md).

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
