<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\LocaleResolver;
use Illuminate\Http\Request;
use Tests\TestCase;

class LocaleResolverTest extends TestCase
{
    private LocaleResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new LocaleResolver(
            supported: ['fr', 'en', 'de', 'es'],
            default: 'fr',
        );
    }

    public function test_returns_matched_locale_from_accept_language(): void
    {
        $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9,en;q=0.8']);

        $this->assertSame('de', $this->resolver->resolve($request));
    }

    public function test_returns_default_when_no_accept_language_header(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => '']);

        $this->assertSame('fr', $this->resolver->resolve($request));
    }

    public function test_returns_default_when_no_supported_locale_matches(): void
    {
        $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'zh-CN,zh;q=0.9']);

        $this->assertSame('fr', $this->resolver->resolve($request));
    }

    public function test_respects_quality_factor_ordering(): void
    {
        $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'en;q=0.5,de;q=0.9']);

        $this->assertSame('de', $this->resolver->resolve($request));
    }

    public function test_matches_language_subtag_to_supported_base_locale(): void
    {
        $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-CH,fr;q=0.9']);

        $this->assertSame('fr', $this->resolver->resolve($request));
    }

    public function test_uses_custom_default_when_nothing_matches(): void
    {
        $resolver = new LocaleResolver(supported: ['de', 'es'], default: 'es');
        $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'zh']);

        $this->assertSame('es', $resolver->resolve($request));
    }
}
