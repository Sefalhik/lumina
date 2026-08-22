<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Tests\TestCase;

class HreflangTest extends TestCase
{
    public function test_public_page_carries_a_self_referencing_canonical(): void
    {
        $this->get('/fr/')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/fr').'">', false);
    }

    public function test_public_page_declares_every_indexable_locale(): void
    {
        $response = $this->get('/fr/')->assertOk();

        foreach (['fr', 'en', 'de', 'it', 'nl'] as $locale) {
            $response->assertSee('hreflang="'.$locale.'"', false);
        }

        $response->assertSee('hreflang="x-default"', false);
    }

    public function test_non_indexable_locale_is_never_declared(): void
    {
        $this->get('/fr/')
            ->assertOk()
            ->assertDontSee('hreflang="mt"', false)
            ->assertDontSee('hreflang="bg"', false);
    }

    public function test_x_default_points_to_the_root(): void
    {
        $this->get('/fr/')
            ->assertOk()
            ->assertSee('<link rel="alternate" hreflang="x-default" href="'.url('/').'">', false);
    }

    public function test_tags_are_present_on_every_public_page(): void
    {
        foreach (['/fr/cv', '/en/projects', '/de/blog'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('rel="canonical"', false)
                ->assertSee('hreflang="x-default"', false);
        }
    }

    public function test_alternates_preserve_route_parameters(): void
    {
        $this->get('/fr/blog/guzzle-8-major-bump')
            ->assertOk()
            ->assertSee('href="'.url('/it/blog/guzzle-8-major-bump').'"', false)
            ->assertSee('href="'.url('/nl/blog/guzzle-8-major-bump').'"', false);
    }

    public function test_page_in_non_indexed_locale_keeps_canonical_without_hreflang(): void
    {
        $response = $this->get('/mt/')->assertOk();

        // Distinct content, so it must point its canonical at itself...
        $response->assertSee('<link rel="canonical" href="'.url('/mt').'">', false);

        // ...but declare no hreflang, since no indexed page references it.
        $response->assertDontSee('rel="alternate"', false);
    }

    public function test_auth_pages_carry_no_seo_tags(): void
    {
        $this->get('/fr/login')
            ->assertOk()
            ->assertDontSee('rel="canonical"', false)
            ->assertDontSee('hreflang=', false);
    }
}
