<?php

declare(strict_types=1);

namespace App\Services\Smoke\Site;

use DateTimeImmutable;

/**
 * Reads when the TLS certificate of a host expires.
 *
 * An interface because the certificate is read over a raw socket, which Http::fake() cannot
 * intercept: the smoke tests substitute a fake, and no test ever opens a real connection.
 */
interface CertificateInspector
{
    /**
     * Returns the expiry date, or null when no valid certificate could be read — connection refused,
     * handshake failed, certificate rejected. Null is a failure, not an unknown.
     */
    public function expiresAt(string $host, int $port): ?DateTimeImmutable;
}
