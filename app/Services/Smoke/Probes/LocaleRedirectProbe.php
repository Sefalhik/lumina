<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * The root has no content of its own: LocaleResolver reads Accept-Language and redirects. A visitor
 * landing on a language they did not ask for is the kind of defect nobody reports.
 */
final class LocaleRedirectProbe implements SmokeProbe
{
    public function id(): string
    {
        return 'locale';
    }

    public function label(): string
    {
        return "The root redirects to the visitor's language";
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $response = $site->get('/', headers: ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.5']);
        $location = (string) parse_url($response?->header('Location') ?? '', PHP_URL_PATH);

        return $response !== null && $response->redirect() && $location === '/de'
            ? SmokeCheck::pass($this->id(), $this->label())
            : SmokeCheck::fail(
                $this->id(),
                $this->label(),
                '/ with Accept-Language: de answered '.($response?->status() ?? 'nothing').($location !== '' ? " towards {$location}" : '').', expected a redirect to /de.',
                'LocaleResolver no longer honours Accept-Language: check the root route in routes/web.php.',
            );
    }
}
