<?php

/*
|--------------------------------------------------------------------------
| Smoke tests — php artisan deploy:smoke (LUMN-49)
|--------------------------------------------------------------------------
|
| See docs/smoke-tests.md. The credentials are those of the HTTP Basic
| authentication in front of preprod; production has none.
|
*/

return [
    'basic_user' => env('SMOKE_BASIC_USER'),
    'basic_password' => env('SMOKE_BASIC_PASSWORD'),

    // Seconds before a single request gives up.
    'timeout' => (int) env('SMOKE_TIMEOUT', 10),

    // A freshly switched release may be cold: /up is retried for up to
    // attempts × sleep before the site is declared down. Nowhere else retries.
    'warmup_attempts' => 6,
    'warmup_sleep_ms' => 5000,

    // Days left on the TLS certificate below which the check warns, then blocks.
    'certificate_warning_days' => 14,
    'certificate_blocking_days' => 3,

    // Homepage response time above which the check warns. Never blocking.
    'response_time_warning_ms' => 1500,
];
