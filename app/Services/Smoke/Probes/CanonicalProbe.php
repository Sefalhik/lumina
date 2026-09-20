<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\Site\Html;
use App\Services\Smoke\SmokeProbe;

/**
 * A canonical URL naming another scheme or host is how the trustProxies defect of 2026-09-14 showed
 * itself: the application had stopped knowing how it was reached, and every absolute URL it built —
 * canonical, hreflang, redirects — was wrong.
 */
final class CanonicalProbe implements SmokeProbe
{
    public function id(): string
    {
        return 'canonical';
    }

    public function label(): string
    {
        return 'Canonical URLs name the host that was queried';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $wrong = [];
        foreach ($site->pages() as $path => $response) {
            if ($response?->status() !== 200) {
                continue;
            }
            $canonical = Html::canonical($response->body());
            if ($canonical !== $site->target->url($path)) {
                $wrong[] = $path.' → '.($canonical ?? 'none');
            }
        }

        return $wrong === []
            ? SmokeCheck::pass($this->id(), $this->label())
            : SmokeCheck::fail(
                $this->id(),
                $this->label(),
                implode('; ', $wrong),
                'Laravel builds absolute URLs from the request: check APP_URL, and that no proxy rewrites the scheme or the host (docs/deployment.md, trustProxies).',
            );
    }
}
