<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeoService
{
    /**
     * Returns geo data for the given IP, served from Redis cache when available.
     * Returns null when the IP cannot be resolved (private/loopback with no fallback
     * configured, fetch failure, or API error) — callers must handle the fallback.
     *
     * @return array<string, mixed>|null
     */
    public function locate(?string $ip): ?array
    {
        $resolved = $this->resolveIp($ip);

        if ($resolved === null) {
            return null;
        }

        $cacheKey = "geo_data:{$resolved}";
        $negativeCacheKey = "geo_data_failed:{$resolved}";
        $ctx = ['service' => self::class, 'method' => __FUNCTION__, 'ip' => $resolved];

        /** @var array<string, mixed>|null $cached */
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug('Geo lookup served from cache', $ctx + ['step' => 'cache_hit']);

            return $cached;
        }

        if (Cache::has($negativeCacheKey)) {
            Log::debug('Geo lookup skipped — negative cache active', $ctx + ['step' => 'negative_cache_hit']);

            return null;
        }

        Log::info('Geo lookup cache miss — fetching from API', $ctx + ['step' => 'cache_miss']);

        $data = $this->fetchFromApi($resolved, $ctx);

        if ($data !== null) {
            Cache::put($cacheKey, $data, config('geo.cache_ttl'));
            Log::info('Geo lookup succeeded and cached', $ctx + [
                'step' => 'cache_store',
                'country' => $data['country_code'] ?? 'unknown',
            ]);
        } else {
            Cache::put($negativeCacheKey, true, config('geo.failure_cache_ttl'));
            Log::warning('Geo lookup failed — negative cache set', $ctx + [
                'step' => 'negative_cache_store',
                'ttl' => config('geo.failure_cache_ttl'),
            ]);
        }

        return $data;
    }

    /**
     * Resolves the effective IP to look up.
     * Substitutes a configured fallback when a private or loopback address is
     * detected — useful in local dev where the request always comes from 127.0.0.1.
     * Returns null when no usable IP can be determined.
     */
    private function resolveIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        $isPublic = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        if ($isPublic) {
            return $ip;
        }

        $fallback = config('geo.dev_fallback_ip');

        return (is_string($fallback) && $fallback !== '') ? $fallback : null;
    }

    /**
     * @param  array<string, mixed>  $ctx  Caller context forwarded for log consistency
     * @return array<string, mixed>|null
     */
    private function fetchFromApi(string $ip, array $ctx): ?array
    {
        try {
            $baseUrl = config('geo.api_base_url');
            $response = Http::timeout(config('geo.fetch_timeout'))
                ->get("{$baseUrl}/{$ip}");

            if (! $response->successful()) {
                Log::warning('Geo API returned a non-2xx response', $ctx + [
                    'step' => 'api_fetch',
                    'status' => $response->status(),
                ]);

                return null;
            }

            /** @var array<string, mixed>|null $data */
            $data = $response->json();

            if (! is_array($data) || ($data['status'] ?? null) === 'fail') {
                Log::warning('Geo API returned an error payload', $ctx + [
                    'step' => 'api_parse',
                    'error' => is_array($data) ? ($data['message'] ?? 'unknown') : 'invalid_json',
                ]);

                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            Log::error('Geo API fetch threw an exception', $ctx + [
                'step' => 'api_fetch',
                'exception' => $e,
            ]);

            return null;
        }
    }
}
