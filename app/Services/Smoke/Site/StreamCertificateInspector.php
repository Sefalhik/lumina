<?php

declare(strict_types=1);

namespace App\Services\Smoke\Site;

use DateTimeImmutable;
use OpenSSLCertificate;

/**
 * Reads a certificate the way a browser would meet it: SNI on, peer verification on.
 *
 * Verification stays on deliberately. A certificate that does not verify — expired, wrong host,
 * broken chain — cannot be read here, and the smoke check reports it as a blocking failure. That is
 * the right answer: a visitor would see the same refusal.
 */
final class StreamCertificateInspector implements CertificateInspector
{
    public function __construct(private readonly float $timeoutSeconds = 10.0) {}

    public function expiresAt(string $host, int $port): ?DateTimeImmutable
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        // A refused connection or a failed handshake is an expected answer here, not an error: the
        // PHP warning it raises is swallowed, and the null return carries the verdict.
        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client(
                "ssl://{$host}:{$port}",
                $errorCode,
                $errorMessage,
                $this->timeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $context,
            );
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            return null;
        }

        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);

        return $certificate instanceof OpenSSLCertificate ? self::expiryOf($certificate) : null;
    }

    /**
     * Extracted so the parsing is testable on a certificate generated in memory, with no network.
     */
    public static function expiryOf(OpenSSLCertificate|string $certificate): ?DateTimeImmutable
    {
        $parsed = openssl_x509_parse($certificate);

        if (! is_array($parsed) || ! isset($parsed['validTo_time_t']) || ! is_int($parsed['validTo_time_t'])) {
            return null;
        }

        return (new DateTimeImmutable)->setTimestamp($parsed['validTo_time_t']);
    }
}
