<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seo;

use App\Services\Seo\LocalizedUrlService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LocalizedUrlServiceTest extends TestCase
{
    private LocalizedUrlService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LocalizedUrlService(
            indexableLocales: ['fr', 'en', 'de', 'it', 'nl'],
            supportedLocales: ['bg', 'de', 'en', 'fr', 'it', 'mt', 'nl'],
            publicRoutes: ['home', 'cv', 'projects', 'blog', 'blog.show'],
        );
    }

    public function test_public_route_is_recognised(): void
    {
        $this->assertTrue($this->service->isPublic('home'));
        $this->assertTrue($this->service->isPublic('blog.show'));
    }

    public function test_auth_and_admin_routes_are_not_public(): void
    {
        $this->assertFalse($this->service->isPublic('login'));
        $this->assertFalse($this->service->isPublic('two-factor.setup'));
        $this->assertFalse($this->service->isPublic('admin.dashboard'));
        $this->assertFalse($this->service->isPublic('admin.homepage.edit'));
    }

    public function test_canonical_is_self_referencing(): void
    {
        $canonical = $this->service->canonical('home', ['lang' => 'de'], 'de');

        $this->assertNotNull($canonical);
        $this->assertStringEndsWith('/de', $canonical);
    }

    public function test_canonical_is_null_for_non_public_route(): void
    {
        $this->assertNull($this->service->canonical('login', ['lang' => 'fr'], 'fr'));
        $this->assertNull($this->service->canonical('admin.dashboard', ['lang' => 'fr'], 'fr'));
    }

    public function test_canonical_is_returned_for_non_indexed_locale(): void
    {
        // A Maltese page is distinct content, not a duplicate: it must still
        // declare a canonical, pointing at itself and never at the French page.
        $canonical = $this->service->canonical('home', ['lang' => 'mt'], 'mt');

        $this->assertNotNull($canonical);
        $this->assertStringEndsWith('/mt', $canonical);
    }

    public function test_alternates_cover_every_indexable_locale(): void
    {
        $alternates = $this->service->alternates('home', ['lang' => 'fr'], 'fr');

        $this->assertSame(
            ['fr', 'en', 'de', 'it', 'nl', 'x-default'],
            array_keys($alternates),
        );
    }

    public function test_alternates_include_the_current_locale_for_reciprocity(): void
    {
        $alternates = $this->service->alternates('home', ['lang' => 'de'], 'de');

        $this->assertArrayHasKey('de', $alternates);
        $this->assertStringEndsWith('/de', $alternates['de']);
    }

    public function test_alternates_preserve_route_parameters(): void
    {
        $alternates = $this->service->alternates(
            'blog.show',
            ['lang' => 'fr', 'slug' => 'guzzle-8-major-bump'],
            'fr',
        );

        $this->assertStringEndsWith('/it/blog/guzzle-8-major-bump', $alternates['it']);
        $this->assertStringEndsWith('/nl/blog/guzzle-8-major-bump', $alternates['nl']);
    }

    public function test_x_default_points_to_the_root(): void
    {
        $alternates = $this->service->alternates('home', ['lang' => 'fr'], 'fr');

        $this->assertSame(url('/'), $alternates['x-default']);
    }

    public function test_alternates_are_empty_for_non_public_route(): void
    {
        $this->assertSame([], $this->service->alternates('login', ['lang' => 'fr'], 'fr'));
        $this->assertSame([], $this->service->alternates('admin.dashboard', ['lang' => 'fr'], 'fr'));
    }

    public function test_alternates_are_empty_for_non_indexed_locale(): void
    {
        // hreflang requires reciprocity: since no indexed page references the
        // Maltese one, the Maltese page must not reference the indexed set.
        $this->assertSame([], $this->service->alternates('home', ['lang' => 'mt'], 'mt'));
    }

    public function test_indexable_locales_outside_supported_are_ignored_and_logged(): void
    {
        /** @var list<MessageLogged> $captured */
        $captured = [];
        Log::listen(function (MessageLogged $event) use (&$captured): void {
            $captured[] = $event;
        });

        $service = new LocalizedUrlService(
            indexableLocales: ['fr', 'en', 'xx'],
            supportedLocales: ['fr', 'en'],
            publicRoutes: ['home'],
        );

        $alternates = $service->alternates('home', ['lang' => 'fr'], 'fr');

        $this->assertSame(['fr', 'en', 'x-default'], array_keys($alternates));

        $match = array_filter($captured, fn ($e) => $e->level === 'warning'
            && $e->message === 'Indexable locales contain unsupported entries'
            && ($e->context['ignored'] ?? null) === ['xx']
        );
        $this->assertNotEmpty($match, 'Expected warning log was not emitted');
    }
}
