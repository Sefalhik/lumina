<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * First in the catalogue, and it has to be: if the switch to the new release failed, every other
 * probe is testing the old one — and passing. The header comes from the RELEASE file the deployment
 * script writes (LUMN-50/51).
 */
final class ReleaseProbe implements SmokeProbe
{
    public function id(): string
    {
        return 'release';
    }

    public function label(): string
    {
        return 'The expected release is the one served';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $expected = $site->target->expectedRelease;

        if ($expected === null || $expected === '') {
            return SmokeCheck::skip($this->id(), $this->label(), 'No --expect-release given: which version answers is not verified.');
        }

        $health = $site->health();

        if ($health === null) {
            return SmokeCheck::fail($this->id(), $this->label(), 'The site did not answer.', 'Check that the site is up before reading anything else in this report.');
        }

        $served = $health->header('X-Release');

        if ($served === '') {
            return SmokeCheck::fail(
                $this->id(),
                $this->label(),
                'No X-Release header: the deployed version has no RELEASE file.',
                'Write the commit SHA into RELEASE at the root of the release, then run php artisan config:cache.',
            );
        }

        if ($served !== $expected) {
            return SmokeCheck::fail(
                $this->id(),
                $this->label(),
                "Serving {$served}, expected {$expected}.",
                'The switch to the new release did not happen, or a stale config cache is still in place: check the current release and rerun php artisan config:cache.',
            );
        }

        return SmokeCheck::pass($this->id(), $this->label(), "Serving {$served}.");
    }
}
