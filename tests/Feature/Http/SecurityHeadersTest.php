<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\RebootsInEnvironment;
use Tests\TestCase;

/**
 * The assembly of LUMN-37: that the middleware really runs, on every kind of response. Every case
 * of the headers themselves is proven in Tests\Unit\Services\Http\SecurityHeadersTest.
 *
 * Requests address an explicit http:// or https:// root. $this->get('/path') builds its URL from
 * APP_URL, which is https:// — a test of the HTTP case written that way would pass with the
 * middleware deleted (docs/testing-conventions.md, boot-time decisions).
 */
class SecurityHeadersTest extends TestCase
{
    use RebootsInEnvironment;
    use RefreshDatabase;

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options',
        'Referrer-Policy',
        'Permissions-Policy',
        'X-Frame-Options',
        'Content-Security-Policy-Report-Only',
    ];

    private function assertSecurityHeaders(TestResponse $response): void
    {
        foreach (self::SECURITY_HEADERS as $header) {
            $response->assertHeader($header);
        }
    }

    private function signInAsAdmin(): void
    {
        Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create([
            'password' => Hash::make('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->withSession(['auth.two_factor_verified' => true]);
    }

    public function test_a_public_page_carries_them(): void
    {
        $this->assertSecurityHeaders($this->get('https://localhost/fr')->assertOk());
    }

    public function test_an_administration_page_carries_them(): void
    {
        $this->signInAsAdmin();

        $this->assertSecurityHeaders($this->get('https://localhost/fr/admin/homepage')->assertOk());
    }

    public function test_a_redirect_carries_them(): void
    {
        // An anonymous visitor sent to the login page: the response is a redirect, not a page.
        $this->assertSecurityHeaders($this->get('https://localhost/fr/admin/homepage')->assertRedirect());
    }

    public function test_a_missing_page_carries_them(): void
    {
        $this->assertSecurityHeaders($this->get('https://localhost/fr/lumina-no-such-page')->assertNotFound());
    }

    public function test_a_server_error_carries_them(): void
    {
        Route::get('/lumina-test-boom', fn () => throw new RuntimeException('boom'));

        $response = $this->get('https://localhost/lumina-test-boom');

        $response->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);
        $this->assertSecurityHeaders($response);
    }

    public function test_a_maintenance_page_carries_them(): void
    {
        // Refused by a global middleware before the router: only an outermost middleware sees this
        // response. Registered with append() instead of prepend(), this test fails.
        $this->app->maintenanceMode()->activate([]);

        try {
            $response = $this->get('https://localhost/fr');

            $response->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE);
            $this->assertSecurityHeaders($response);
            $response->assertHeader('Strict-Transport-Security');
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    public function test_the_central_policy_wins_over_a_header_a_route_set_itself(): void
    {
        // One place decides. A route that loosened framing for itself would do it silently, and
        // nothing would review it: the middleware overwrites, and says so here.
        Route::get('/lumina-test-framed', fn () => response('framed')->header('X-Frame-Options', 'SAMEORIGIN'));

        $this->get('https://localhost/lumina-test-framed')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_the_health_route_carries_them(): void
    {
        $this->assertSecurityHeaders($this->get('https://localhost/up')->assertOk());
    }

    public function test_an_api_route_carries_them(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['status' => 'success', 'city' => 'Lyon'])]);

        $response = $this->get('https://localhost/api/geo');

        $this->assertLessThan(500, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
    }

    public function test_hsts_is_sent_over_https(): void
    {
        $this->get('https://localhost/fr')
            ->assertHeader('Strict-Transport-Security', 'max-age=86400; includeSubDomains');
    }

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        $response = $this->get('http://localhost/fr')->assertOk();

        // Negative control: the other headers are there, so the absence is HSTS's own.
        $this->assertSecurityHeaders($response);
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_follows_the_configured_duration(): void
    {
        config(['security-headers.hsts.max_age' => 31536000]);

        $this->get('https://localhost/fr')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_hsts_leaves_subdomains_out_when_configured_to(): void
    {
        config(['security-headers.hsts.include_subdomains' => false]);

        $this->get('https://localhost/fr')->assertHeader('Strict-Transport-Security', 'max-age=86400');
    }

    public function test_a_missing_hsts_duration_fails_instead_of_disabling_hsts(): void
    {
        // Cast to an int, a missing key would read as 0 — and max-age=0 tells a browser to forget
        // HSTS. The request must fail rather than switch the protection off in silence.
        config(['security-headers.hsts.max_age' => null]);

        try {
            $this->get('https://localhost/fr');
            $this->fail('A request served without an HSTS duration.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('security-headers.hsts.max_age must be an integer.', $e->getMessage());
        }
    }

    public function test_a_site_that_is_not_indexable_says_so(): void
    {
        config(['seo.indexable' => false]);

        $this->get('https://localhost/fr')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_an_indexable_site_says_nothing(): void
    {
        config(['seo.indexable' => true]);

        $response = $this->get('https://localhost/fr');

        $this->assertSecurityHeaders($response);
        $response->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_the_decision_does_not_depend_on_the_environment(): void
    {
        // Preprod runs APP_ENV=production on purpose. If indexing were ever tied to the environment
        // again, one of these two would fail.
        $this->rebootIn('production');

        config(['seo.indexable' => false]);
        $this->get('https://localhost/up')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        config(['seo.indexable' => true]);
        $this->get('https://localhost/up')->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_the_policy_is_reported_and_never_enforced(): void
    {
        $this->get('https://localhost/fr')
            ->assertHeader('Content-Security-Policy-Report-Only')
            ->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_every_inline_script_carries_the_nonce_of_its_response(): void
    {
        $response = $this->get('https://localhost/fr')->assertOk();

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertSame(1, preg_match("/'nonce-([^']+)'/", $policy, $match));
        $nonce = $match[1];

        preg_match_all('/<script\b[^>]*>/i', (string) $response->getContent(), $scripts);
        // The layout has two at least: the anti-flash script and Ziggy's routes.
        $this->assertGreaterThanOrEqual(2, count($scripts[0]));

        foreach ($scripts[0] as $tag) {
            if (str_contains($tag, 'application/ld+json')) {
                continue; // a data block, never executed: CSP does not apply to it
            }
            $this->assertStringContainsString("nonce=\"{$nonce}\"", $tag, "Script without the response's nonce: {$tag}");
        }
    }

    public function test_the_login_page_scripts_carry_the_nonce(): void
    {
        $response = $this->get('https://localhost/fr/login')->assertOk();

        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy-Report-Only'), $match);
        preg_match_all('/<script\b[^>]*>/i', (string) $response->getContent(), $scripts);

        $this->assertNotEmpty($scripts[0]);
        foreach ($scripts[0] as $tag) {
            $this->assertStringContainsString("nonce=\"{$match[1]}\"", $tag);
        }
    }

    public function test_the_administration_scripts_carry_the_nonce(): void
    {
        $this->signInAsAdmin();
        $response = $this->get('https://localhost/fr/admin/homepage')->assertOk();

        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy-Report-Only'), $match);
        preg_match_all('/<script\b[^>]*>/i', (string) $response->getContent(), $scripts);

        $this->assertGreaterThanOrEqual(2, count($scripts[0]));
        foreach ($scripts[0] as $tag) {
            $this->assertStringContainsString("nonce=\"{$match[1]}\"", $tag);
        }
    }

    public function test_the_development_server_is_allowed_on_a_developer_machine_only(): void
    {
        // The hot file exists whenever `npm run dev` runs — on a developer's machine, but nothing
        // stops one from being left in a directory a test or a server reads. The environment
        // decides, not the file.
        $hot = (string) tempnam(sys_get_temp_dir(), 'lumina-hot-');
        file_put_contents($hot, "https://vite.lumina.test:5173\n");

        try {
            // The real Vite: Tests\TestCase swaps in a fake that never reports a hot file, which
            // would make the first half of this test pass with the environment check deleted.
            $this->withVite();
            Vite::useHotFile($hot);
            $this->assertTrue(Vite::isRunningHot());
            $policy = (string) $this->get('https://localhost/up')->headers->get('Content-Security-Policy-Report-Only');
            $this->assertStringNotContainsString('vite.lumina.test', $policy);

            $this->rebootIn('local');
            Vite::useHotFile($hot);
            $policy = (string) $this->get('https://localhost/up')->headers->get('Content-Security-Policy-Report-Only');
            $this->assertStringContainsString('connect-src', $policy);
            $this->assertStringContainsString('wss://vite.lumina.test:5173', $policy);
        } finally {
            unlink($hot);
        }
    }

    public function test_the_inline_styles_of_an_error_page_carry_the_nonce(): void
    {
        // Error pages do not go through the site's layouts but through Laravel's minimal one,
        // republished in resources/views/errors/ for this alone.
        Route::get('/lumina-test-boom', fn () => throw new RuntimeException('boom'));
        // What preprod and production serve: with debug on, a 500 renders the debug page instead.
        config(['app.debug' => false]);

        $pages = [
            'missing page' => $this->get('https://localhost/fr/lumina-no-such-page')->assertNotFound(),
            'server error' => $this->get('https://localhost/lumina-test-boom')->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR),
        ];

        foreach ($pages as $page => $response) {
            preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy-Report-Only'), $match);
            preg_match_all('/<style\b[^>]*>/i', (string) $response->getContent(), $styles);

            $this->assertCount(2, $styles[0], "The {$page} no longer renders Laravel's two inline styles.");
            foreach ($styles[0] as $tag) {
                $this->assertSame("<style nonce=\"{$match[1]}\">", $tag, "An inline style of the {$page} lacks the nonce.");
            }
        }
    }

    public function test_each_layout_hands_the_nonce_to_vite(): void
    {
        // Vite stamps what it injects itself — styles in development — with the nonce it reads
        // from this tag. Without it, those styles are reported on every page.
        $pages = ['public' => 'https://localhost/fr', 'auth' => 'https://localhost/fr/login'];
        foreach ($pages as $layout => $url) {
            $this->assertMetaCarriesTheNonce($this->get($url)->assertOk(), $layout);
        }

        $this->signInAsAdmin();
        $this->assertMetaCarriesTheNonce($this->get('https://localhost/fr/admin/homepage')->assertOk(), 'admin');
    }

    private function assertMetaCarriesTheNonce(TestResponse $response, string $layout): void
    {
        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy-Report-Only'), $match);

        $this->assertStringContainsString(
            "<meta property=\"csp-nonce\" nonce=\"{$match[1]}\">",
            (string) $response->getContent(),
            "The {$layout} layout does not hand the nonce to Vite.",
        );
    }

    public function test_the_nonce_changes_with_every_request(): void
    {
        $nonces = [];
        foreach (range(1, 3) as $ignored) {
            $policy = (string) $this->get('https://localhost/fr')->headers->get('Content-Security-Policy-Report-Only');
            preg_match("/'nonce-([^']+)'/", $policy, $match);
            $nonces[] = $match[1];
        }

        $this->assertCount(3, array_unique($nonces));
    }
}
