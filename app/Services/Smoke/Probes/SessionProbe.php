<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * SESSION_DRIVER=database needs the sessions table: without it the login page renders, sets no
 * session, and no one can ever log in. The cookies and the CSRF field together prove the store
 * works, without attempting a login — a wrong password would eat into the rate limit of LUMN-36.
 */
final class SessionProbe implements SmokeProbe
{
    public function id(): string
    {
        return 'session';
    }

    public function label(): string
    {
        return 'The login page starts a session';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $response = $site->get('/fr/login');

        if ($response?->status() !== 200) {
            return SmokeCheck::fail($this->id(), $this->label(), '/fr/login answered '.($response?->status() ?? 'nothing').'.', 'Read storage/logs/laravel.log.');
        }

        // Through the PSR-7 response, because header lookup there is case-insensitive: preprod
        // answers in HTTP/2, where every header name is lower-cased, and headers()['Set-Cookie']
        // finds nothing. Measured against the real preprod on 2026-09-20 — the fake site used in
        // the tests was kinder than reality.
        $cookies = implode("\n", $response->toPsrResponse()->getHeader('Set-Cookie'));
        $hasSession = preg_match('/^[^=\s]*session=/mi', $cookies) === 1;
        $hasXsrf = str_contains($cookies, 'XSRF-TOKEN=');
        $hasToken = str_contains($response->body(), 'name="_token"');

        return $hasSession && $hasXsrf && $hasToken
            ? SmokeCheck::pass($this->id(), $this->label())
            : SmokeCheck::fail(
                $this->id(),
                $this->label(),
                'Missing: '.implode(', ', array_keys(array_filter(['session cookie' => ! $hasSession, 'XSRF-TOKEN cookie' => ! $hasXsrf, 'CSRF token field' => ! $hasToken]))).'.',
                'The session store is not working: SESSION_DRIVER=database needs the sessions table (php artisan migrate --force).',
            );
    }
}
