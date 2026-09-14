<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * No proxy is trusted, and these assertions exist to keep it that way.
 *
 * This file previously asserted the opposite. It was written on 2026-09-14
 * alongside `trustProxies(at: '*')`, under the assumption that the host
 * terminated TLS upstream and handed PHP a plain HTTP request. The first
 * deployment measured the host and the assumption was wrong:
 *
 *   HTTPS                   = on                      Apache sets it itself
 *   REMOTE_ADDR             = the visitor's real IP    mod_remoteip runs upstream
 *   X-Forwarded-Proto       = https                   overwritten by the proxy
 *   X-Forwarded-For / -Host = whatever the client sent, verbatim
 *
 * With `REMOTE_ADDR` already the visitor, trusting "any proxy" means trusting the
 * visitor: `$request->ip()` becomes whatever they put in `X-Forwarded-For`, and
 * `$request->getHost()` whatever they put in `X-Forwarded-Host`. The second is
 * host header poisoning — every absolute URL the application builds would carry an
 * attacker-chosen host.
 *
 * The lesson these tests now carry: the old ones passed, and proved a belief about
 * the environment rather than a behaviour of it.
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    private const PLAIN_ROOT = 'http://localhost';

    private const SPOOFED = [
        'X-Forwarded-For' => '1.2.3.4',
        'X-Forwarded-Host' => 'evil.example',
        'X-Forwarded-Proto' => 'https',
    ];

    /**
     * The structural guard. Everything below follows from it, and it is the one
     * assertion that fails the moment someone adds `trustProxies()` back.
     */
    public function test_no_proxy_is_trusted(): void
    {
        // The request is what makes this assertable: TrustProxies calls
        // Request::setTrustedProxies() while handling one, not at boot. Asserting on
        // the static without sending a request reads an empty list no matter how the
        // application is configured — a test that cannot fail. This one did, until a
        // mutation restoring trustProxies() left it green.
        $this->get(self::PLAIN_ROOT.'/fr')->assertOk();

        $this->assertEmpty(
            Request::getTrustedProxies(),
            'A trusted proxy is configured: forwarded headers become caller-controlled.',
        );
    }

    /**
     * The one that matters. A visitor must not be able to choose the host the
     * application uses to build its absolute URLs.
     */
    public function test_a_forwarded_host_cannot_change_the_generated_host(): void
    {
        $this->app['router']
            ->get('/_proxy-probe', fn (): string => request()->getHost())
            ->middleware('web');

        $this->get(self::PLAIN_ROOT.'/_proxy-probe', self::SPOOFED)
            ->assertOk()
            ->assertSee('localhost')
            ->assertDontSee('evil.example');
    }

    /** Same thing where a visitor would actually notice it. */
    public function test_the_canonical_tag_cannot_be_poisoned(): void
    {
        $this->get(self::PLAIN_ROOT.'/fr', self::SPOOFED)
            ->assertOk()
            ->assertDontSee('evil.example', false)
            ->assertSee('<link rel="canonical" href="http://localhost/fr">', false);
    }

    /** `$request->ip()` must stay the peer, whatever the caller claims. */
    public function test_a_forwarded_for_cannot_change_the_client_address(): void
    {
        $this->app['router']
            ->get('/_proxy-probe', fn (): string => (string) request()->ip())
            ->middleware('web');

        $this->get(self::PLAIN_ROOT.'/_proxy-probe', self::SPOOFED)
            ->assertOk()
            ->assertDontSee('1.2.3.4');
    }

    public function test_a_forwarded_proto_does_not_make_a_plain_request_secure(): void
    {
        $this->app['router']
            ->get('/_proxy-probe', fn (): string => request()->isSecure() ? 'secure' : 'plain')
            ->middleware('web');

        $this->get(self::PLAIN_ROOT.'/_proxy-probe', self::SPOOFED)
            ->assertOk()
            ->assertSee('plain');
    }

    /**
     * The counterpart, and the reason removing `trustProxies` breaks nothing: the
     * host sets `HTTPS=on` itself, which Laravel reads without trusting anyone.
     * Without this test, "ignore every forwarded header" could be satisfied by an
     * application that never detects HTTPS at all.
     */
    public function test_a_request_the_server_marks_secure_is_still_secure(): void
    {
        $this->app['router']
            ->get('/_proxy-probe', fn (): string => request()->isSecure() ? 'secure' : 'plain')
            ->middleware('web');

        $this->get('https://localhost/_proxy-probe')
            ->assertOk()
            ->assertSee('secure');
    }

    public function test_the_canonical_is_https_on_a_request_the_server_marks_secure(): void
    {
        $this->get('https://localhost/fr')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://localhost/fr">', false);
    }
}
