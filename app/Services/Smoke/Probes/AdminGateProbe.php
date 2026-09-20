<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * The admin area sits behind auth → role:admin → two_factor_verified. This probe asks for it
 * unauthenticated and requires a redirect to the login page — a 200 would mean the chain is gone, a
 * 500 that it is broken.
 */
final class AdminGateProbe implements SmokeProbe
{
    public function id(): string
    {
        return 'admin-gate';
    }

    public function label(): string
    {
        return 'The admin area requires a login';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $response = $site->get('/fr/admin');
        $location = (string) parse_url($response?->header('Location') ?? '', PHP_URL_PATH);

        return $response !== null && $response->redirect() && str_ends_with($location, '/fr/login')
            ? SmokeCheck::pass($this->id(), $this->label())
            : SmokeCheck::fail(
                $this->id(),
                $this->label(),
                '/fr/admin answered '.($response?->status() ?? 'nothing').($location !== '' ? " towards {$location}" : '').'.',
                'The auth → role:admin → two_factor_verified chain is broken: check routes/web.php and bootstrap/app.php.',
            );
    }
}
