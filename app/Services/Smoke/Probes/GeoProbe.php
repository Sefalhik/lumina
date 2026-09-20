<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Enums\SmokeSeverity;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * /api/geo is the one place the server calls out to the internet — the boot overlay reads it on a
 * first visit. Everything else in this suite goes inwards, so an outbound rule, a DNS resolver or a
 * rate limit that only exists on the server is invisible to all of it.
 *
 * It answers 200 either way: GeoController turns a failed lookup into {"error": true}. The status
 * code alone would always pass, which is why the payload is what this probe reads.
 *
 * A warning, not a blocker: the overlay degrades without it, and ip-api.com is a free third party —
 * refusing a release because someone else's service is down is how a gate earns its bypass.
 */
final class GeoProbe implements SmokeProbe
{
    public function id(): string
    {
        return 'geo';
    }

    public function label(): string
    {
        return 'The geolocation proxy reaches its upstream API';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $response = $site->get('/api/geo');

        if ($response?->status() !== 200) {
            return SmokeCheck::fail(
                $this->id(),
                $this->label(),
                '/api/geo answered '.($response?->status() ?? 'nothing').' instead of 200.',
                'The endpoint itself is broken, not its upstream: read storage/logs/laravel.log.',
                SmokeSeverity::Warning,
            );
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['error'] ?? false) === true || $payload === []) {
            return SmokeCheck::fail(
                $this->id(),
                $this->label(),
                '/api/geo answered 200 with no location: the upstream call failed.',
                'The server could not reach ip-api.com — check outbound HTTP and DNS on the host, and the GEO_* values. A failure is negatively cached for GEO_FAILURE_CACHE_TTL seconds, so retry after that window before investigating.',
                SmokeSeverity::Warning,
            );
        }

        return SmokeCheck::pass($this->id(), $this->label());
    }
}
