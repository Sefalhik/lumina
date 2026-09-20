<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * /up proves more than "Laravel boots": a listener makes it query the database, so a 200 here means
 * the application and its most likely point of failure both answer.
 *
 * A failure is attributed before it is reported. The listener makes the database the first suspect
 * of a 500, but it explains nothing about a 503 served by Apache alone, a 401 from the Basic auth in
 * front of the site, or a 404 from a document root pointing at the wrong directory — and a confident
 * wrong lead costs more at three in the morning than no lead at all.
 */
final class HealthProbe implements SmokeProbe
{
    public function id(): string
    {
        return 'health';
    }

    public function label(): string
    {
        return 'The application and its database answer';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $health = $site->health();

        if ($health === null) {
            return SmokeCheck::fail($this->id(), $this->label(), '/up did not answer after the warm-up.', 'Check that the site is up, then read storage/logs/laravel.log.');
        }

        $status = $health->status();

        if ($status === 200) {
            return SmokeCheck::pass($this->id(), $this->label());
        }

        // The warm-up already retried a 5xx, so anything arriving here is a persistent failure.
        [$detail, $remedy] = match (true) {
            in_array($status, [502, 503, 504], true) => [
                "/up answered {$status}: a gateway answer, not the application's.",
                'The request did not reach PHP, or the site is in maintenance mode. Check PHP-FPM and the Apache configuration in the alwaysdata panel, and the site error log — not the database.',
            ],
            in_array($status, [401, 403], true) => [
                "/up answered {$status}: the request was refused before the application answered.",
                'Basic auth stands in front of /up: set SMOKE_BASIC_USER and SMOKE_BASIC_PASSWORD, or exempt /up in the alwaysdata panel.',
            ],
            $status === 404 => [
                '/up answered 404: the health route is not served.',
                'Check that the document root points at public/ and that health: "/up" is still registered in bootstrap/app.php.',
            ],
            default => [
                "/up answered {$status}: the application answered and refused.",
                'A listener makes /up query the database, so that is the first suspect: check the DB_* values in .env and run php artisan about. The site error log carries the exception.',
            ],
        };

        return SmokeCheck::fail($this->id(), $this->label(), $detail, $remedy);
    }
}
