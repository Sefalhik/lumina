<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * Let's Encrypt renews by fetching a file under /.well-known/acme-challenge/. Behind Basic auth it
 * gets a 401, the renewal fails silently, and the certificate expires about ninety days later with
 * nothing having reported a problem.
 *
 * **What a 404 here proves, and what it does not.** The probe asks for a path that deliberately
 * does not exist, so the answer only says how the server treats an *unmatched* request under
 * /.well-known/. It proves the Basic auth exemption only as long as nothing rewrites that request
 * first — and on 2026-09-20 something did: the front controller turned it into an internal redirect
 * to /index.php, which Apache re-evaluated against <Location "/">, and the probe read the resulting
 * 401 as a missing exemption. The exemption was there all along (LUMN-61).
 *
 * public/.htaccess therefore excludes /.well-known/ from the rewrite, and AcmeChallengeRewriteTest
 * fails if that exclusion is removed: without it this probe silently stops measuring authorisation
 * and starts measuring routing, which is the harder failure to notice — it still goes red, for the
 * wrong reason, and sends the reader to the wrong panel.
 *
 * What it cannot prove either way is that a challenge file that *does* exist is served. That is the
 * request a renewal actually makes, and a smoke test cannot create a file on the host it probes.
 */
final class AcmeChallengeProbe implements SmokeProbe
{
    private const ACME_PATH = '/.well-known/acme-challenge/lumina-smoke';

    public function id(): string
    {
        return 'acme';
    }

    public function label(): string
    {
        return 'Certificate renewal can reach its challenge path';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        // Deliberately sent WITHOUT the Basic credentials: this probe exists to prove the path is
        // exempted from them. With the credentials, it would pass even if the exemption were gone.
        $response = $site->get(self::ACME_PATH, authenticate: false);
        $status = $response?->status();

        if ($status === 404) {
            return SmokeCheck::pass($this->id(), $this->label());
        }

        return SmokeCheck::fail(
            $this->id(),
            $this->label(),
            $status === 401
                ? 'The challenge path answered 401: Basic auth covers it, and the next renewal will fail.'
                : 'The challenge path answered '.($status ?? 'nothing').' instead of 404.',
            'Exempt /.well-known/acme-challenge/ from Basic auth (docs/deployment.md, "Preprod is behind HTTP Basic authentication").',
        );
    }
}
