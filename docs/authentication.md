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

Not covered here, and tracked: a TOTP code can still be replayed within its own window
(LUMN-91), and the health route `/up` has no ceiling (LUMN-92).

## Roles & access
| Role | Access |
|------|--------|
| `admin` | Everything — monitoring, private tools, CMS |
| `maintainer` | Private sections in read-only |
| `member` | Semi-private sections, no tools/monitoring |
| — (public) | CV, blog, projects |
