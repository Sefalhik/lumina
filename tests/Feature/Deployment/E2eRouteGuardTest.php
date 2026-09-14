<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Illuminate\Routing\Route;
use Tests\Concerns\RebootsInEnvironment;
use Tests\TestCase;

/**
 * `routes/e2e.php` is a backdoor, and this is what keeps it off the internet.
 *
 * GET /e2e/admin-auth creates an administrator, sets the two-factor session flag
 * and logs the caller in — no password, no TOTP. `bootstrap/app.php` loads that
 * file on an ALLOWLIST of `local` and `testing`.
 *
 * It used to be a denylist of `production`, which published the backdoor on every
 * environment name nobody had anticipated. The one being created when that was
 * found was called `preprod`, and it is on the public internet.
 *
 * The test that matters here is therefore not "it is absent in production" —
 * a denylist passes that one too. It is "it is absent in an environment this
 * codebase has never heard of".
 */
class E2eRouteGuardTest extends TestCase
{
    use RebootsInEnvironment;

    /** @return list<string> */
    private function helperRoutes(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->map(fn (Route $route): string => $route->uri())
            ->filter(fn (string $uri): bool => str_starts_with($uri, 'e2e/'))
            ->values()
            ->all();
    }

    public function test_helper_routes_are_available_in_testing(): void
    {
        // No reboot: this is the environment the suite already runs in, and the
        // Playwright suite depends on these routes existing.
        $this->assertNotEmpty($this->helperRoutes());
    }

    public function test_helper_routes_are_available_in_local(): void
    {
        $this->rebootIn('local');

        $this->assertNotEmpty($this->helperRoutes());
    }

    public function test_helper_routes_are_absent_in_production(): void
    {
        $this->rebootIn('production');

        $this->assertSame([], $this->helperRoutes());
    }

    /**
     * The regression this whole file exists for. A denylist of `production`
     * passes every other test in this class and fails this one.
     */
    public function test_helper_routes_are_absent_in_an_unanticipated_environment(): void
    {
        foreach (['preprod', 'staging', 'demo', 'review-app-42', 'prod'] as $environment) {
            $this->rebootIn($environment);

            $this->assertSame(
                [],
                $this->helperRoutes(),
                "The e2e helper routes are exposed in the [{$environment}] environment.",
            );
        }
    }

    public function test_the_admin_backdoor_answers_nothing_outside_the_allowlist(): void
    {
        $this->rebootIn('preprod');

        $this->get('/e2e/admin-auth')->assertNotFound();
    }

    /**
     * Guards the guard's reach: a route added to routes/e2e.php under a path that
     * does not start with `e2e/` would escape the filter above and be reported as
     * absent while being served.
     */
    public function test_every_helper_route_sits_under_the_e2e_prefix(): void
    {
        $inTesting = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn (Route $route): string => $route->uri());

        $this->rebootIn('production');

        $inProduction = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn (Route $route): string => $route->uri());

        $onlyInTesting = $inTesting->diff($inProduction)->values();

        $this->assertNotEmpty($onlyInTesting, 'No route is conditional on the environment at all.');

        foreach ($onlyInTesting as $uri) {
            $this->assertStringStartsWith(
                'e2e/',
                $uri,
                "Route [{$uri}] is environment-conditional but does not sit under the e2e/ prefix.",
            );
        }
    }
}
