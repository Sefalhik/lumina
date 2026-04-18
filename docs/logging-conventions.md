# Logging conventions

> Reference for all logging in this project. Follow these rules in every service,
> controller, and job — no exceptions (pun intended).

---

## 1. The golden rule: static messages, structured context

Log messages must be **static strings**. All variable data goes in the context array.

```php
// ✅
Log::error('Geo API fetch threw an exception', ['ip' => $ip, 'exception' => $e]);

// ❌
Log::error("Geo API fetch failed for IP {$ip}: " . $e->getMessage());
```

Reasons: static messages are groupable in log aggregators (Loki, Graylog…);
interpolated strings generate as many unique "events" as there are values.

---

## 2. Mandatory context keys

Every log call **must** include these keys so that any log line is self-identifiable
without reading surrounding lines.

| Key | Type | Description | Example |
|---|---|---|---|
| `service` | `string` | Fully-qualified class name — use `self::class` | `App\Services\GeoService` |
| `method` | `string` | Method name — use `__FUNCTION__` | `locate` |
| `step` | `string` | Human label for the execution stage | `'cache_lookup'`, `'api_fetch'` |

```php
Log::info('Geo lookup served from cache', [
    'service' => self::class,
    'method'  => __FUNCTION__,
    'step'    => 'cache_lookup',
    'ip'      => $ip,
]);
```

Request-level keys (`request_id`, `user_id`) are injected automatically by the
correlation middleware — do **not** repeat them manually in service code.

---

## 3. Exception logging

Always pass the **full `Throwable` object** under the `exception` key.
Monolog/JsonFormatter serializes it into class, message, file, line and full stack trace.
Never reduce it to a string.

```php
// ✅ Full exception — stack trace included
Log::error('Geo API fetch threw an exception', [
    'service'   => self::class,
    'method'    => __FUNCTION__,
    'step'      => 'api_fetch',
    'ip'        => $ip,
    'exception' => $e,          // Throwable — Monolog handles serialization
]);

// ❌ Only the message — trace lost forever
Log::error('Geo API fetch threw an exception', [
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

## 5. Correlation — request ID injection

A middleware injects a `request_id` (UUID) into every log line for the duration
of a request, using Laravel's `Context` facade. This ties together all log lines
from a single HTTP request — including queued jobs dispatched from that request.

```php
// app/Http/Middleware/LogRequestContext.php
Context::add('request_id', $request->header('X-Request-ID') ?? Str::uuid()->toString());
Context::add('user_id',    auth()->id());
```

Service code does **not** need to repeat these — they appear automatically in every
log record emitted during that request.

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

The project uses a single `stack` channel (default). A dedicated `geo` channel or
Loki integration may be added later without changing call sites.

```php
// Services always use the default facade — channel routing is infrastructure concern
Log::info(…);          // not Log::channel('geo')->info(…)
```

This will be revisited when a log aggregator (Loki/Grafana) is set up.

---

## 8. Quick reference — full service example

Taken from `GeoService` — the canonical logging reference for this project.

```php
public function locate(?string $ip): ?array
{
    // resolveIp() substitutes GEO_DEV_FALLBACK_IP for private/loopback addresses.
    // Returns null when no usable public IP can be determined.
    $resolved = $this->resolveIp($ip);
    if ($resolved === null) {
        return null;
    }

    $cacheKey         = "geo_data:{$resolved}";
    $negativeCacheKey = "geo_data_failed:{$resolved}";
    $ctx = ['service' => self::class, 'method' => __FUNCTION__, 'ip' => $resolved];

    // --- tier 1: success cache (24 h by default) ---
    /** @var array<string, mixed>|null $cached */
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        Log::debug('Geo lookup served from cache', $ctx + ['step' => 'cache_hit']);
        return $cached;
    }

    // --- tier 2: negative cache (60 s) — avoids hammering a rate-limited API ---
    if (Cache::has($negativeCacheKey)) {
        Log::debug('Geo lookup skipped — negative cache active', $ctx + ['step' => 'negative_cache_hit']);
        return null;
    }

    Log::info('Geo lookup cache miss — fetching from API', $ctx + ['step' => 'cache_miss']);

    $data = $this->fetchFromApi($resolved, $ctx);

    if ($data !== null) {
        Cache::put($cacheKey, $data, config('geo.cache_ttl'));
        Log::info('Geo lookup succeeded and cached', $ctx + [
            'step'    => 'cache_store',
            'country' => $data['countryCode'] ?? 'unknown',  // ip-api.com field name
        ]);
    } else {
        Cache::put($negativeCacheKey, true, config('geo.failure_cache_ttl'));
        Log::warning('Geo lookup failed — negative cache set', $ctx + [
            'step' => 'negative_cache_store',
            'ttl'  => config('geo.failure_cache_ttl'),
        ]);
    }

    return $data;
}

private function fetchFromApi(string $ip, array $ctx): ?array
{
    try {
        $response = Http::timeout(config('geo.fetch_timeout'))
            ->get(config('geo.api_base_url') . "/{$ip}");

        if (!$response->successful()) {
            Log::warning('Geo API returned a non-2xx response', $ctx + [
                'step'   => 'api_fetch',
                'status' => $response->status(),
            ]);
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = $response->json();

        // ip-api.com signals failure with status: 'fail' (not an HTTP error code)
        if (!is_array($data) || ($data['status'] ?? null) === 'fail') {
            Log::warning('Geo API returned an error payload', $ctx + [
                'step'  => 'api_parse',
                'error' => is_array($data) ? ($data['message'] ?? 'unknown') : 'invalid_json',
            ]);
            return null;
        }

        return $data;

    } catch (\Throwable $e) {
        Log::error('Geo API fetch threw an exception', $ctx + [
            'step'      => 'api_fetch',
            'exception' => $e,   // full Throwable — Monolog serializes class/message/trace
        ]);
        return null;
    }
}
```
