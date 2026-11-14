# Logging conventions

> Reference for all logging in this project. Follow these rules in every service,
> controller, and job — no exceptions (pun intended).

---

## 1. The golden rule: static messages, structured context

Log messages must be **static strings**. All variable data goes in the context array.

```php
// ✅
Log::error('Two-factor secret unreadable — code refused', ['user_id' => $user->getKey(), 'exception' => $e]);

// ❌
Log::error("Two-factor secret of user {$user->id} unreadable: " . $e->getMessage());
```

Reasons: static messages are groupable in log aggregators (Loki, Graylog…);
interpolated strings generate as many unique "events" as there are values.

---

## 2. Mandatory context keys

Every log call **must** include these keys so that any log line is self-identifiable
without reading surrounding lines.

| Key | Type | Description | Example |
|---|---|---|---|
| `service` | `string` | Fully-qualified class name — use `self::class` | `App\Services\Auth\TwoFactorService` |
| `method` | `string` | Method name — use `__FUNCTION__` | `verify` |
| `step` | `string` | Human label for the execution stage | `'timestep_in_future'`, `'code_replayed'` |

```php
Log::warning('Two-factor code refused — already used', [
    'service' => self::class,
    'method'  => $method,
    'step'    => 'code_replayed',
    'user_id' => $user->getKey(),
]);
```

Nothing is injected at request level yet — see [Correlation](#5-correlation--not-implemented-yet-lumn-30).
Pass `user_id` explicitly when a log line is about a user, as `AuthThrottleService` does.

---

## 3. Exception logging

Always pass the **full `Throwable` object** under the `exception` key.
Monolog/JsonFormatter serializes it into class, message, file, line and full stack trace.
Never reduce it to a string.

```php
// ✅ Full exception — stack trace included
Log::error('Two-factor secret unreadable — code refused', [
    'service'   => self::class,
    'method'    => $method,
    'step'      => 'secret_unreadable',
    'user_id'   => $user->getKey(),
    'exception' => $e,          // Throwable — Monolog handles serialization
]);

// ❌ Only the message — trace lost forever
Log::error('Two-factor secret unreadable — code refused', [
    'exception' => $e->getMessage(),
]);
```

`exception` is the only PSR-3 reserved context key. Monolog gives it special treatment
across all formatters and handlers.

---

## 4. Log levels — when to use which

| Level | Meaning | Production noise | Example |
|---|---|---|---|
| `debug` | Diagnostic detail, dev/troubleshooting | Disabled in prod | Cache hit, raw API response |
| `info` | Normal business event, state change | Low | User registered, lookup cached |
| `notice` | Normal but significant system event | Low | Config cleared, deprecation |
| `warning` | Expected failure, gracefully handled | Medium | API timeout → fallback used, quota at 80% |
| `error` | Operation failed, app continues | High → alerts | Payment failed, email not sent |
| `critical` | Infrastructure failure, many users affected | Page on-call | DB down, auth provider unreachable |
| `alert` | Security/integrity — act immediately | Page on-call | Brute force detected |
| `emergency` | System unusable | Page on-call | Unrecoverable corruption |

**Decision tree**

```
Did something go wrong?
  NO  → is it a business/state event?  → info
        is it a system-level event?    → notice
  YES → is the app still running?
          NO  → emergency / critical
          YES → was it expected and handled?  → warning
                is it an operation failure?   → error
                is it a security event?       → alert
```

---

## 5. Correlation — not implemented yet (LUMN-30)

**No middleware stamps log lines today.** No `request_id` exists, and no `user_id` is added for
you: two lines of the same request can only be tied together by their timestamp and address.

This section once described a `LogRequestContext` middleware as if it existed, and told service
code not to repeat what it would add. The file was never written, and the instruction kept every
service from compensating — that gap is what LUMN-30 was opened for.

What LUMN-30 plans, so that nobody has to rediscover it:

- a `request_id` set before anything else, through Laravel's `Context` facade — Laravel 13 then
  writes it into every log record, as a second JSON object (Monolog's `extra`), not merged into
  the first;
- a `X-Request-ID` header taken over **only if it is shaped like a UUID**, otherwise ignored and
  regenerated — a client must not choose an identifier, or slip line breaks into the log;
- `user_id` on every line, `null` for a visitor — the key present, its absence an information.

Until it ships: pass `user_id` yourself when it matters, and do not invent a `request_id`.

---

## 6. What never goes in logs

| Banned data | Why | Safe alternative |
|---|---|---|
| Passwords (plain or hashed) | Credential exposure | — |
| Full JWT / API keys / tokens | Credential exposure | First 8 chars + `…` |
| Credit card numbers | PCI-DSS | `last4` only |
| Full email addresses | GDPR/PII | `user_id` (numeric) |
| Full request/response bodies | May contain any of the above | Specific fields only |
| `$request->all()` / `->toArray()` on models | Dumps unknown fields | Whitelist fields explicitly |

When in doubt: **log IDs, not values**.

---

## 7. Channel strategy (current)

The project uses a single `stack` channel (default). A dedicated channel (`auth`, say) or
Loki integration may be added later without changing call sites.

```php
// Services always use the default facade — channel routing is infrastructure concern
Log::info(…);          // not Log::channel('auth')->info(…)
```

This will be revisited when a log aggregator (Loki/Grafana) is set up.

---

## 8. Quick reference — read the real thing

No example is copied here: a copy drifts from the code it was taken from, and keeps being followed
after the code is gone. The canonical reference was `GeoService` until LUMN-122 removed it — and
removed with it the code this section reproduced. Read these instead; each is tested, and each
shows a different part of the rules.

| Service | What it shows |
|---|---|
| `App\Services\Auth\TwoFactorService` | The whole set on one service: static messages, the three mandatory keys, `user_id`, the full `Throwable` under `exception` (`logUnreadableSecret()`), and the levels — `info` for a state change (`confirm()`), `warning` for an expected refusal (a replayed code), `error` for a state that should not exist (a last step in the future) |
| `App\Services\Auth\AuthThrottleService` | A security event, and the step from `warning` (throttled) to `alert` (locked out) |
| `App\Services\AnthropicTranslator` | A third party's failure, and how much of its answer to keep: the status, its message, a bounded excerpt — never the whole body |
