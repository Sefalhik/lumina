<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * The missing public/.htaccess of 2026-09-14 left only "/" answering and every other route in 404;
 * the unguarded Telescope provider of the same day made every page a 500. Which of the two it is
 * shows in the pattern: some pages failing, or all of them.
 */
final class PublicPagesProbe implements SmokeProbe
{
    public function id(): string
    {
        return 'pages';
    }

    public function label(): string
    {
        return 'Public pages answer';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $failures = [];
        foreach ($site->pages() as $path => $response) {
            if ($response?->status() !== 200) {
                $failures[] = $path.' → '.($response?->status() ?? 'no answer');
            }
        }

        return $failures === []
            ? SmokeCheck::pass($this->id(), $this->label(), implode(', ', array_keys($site->pages())))
            : SmokeCheck::fail(
                $this->id(),
                $this->label(),
                implode('; ', $failures),
                'If only some pages fail, routing is broken — check public/.htaccess. If all do, the application does not boot — read storage/logs/laravel.log.',
            );
    }
}
