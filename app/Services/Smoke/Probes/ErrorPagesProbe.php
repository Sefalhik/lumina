<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * A 404 cannot reveal APP_DEBUG: Laravel ships a page for it and renders that page even in debug
 * mode — measured on 2026-09-19, a 404-only probe passed on a machine with APP_DEBUG=true. A 405 has
 * no such page, so debug mode prints the whole stack trace. PATCH on a GET-only page produces one,
 * and the router refuses it before any controller runs: still read-only.
 */
final class ErrorPagesProbe implements SmokeProbe
{
    /** What an error page shows when APP_DEBUG leaks into production. */
    private const DEBUG_MARKERS = ['Stack trace', 'Whoops', 'vendor/laravel/framework', 'Illuminate\\'];

    private const MISSING_PATH = '/fr/lumina-smoke-missing-page';

    public function id(): string
    {
        return 'errors';
    }

    public function label(): string
    {
        return 'Errors answer with the right status, and without debug output';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $missing = $site->get(self::MISSING_PATH);

        if ($missing?->status() !== 404) {
            return SmokeCheck::fail(
                $this->id(),
                $this->label(),
                self::MISSING_PATH.' answered '.($missing?->status() ?? 'nothing').' instead of 404.',
                'Read storage/logs/laravel.log: an error page that is not a 404 means the error handler itself fails.',
            );
        }

        $refused = $site->request('patch', '/fr');
        foreach (self::DEBUG_MARKERS as $marker) {
            if (str_contains($refused?->body() ?? '', $marker)) {
                return SmokeCheck::fail(
                    $this->id(),
                    $this->label(),
                    'An error page shows a stack trace: APP_DEBUG is on.',
                    'Set APP_DEBUG=false in .env, then php artisan config:cache.',
                );
            }
        }

        return SmokeCheck::pass($this->id(), $this->label());
    }
}
