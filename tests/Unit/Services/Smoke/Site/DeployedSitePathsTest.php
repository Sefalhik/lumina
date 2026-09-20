<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Site;

use App\Services\Smoke\Site\DeployedSite;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The pages the suite reads are derived from config/seo.php, not written down beside it.
 *
 * Two lists of public pages would drift the first time one is updated alone — and the one that
 * would be forgotten is this one, because nothing fails when a page goes unprobed. So this asserts
 * the derivation, not a list: add a public route tomorrow and it is smoke-tested the same day.
 */
class DeployedSitePathsTest extends TestCase
{
    public function test_every_indexable_locale_has_its_homepage_read(): void
    {
        config(['i18n.indexable_locales' => ['fr', 'en', 'de']]);

        $paths = DeployedSite::publicPaths();

        $this->assertSame(['/fr', '/en', '/de'], array_slice($paths, 0, 3));
    }

    public function test_every_public_route_of_the_seo_allowlist_is_read(): void
    {
        foreach ((array) config('seo.public_routes') as $name) {
            $route = Route::getRoutes()->getByName((string) $name);
            $this->assertNotNull($route, "config/seo.php lists an unknown route: {$name}.");

            if (array_diff($route->parameterNames(), ['lang']) !== []) {
                continue;
            }

            $expected = '/'.ltrim(str_replace('{lang}', 'fr', $route->uri()), '/');
            $this->assertContains($expected, DeployedSite::publicPaths(), "The public route {$name} is never read by the smoke suite.");
        }
    }

    public function test_a_route_needing_more_than_a_locale_is_skipped(): void
    {
        // blog.show takes a slug. The probe has no legitimate one to invent, and a made-up slug
        // would assert a 404 page rather than the page the route serves.
        $this->assertNotNull(Route::getRoutes()->getByName('blog.show'));

        foreach (DeployedSite::publicPaths() as $path) {
            $this->assertStringNotContainsString('{', $path);
        }
    }

    public function test_an_unknown_route_name_is_skipped_rather_than_fatal(): void
    {
        // A smoke run must never die on a stale config entry: it is the thing that reports failures.
        config(['i18n.indexable_locales' => ['fr'], 'seo.public_routes' => ['home', 'cv', 'route.that.went.away']]);

        $this->assertSame(['/fr', '/fr/cv'], DeployedSite::publicPaths());
    }
}
