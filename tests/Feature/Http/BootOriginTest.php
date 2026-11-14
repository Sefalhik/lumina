<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The boot sequence asks the server nothing about the visitor (LUMN-122). Its origin is drawn in
 * the browser (resources/js/utils/boot-origin.js); the endpoint that sent each visitor's address to
 * a third party is gone, under its address and under its name — a name Ziggy would still publish
 * to every page.
 */
class BootOriginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_geolocation_endpoint_is_gone(): void
    {
        $this->get('/api/geo')->assertNotFound();
    }

    public function test_no_route_bears_its_name(): void
    {
        $this->assertFalse(Route::has('api.geo'));
    }

    public function test_the_pages_publish_no_route_to_it(): void
    {
        // Ziggy publishes every named route in a JSON object, its path escaped (api\/geo): the name
        // is what reads the same in the page.
        $this->get('/fr/')->assertOk()->assertDontSee('"api.geo"', false);
    }
}
