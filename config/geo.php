<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Geo API
    |--------------------------------------------------------------------------
    |
    | Configuration for the ipapi.co reverse-proxy endpoint used by the boot
    | sequence. Requests are made server-side to avoid CORS restrictions and
    | results are cached in Redis per visitor IP.
    |
    */

    // ip-api.com: no API key, 45 req/min, HTTP only on free tier (fine for server-side use).
    'api_base_url' => env('GEO_API_BASE_URL', 'http://ip-api.com/json'),

    // Fallback IP used when a private/loopback address is detected (local dev only).
    // Set to any public IP you want to simulate. Leave empty to return the geo fallback.
    'dev_fallback_ip' => env('GEO_DEV_FALLBACK_IP', ''),

    'fetch_timeout' => (int) env('GEO_FETCH_TIMEOUT', 3),

    'cache_ttl' => (int) env('GEO_CACHE_TTL', 86400),

    // How long to suppress retries after a failed API call (rate-limit / network error).
    // Avoids hammering ipapi.co when it returns 429 or is unreachable.
    'failure_cache_ttl' => (int) env('GEO_FAILURE_CACHE_TTL', 60),

];
