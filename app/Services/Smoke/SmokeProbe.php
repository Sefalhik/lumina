<?php

declare(strict_types=1);

namespace App\Services\Smoke;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;

/**
 * One smoke probe: one question asked of a deployed environment, one answer (LUMN-49).
 *
 * A probe is stateless and read-only. It receives the site under test, asks it what it needs, and
 * returns a single result. It never knows about the other probes, and never writes anything.
 */
interface SmokeProbe
{
    /** Stable identifier, used in reports and in CI output. */
    public function id(): string;

    /** What the probe asserts, phrased as the sentence a passing report should read as. */
    public function label(): string;

    public function check(DeployedSite $site): SmokeCheck;
}
