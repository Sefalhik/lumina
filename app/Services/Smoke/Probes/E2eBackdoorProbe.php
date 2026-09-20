<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * GET /e2e/admin-auth creates an admin and logs the caller in — no password, no TOTP. It is loaded
 * on an allowlist of local and testing, and until 2026-09-14 that guard was a denylist of
 * production, which published the backdoor on the preprod site. This probe is the one that watches
 * the guard from outside, on the machine it protects.
 *
 * All three helper routes are read, not one. They share a conditional block today, so probing a
 * single route would pass on the strength of what the code looks like — and a probe that reasons
 * about the code it is checking has stopped being an outside view. The two others also accept a
 * POST that overwrites site-wide content; those are deliberately never sent, because a POST that
 * answers is a POST that already wrote.
 */
final class E2eBackdoorProbe implements SmokeProbe
{
    private const HELPER_PATHS = ['/e2e/admin-auth', '/e2e/homepage-content', '/e2e/site-identity'];

    public function id(): string
    {
        return 'e2e';
    }

    public function label(): string
    {
        return 'The E2E helper routes are not published';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $published = [];

        foreach (self::HELPER_PATHS as $path) {
            $response = $site->get($path);
            if ($response?->status() !== 404) {
                $published[] = $path.' → '.($response?->status() ?? 'no answer');
            }
        }

        return $published === []
            ? SmokeCheck::pass($this->id(), $this->label())
            : SmokeCheck::fail(
                $this->id(),
                $this->label(),
                implode('; ', $published).' — expected 404.',
                'The E2E helper routes are loaded, and one of them logs anyone in as an admin: set APP_ENV=production on every internet-facing host, then php artisan config:cache.',
            );
    }
}
