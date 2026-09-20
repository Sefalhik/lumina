<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Site;

use App\Services\Smoke\Site\StreamCertificateInspector;
use PHPUnit\Framework\TestCase;

/**
 * The parsing is tested on a certificate generated in memory. The connection itself is only tested
 * on its failure path, against a port nothing listens on: the project rule is no real network call
 * in a test, and the success path needs a trusted TLS server.
 */
class StreamCertificateInspectorTest extends TestCase
{
    public function test_it_reads_the_expiry_of_a_certificate(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'lumina.test'], $key);
        $this->assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 30);
        $this->assertNotFalse($certificate);

        $expiry = StreamCertificateInspector::expiryOf($certificate);

        $this->assertNotNull($expiry);
        $days = (int) round(($expiry->getTimestamp() - time()) / 86400);
        $this->assertSame(30, $days);
    }

    public function test_something_that_is_not_a_certificate_has_no_expiry(): void
    {
        $this->assertNull(@StreamCertificateInspector::expiryOf('not a certificate'));
    }

    public function test_an_unreachable_host_yields_no_certificate(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        $this->assertNull((new StreamCertificateInspector(1.0))->expiresAt('127.0.0.1', $port));
    }
}
