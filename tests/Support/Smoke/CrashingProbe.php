<?php

declare(strict_types=1);

namespace Tests\Support\Smoke;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;
use RuntimeException;

/**
 * A probe with a bug in it, for the one thing no real probe should ever do.
 *
 * It lives outside app/Services/Smoke/Probes/ on purpose: SmokeCatalogueTest fails on any class in
 * that folder missing from the catalogue, and this one must never be in the catalogue.
 */
final class CrashingProbe implements SmokeProbe
{
    public static string $message = 'Undefined array key "body"';

    public function id(): string
    {
        return 'crashing';
    }

    public function label(): string
    {
        return 'A probe that throws instead of answering';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        throw new RuntimeException(self::$message);
    }
}
