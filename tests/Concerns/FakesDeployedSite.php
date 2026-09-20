<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Services\Smoke\Site\CertificateInspector;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\Site\SmokeTarget;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A deployed site, faked at the HTTP layer, for the smoke tests (LUMN-49).
 *
 * It answers every request the smoke test makes the way a healthy preprod does — measured against
 * the real one — and each test breaks one route and nothing else. Any request it does not know
 * throws (Http::preventStrayRequests): a probe calling an unexpected URL fails loudly, and no test
 * can reach the network.
 */
trait FakesDeployedSite
{
    protected const SITE = 'https://lumina.test';

    protected const SITE_RELEASE = 'abc123';

    /**
     * @param  array<string, Closure(Request): mixed>  $overrides  routes to break, keyed "METHOD url"
     */
    protected function fakeDeployedSite(array $overrides = [], ?DateTimeImmutable $certificateExpiry = null, bool $noCertificate = false): void
    {
        Http::preventStrayRequests();

        $routes = [...$this->healthySiteRoutes(), ...$overrides];
        Http::fake(function (Request $request) use ($routes) {
            $route = $routes[$request->method().' '.$request->url()] ?? null;

            return $route !== null ? $route($request) : null;
        });

        $expiry = $noCertificate ? null : ($certificateExpiry ?? CarbonImmutable::now()->addDays(90));
        $this->app->instance(CertificateInspector::class, new class($expiry) implements CertificateInspector
        {
            public function __construct(private readonly ?DateTimeImmutable $expiry) {}

            public function expiresAt(string $host, int $port): ?DateTimeImmutable
            {
                return $this->expiry;
            }
        });
    }

    /**
     * The faked site, ready for a probe: `(new XxxProbe)->check($this->site([...]))`.
     *
     * @param  array<string, Closure(Request): mixed>  $overrides  routes to break, keyed "METHOD url"
     */
    protected function site(
        array $overrides = [],
        ?DateTimeImmutable $certificateExpiry = null,
        bool $noCertificate = false,
        ?SmokeTarget $target = null,
    ): DeployedSite {
        $this->fakeDeployedSite($overrides, $certificateExpiry, $noCertificate);

        return new DeployedSite(
            $target ?? new SmokeTarget(self::SITE, self::SITE_RELEASE),
            $this->app->make(CertificateInspector::class),
        );
    }

    protected static function deployedPage(string $locale, string $path, string $bio): string
    {
        $site = self::SITE;

        return <<<HTML
            <!doctype html><html lang="{$locale}"><head>
            <link rel="canonical" href="{$site}{$path}">
            <link rel="preload" as="style" href="{$site}/build/assets/app-1.css">
            <link rel="stylesheet" href="{$site}/build/assets/app-1.css">
            <script type="module" src="{$site}/build/assets/app-2.js"></script>
            </head><body><p data-smoke="bio" class="lead">{$bio}</p></body></html>
            HTML;
    }

    /**
     * @return array<string, Closure(Request): mixed>
     */
    protected function healthySiteRoutes(): array
    {
        $site = self::SITE;
        $routes = [
            "GET {$site}/up" => fn () => Http::response('OK', 200, ['X-Release' => self::SITE_RELEASE]),
            "GET {$site}/build/assets/app-1.css" => fn () => Http::response('body{}', 200, ['Content-Type' => 'text/css']),
            "GET {$site}/build/assets/app-2.js" => fn () => Http::response('export{}', 200, ['Content-Type' => 'application/javascript']),
            "GET {$site}/telescope" => fn () => Http::response('Not Found', 404),
            "GET {$site}/api/geo" => fn () => Http::response(['country_code' => 'FR', 'city' => 'Toulouse']),
            "GET {$site}/fr/lumina-smoke-missing-page" => fn () => Http::response('Not Found', 404),
            "PATCH {$site}/fr" => fn () => Http::response('Method Not Allowed', 405),
            "GET {$site}/fr/admin" => fn () => Http::response('', 302, ['Location' => "{$site}/fr/login"]),
            // Lower-case header name on purpose: preprod answers in HTTP/2, where header names are
            // lower-cased, and a fake using the canonical casing hid a real defect in the probe.
            "GET {$site}/fr/login" => fn () => Http::response('<form><input type="hidden" name="_token" value="t"></form>', 200, [
                'set-cookie' => ['XSRF-TOKEN=x; path=/', 'cardascia-it-session=y; path=/; httponly'],
            ]),
            "GET {$site}/" => fn (Request $request) => Http::response('', 302, [
                'Location' => str_contains($request->header('Accept-Language')[0] ?? '', 'de') ? "{$site}/de" : "{$site}/fr",
            ]),
            'GET http://lumina.test/fr' => fn () => Http::response('', 301, ['Location' => "{$site}/fr"]),
            "GET {$site}/.well-known/acme-challenge/lumina-smoke" => fn () => Http::response('Not Found', 404),
        ];

        foreach (['/.env', '/.git/HEAD', '/composer.json', '/vendor/autoload.php'] as $path) {
            $routes["GET {$site}{$path}"] = fn () => Http::response('Not Found', 404);
        }
        foreach (['/e2e/admin-auth', '/e2e/homepage-content', '/e2e/site-identity'] as $path) {
            $routes["GET {$site}{$path}"] = fn () => Http::response('Not Found', 404);
        }
        // The pages are derived from the SEO allowlist, so the fake derives its routes the same way
        // rather than repeating a list that would then have to be remembered twice.
        foreach (DeployedSite::publicPaths() as $path) {
            $locale = ltrim(explode('/', ltrim($path, '/'))[0], '/');
            $routes["GET {$site}{$path}"] = fn () => Http::response(self::deployedPage($locale, $path, "Biographie {$locale}"));
        }

        return $routes;
    }
}
