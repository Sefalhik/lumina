<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SetLocaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'i18n.supported_locales' => ['fr', 'en', 'de'],
            'i18n.default_locale' => 'fr',
        ]);
    }

    public function test_supported_locale_sets_app_locale(): void
    {
        $this->get('/fr');

        $this->assertSame('fr', app()->getLocale());
    }

    public function test_supported_locale_passes_request_to_next_handler(): void
    {
        $this->get('/fr')->assertOk();
        $this->get('/en')->assertOk();
        $this->get('/de')->assertOk();
    }

    public function test_url_defaults_include_lang_after_supported_locale(): void
    {
        $this->get('/fr');

        $this->assertSame('fr', url()->getDefaultParameters()['lang'] ?? null);
    }

    public function test_unsupported_locale_redirects_to_default_locale(): void
    {
        $response = $this->callMiddlewareWithLang('xx');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(url('/fr'), $response->headers->get('Location'));
    }

    public function test_null_lang_parameter_redirects_to_default_locale(): void
    {
        $response = $this->callMiddlewareWithLang(null);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(url('/fr'), $response->headers->get('Location'));
    }

    // -------------------------------------------------------------------------

    private function callMiddlewareWithLang(?string $lang): Response
    {
        $uri = $lang !== null ? "/{$lang}" : '/';
        $request = Request::create($uri);

        $request->setRouteResolver(function () use ($request, $lang) {
            $route = new Route('GET', $lang !== null ? '/{lang}' : '/', []);
            $route->bind($request);

            return $route;
        });

        return (new SetLocale)->handle($request, fn () => response('ok'));
    }
}
