<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * The other half of the environment drift the E2E backdoor probe watches.
 *
 * Telescope is registered when APP_ENV is local and the package is installed (AppServiceProvider).
 * The very misnaming that publishes /e2e/admin-auth therefore publishes the Telescope console too —
 * one gives an admin session, the other reads every SQL query, exception, mail and session of the
 * site. Probing one and not the other watches half a door.
 */
final class TelescopeProbe implements SmokeProbe
{
    public function id(): string
    {
        return 'telescope';
    }

    public function label(): string
    {
        return 'The Telescope console is not served';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $response = $site->get('/telescope');

        if ($response?->status() === 404) {
            return SmokeCheck::pass($this->id(), $this->label());
        }

        return SmokeCheck::fail(
            $this->id(),
            $this->label(),
            '/telescope answered '.($response?->status() ?? 'nothing').' instead of 404.',
            'Debugging tooling is reachable. Check APP_ENV is production, and that composer install ran with --no-dev: laravel/telescope is a require-dev package and must not be on the server at all.',
        );
    }
}
