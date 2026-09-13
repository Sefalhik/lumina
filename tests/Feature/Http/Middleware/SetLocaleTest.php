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

    // ── Forgetting the {lang} parameter ───────────────────────────────────────

    public function test_lang_is_removed_from_the_route_parameters(): void
    {
        // Laravel hands route parameters to a handler positionally. Leaving
        // {lang} in place means it arrives first, ahead of anything the route
        // actually carries.
        $request = Request::create('/fr/blog/mon-article');

        $route = new Route('GET', '/{lang}/blog/{slug}', []);
        $route->bind($request);

        // Bound once, outside the resolver: re-binding on every call would
        // restore the parameter and the assertion would test nothing.
        $request->setRouteResolver(fn () => $route);

        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertArrayNotHasKey('lang', $route->parameters());
        $this->assertSame('mon-article', $route->parameter('slug'));
    }

    public function test_a_route_parameter_reaches_its_handler(): void
    {
        // Regression: blog.show is `fn (string $slug)` on a /{lang}/ prefixed
        // route. Before {lang} was forgotten the closure received 'fr' as its
        // slug, and nothing noticed because the view ignores the value. It would
        // have surfaced the day the blog engine started using it.
        $this->get('/fr/blog/mon-article')
            ->assertOk()
            ->assertViewHas('slug', 'mon-article');
    }

    public function test_url_generation_still_carries_the_locale(): void
    {
        // The parameter is dropped, so route() has to get the locale from the
        // URL default instead. Without it every generated link would lose its
        // language segment.
        $this->get('/de');

        $this->assertStringEndsWith('/de', route('home'));
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
