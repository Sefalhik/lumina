<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Enums\SmokeSeverity;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;
use Carbon\CarbonImmutable;

/**
 * A renewal that stops working says nothing: the certificate simply expires, roughly ninety days
 * later, and every visitor meets a security warning. Two thresholds, because "expires in twelve
 * days" is something to plan, and "expires in two" is something to stop a deployment for.
 */
final class CertificateProbe implements SmokeProbe
{
    public function id(): string
    {
        return 'certificate';
    }

    public function label(): string
    {
        return 'The TLS certificate is valid and not about to expire';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        if (! $site->target->isHttps()) {
            return SmokeCheck::skip($this->id(), $this->label(), 'The target is not served over HTTPS.');
        }

        $remedy = 'Renew the certificate. On alwaysdata, check its Let\'s Encrypt renewal, and that /.well-known/acme-challenge/ is not behind Basic auth.';
        $expiry = $site->certificates->expiresAt($site->target->host(), $site->target->port());

        if ($expiry === null) {
            return SmokeCheck::fail($this->id(), $this->label(), 'No valid certificate could be read.', $remedy);
        }

        $days = (int) floor(($expiry->getTimestamp() - CarbonImmutable::now()->getTimestamp()) / 86400);
        $detail = "Expires in {$days} days ({$expiry->format('Y-m-d')}).";

        if ($days < (int) config('smoke.certificate_blocking_days')) {
            return SmokeCheck::fail($this->id(), $this->label(), $detail, $remedy);
        }

        if ($days < (int) config('smoke.certificate_warning_days')) {
            return SmokeCheck::fail($this->id(), $this->label(), $detail, $remedy, SmokeSeverity::Warning);
        }

        return SmokeCheck::pass($this->id(), $this->label(), $detail);
    }
}
