<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hosting provider terminates TLS at a front proxy, so PHP only ever sees a
 * plain HTTP request carrying `X-Forwarded-Proto: https`.
 *
 * Without `trustProxies` the application believes it serves HTTP. The visible
 * symptom is `http://` links on an `https://` page — including in the canonical
 * and hreflang tags, which is the SEO work of LUMN-9 quietly undone. The
 * expensive symptom is `SESSION_SECURE_COOKIE=true` producing a cookie the
 * browser refuses to send back, which presents as an endless login loop rather
 * than as a configuration problem.
 *
 * Every request below is addressed with an explicit `http://` root, and that is
 * load-bearing. `$this->get('/fr')` builds its URL from `APP_URL`, which is
 * `https://…`: the request would already be secure, and all of these assertions
 * would pass with `trustProxies` deleted entirely. The negative control at the
 * bottom is what caught that while this file was being written.
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    private const PLAIN_ROOT = 'http://localhost';

    private function registerProbe(): void
    {
        $this->app['router']
            ->get('/_trusted-proxy-probe', fn (): string => request()->isSecure() ? 'secure' : 'plain')
            ->middleware('web');
    }

    public function test_a_forwarded_proto_makes_a_plain_request_secure(): void
    {
        $this->registerProbe();

        $this->get(self::PLAIN_ROOT.'/_trusted-proxy-probe', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertSee('secure');
    }

    /**
     * The negative control. Without it, an application that considered every
     * request secure — because `APP_URL` says so, or because a middleware forced
     * it — would pass the test above while proving nothing.
     */
    public function test_without_the_header_a_plain_request_stays_plain(): void
    {
        $this->registerProbe();

        $this->get(self::PLAIN_ROOT.'/_trusted-proxy-probe')
            ->assertOk()
            ->assertSee('plain');
    }

    /**
     * The consequence that actually reaches a visitor: the canonical tag is an
     * absolute URL, and one announcing `http://` on an `https://` page points at
     * an address the site does not serve.
     */
    public function test_the_canonical_tag_is_https_behind_a_forwarded_proto(): void
    {
        $this->get(self::PLAIN_ROOT.'/fr', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://', false)
            ->assertDontSee('<link rel="canonical" href="http://', false);
    }

    public function test_the_canonical_tag_is_plain_without_the_header(): void
    {
        $this->get(self::PLAIN_ROOT.'/fr')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="http://', false);
    }

    public function test_hreflang_alternates_are_https_behind_a_forwarded_proto(): void
    {
        $this->get(self::PLAIN_ROOT.'/fr', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertSee('hreflang="en" href="https://', false)
            ->assertDontSee('hreflang="en" href="http://', false);
    }

    public function test_a_forwarded_host_is_honoured(): void
    {
        $this->get(self::PLAIN_ROOT.'/fr', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'preprod.cardascia-it.org',
        ])
            ->assertOk()
            ->assertSee('https://preprod.cardascia-it.org/fr', false);
    }
}
