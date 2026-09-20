<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * On 2026-09-14 the site asked for a Basic auth password over plain HTTP, because *Force HTTPS* was
 * still unticked: Basic transmits credentials as base64 — encoding, not encryption. The redirect has
 * to come first, and it has to be permanent, or browsers will keep trying plain HTTP.
 */
final class HttpsRedirectProbe implements SmokeProbe
{
    public function id(): string
    {
        return 'https';
    }

    public function label(): string
    {
        return 'Plain HTTP redirects permanently to HTTPS';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        if (! $site->target->isHttps()) {
            return SmokeCheck::skip($this->id(), $this->label(), 'The target is not served over HTTPS.');
        }

        $response = $site->get('http://'.$site->target->host().'/fr');
        $location = $response?->header('Location') ?? '';

        if ($response !== null && in_array($response->status(), [301, 308], true) && str_starts_with($location, 'https://')) {
            return SmokeCheck::pass($this->id(), $this->label());
        }

        return SmokeCheck::fail(
            $this->id(),
            $this->label(),
            'http:// answered '.($response?->status() ?? 'nothing').($location !== '' ? " towards {$location}" : '').'.',
            'Force HTTPS with a permanent redirect (301 or 308) in the hosting panel — a temporary one is not cached, and plain HTTP must never serve the site.',
        );
    }
}
