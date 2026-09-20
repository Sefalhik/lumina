<?php

declare(strict_types=1);

namespace Tests\Support\Smoke;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;
use DateTimeZone;

/**
 * A probe the container cannot build: it fails before it can even say its own name, which is why
 * the orchestrator catches the resolution and the check separately.
 */
final class UnresolvableProbe implements SmokeProbe
{
    public function __construct(private readonly DateTimeZone $whatTheContainerCannotGuess) {}

    public function id(): string
    {
        return 'unresolvable';
    }

    public function label(): string
    {
        return 'A probe that cannot be built';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        // It would work perfectly well — if only the container could build it.
        return SmokeCheck::pass($this->id(), $this->whatTheContainerCannotGuess->getName());
    }
}
