# Authentication, 2FA and roles

## Authentication & 2FA flow
All auth routes are under the `/{lang}/` prefix so locale is always set when these pages render.

The admin access chain enforces three middleware layers in order:

```
auth → role:admin → two_factor_verified
```

`EnsureTwoFactorVerified` (`app/Http/Middleware/`) implements a three-state gate:
1. No `two_factor_confirmed_at` on the user → redirect to `/{lang}/two-factor/setup`
2. Flag present but `auth.two_factor_verified` absent from session → store `url.intended`, redirect to `/{lang}/two-factor/challenge`
3. Session flag present → pass through

Session keys used by the 2FA flow:
- `auth.two_factor_setup_secret` — temporary secret stored during setup, cleared on confirm
- `auth.two_factor_verified` — boolean flag set after a successful challenge, lasts the session

The admin account is seeded via `AdminSeeder` from `.env` values (`ADMIN_EMAIL`, `ADMIN_NAME`, `ADMIN_PASSWORD`). Run once after `migrate`: `php artisan db:seed --class=AdminSeeder`.

`bootstrap/app.php` configures both redirect callbacks:
- `redirectUsersTo` — authenticated users hitting guest routes → `/{lang}/home`
- `redirectGuestsTo` — unauthenticated users hitting auth routes → `/{lang}/login`

## Rate limiting

Since LUMN-36, `App\Services\Auth\AuthThrottleService` counts failed attempts and says when to
refuse the next one. `LoginController` and `TwoFactorController` ask it **before** checking a
password or a code, so a locked-out caller never gets to test one.

| Form | Counter key | Limit | Wait |
|---|---|---|---|
| `POST /{lang}/login` | email + IP | 5 failures | 1 minute |
| `POST /{lang}/login` | email alone | 20 failures | 1 hour |
| `POST /{lang}/two-factor/challenge` and `/setup` | user id | 5 wrong codes, shared by both forms | 15 minutes |

**Only failures count.** A correct password or code consumes no attempt and clears the counters
it belongs to — both login counters on a successful login. A refused attempt is not counted
either. The windows are fixed — a counted refusal would not lengthen the wait — but it would feed
the email counter: a caller retrying through a one-minute lockout would push the address towards
its one-hour ceiling.

**Why email + IP, and why that is enough here.** The caller is not identified yet, so there is no
better key. On alwaysdata the IP is a real barrier: `mod_remoteip` sets `REMOTE_ADDR` upstream
and no proxy is trusted, so a forged `X-Forwarded-For` changes nothing — see
[`trustProxies` was configured, and had to be removed](deployment.md#trustproxies-was-configured-and-had-to-be-removed). `AuthThrottleTest` checks it, and fails the
day `trustProxies` comes back. On another host, re-measure before relying on it.

**Why a second counter on the email alone.** Guesses spread over many addresses never reach a
per-address limit. That counter stays wide and expires on its own: the site has one account, and
a hard lock on the email would let anyone lock its administrator out by submitting the address
in a loop.

**Why the user id for two-factor.** The caller already holds the password. The key depends on
no header and no host, and this is the lock that matters: a six-digit code is only safe because
few attempts are allowed.

The email is hashed into the counter key, never written into it: counters live in the cache, the
cache lives in the database, and an address is personal data.

The refusal reuses `auth.throttle`, which announces the wait in seconds. Each refused attempt
logs a `warning` (`login_throttled`, `two_factor_throttled`); the failure that starts a lockout
logs one `alert` (`login_lockout`, `two_factor_lockout`) — brute force is its likeliest reading.

Not covered here, and tracked: the health route `/up` has no ceiling (LUMN-92).

## A code is accepted once

A six-digit code stays valid for about 90 seconds: Google2FA accepts the current 30-second step
and the one on either side, for clock drift. Since LUMN-91, `users.two_factor_last_used_timestep`
holds the step of the last code accepted, and only a **newer** step passes — a code seen over a
shoulder, or intercepted, cannot open a second session.

| Step | Where | Cost |
|---|---|---|
| `TwoFactorService::verify()` finds the code among the steps newer than the last one | computation only | no query |
| `claim()` records it at the challenge, in `UPDATE … WHERE id = ? AND (last IS NULL OR last < ?)` | the database decides | one statement, by primary key, `updated_at` untouched |
| `confirm()` records it at setup, with the secret and the confirmation date | one `save()` | no extra query |

**The database decides, not the model.** Two requests carrying the same code may both have loaded
the user before either claims it; the `WHERE` lets one of them change the row and the other none.
At setup no such race matters: confirming overwrites the whole state anyway.

**A null column means no code accepted yet**, named `NO_CODE_ACCEPTED_YET` in the service. It is
passed to Google2FA as `0`, not `null`: given `null`, `verifyKeyNewer()` answers `true` instead of
the step it accepted, and there would be nothing to record.

A replayed code is refused with the same message as a wrong one, counts as a failed attempt for
[Rate limiting](#rate-limiting), and logs a `warning` with the step `code_replayed`.

**The current step is read from Carbon**, not from Google2FA's own `microtime()`: the application
keeps one clock, and the tests freeze it at an absolute date.

### When the code cannot be checked at all

Two improbable cases, both **failing closed** — the code is refused with the usual message, never
a 500, never a pass — and both logged as an `error`, so that they are diagnosed rather than
mistaken for a wrong code:

| Case | How it can happen | Step logged | Way out |
|---|---|---|---|
| The last step on record is beyond the window ahead | the server clock went back (an NTP correction, a restored snapshot), or the row was copied from another environment | `timestep_in_future`, with both steps | wait until time catches up — codes pass again on their own — or set `users.two_factor_last_used_timestep` to `NULL` |
| The stored secret cannot be read | `APP_KEY` rotated without re-encrypting the column, a secret Google2FA rejects, or a confirmed 2FA with no secret | `secret_unreadable`, with the exception | restore the key, or reset the 2FA of that account — there is no screen for it yet |

One step ahead is not an anomaly: it is the furthest a legitimate acceptance can record, a code
typed with the authenticator's clock slightly ahead.

A confirmed 2FA whose secret is missing is **not** sent back to setup: that would let a new secret
be enrolled behind the password alone. The challenge tests `two_factor_confirmed_at`, like
`EnsureTwoFactorVerified`, and refuses.

## Roles & access
| Role | Access |
|------|--------|
| `admin` | Everything — monitoring, private tools, CMS |
| `maintainer` | Private sections in read-only |
| `member` | Semi-private sections, no tools/monitoring |
| — (public) | CV, blog, projects |
