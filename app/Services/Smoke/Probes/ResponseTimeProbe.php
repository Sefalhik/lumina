<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Enums\SmokeSeverity;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * Never blocking: a slow page is a reason to look, not to refuse a release. Most often it means a
 * cache the deployment forgot to warm.
 */
final class ResponseTimeProbe implements SmokeProbe
{
    public function id(): string
    {
        return 'response-time';
    }

    public function label(): string
    {
        return 'The homepage answers quickly';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $threshold = (int) config('smoke.response_time_warning_ms');

        $start = hrtime(true);
        $response = $site->get('/fr');
        $elapsed = (int) round((hrtime(true) - $start) / 1_000_000);

        if ($response === null || $elapsed > $threshold) {
            return SmokeCheck::fail(
                $this->id(),
                $this->label(),
                $response === null ? 'The homepage did not answer.' : "{$elapsed} ms, above the {$threshold} ms budget.",
                'Check that the caches are warm (config:cache, route:cache, view:cache) and read the slow requests in the server logs.',
                SmokeSeverity::Warning,
            );
        }

        return SmokeCheck::pass($this->id(), $this->label(), "{$elapsed} ms");
    }
}
