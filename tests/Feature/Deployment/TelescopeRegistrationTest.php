<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use App\Providers\TelescopeServiceProvider;
use Tests\Concerns\RebootsInEnvironment;
use Tests\TestCase;

/**
 * `laravel/telescope` lives in `require-dev`, and `App\Providers\TelescopeServiceProvider`
 * extends a class that ships with it.
 *
 * Listing that provider in `bootstrap/providers.php` therefore made a production
 * install — `composer install --no-dev` — die at boot with
 * `Class "Laravel\Telescope\TelescopeApplicationServiceProvider" not found`,
 * before a single route was matched. Not a broken feature: a site that does not start.
 *
 * It is now registered from `AppServiceProvider::register()` behind two conditions.
 */
class TelescopeRegistrationTest extends TestCase
{
    use RebootsInEnvironment;

    /**
     * The actual regression guard.
     *
     * The `class_exists()` half of the condition cannot be exercised by this suite:
     * the suite runs with dev dependencies installed, by definition, so the class
     * always exists here. What *can* be pinned is that nothing puts the provider
     * back into the unconditional list — which is the mistake that was made, and
     * the one a future `php artisan telescope:install` would silently repeat.
     */
    public function test_telescope_is_not_in_the_unconditional_provider_list(): void
    {
        $providers = require base_path('bootstrap/providers.php');

        $this->assertNotContains(
            TelescopeServiceProvider::class,
            $providers,
            'TelescopeServiceProvider is registered unconditionally again: '
            .'`composer install --no-dev` will be fatal at boot.',
        );
    }

    public function test_telescope_is_registered_in_local(): void
    {
        $this->rebootIn('local');

        $this->assertNotNull(
            app()->getProvider(TelescopeServiceProvider::class),
            'Telescope should still be available to a developer.',
        );
    }

    public function test_telescope_is_not_registered_in_production(): void
    {
        $this->rebootIn('production');

        $this->assertNull(app()->getProvider(TelescopeServiceProvider::class));
    }

    public function test_telescope_is_not_registered_in_an_unanticipated_environment(): void
    {
        foreach (['preprod', 'staging', 'testing'] as $environment) {
            $this->rebootIn($environment);

            $this->assertNull(
                app()->getProvider(TelescopeServiceProvider::class),
                "Debugging tooling is registered in the [{$environment}] environment.",
            );
        }
    }
}
