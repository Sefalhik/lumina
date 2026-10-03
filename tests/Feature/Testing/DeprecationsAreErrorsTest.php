<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use ErrorException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A deprecation raised while Laravel runs has to fail the test that raised it.
 *
 * It did not, until LUMN-71. Laravel's error handler drops every deprecation when the application
 * runs in tests, so PHPUnit counted none and the suite printed a zero that proved nothing: three
 * probes out of four went through unseen. `Tests\TestCase` now calls `withoutDeprecationHandling()`,
 * and these tests fail the day that call is removed — which nothing else would notice, since a
 * suite that stops seeing deprecations stays green.
 *
 * A deprecation is the only notice a package gives before removing something. It is worth more
 * before a major upgrade than after.
 *
 * @see docs/testing-conventions.md
 */
class DeprecationsAreErrorsTest extends TestCase
{
    public function test_a_deprecation_raised_in_a_test_is_an_exception(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessageIsOrContains('deprecated on purpose');

        trigger_error('deprecated on purpose', E_USER_DEPRECATED);
    }

    public function test_a_deprecation_raised_while_serving_a_request_fails_the_request(): void
    {
        Route::get('/__deprecation', function (): string {
            trigger_error('deprecated on purpose', E_USER_DEPRECATED);

            return 'never reached';
        });

        $this->get('/__deprecation')->assertStatus(500);
    }

    public function test_a_test_can_let_a_deprecation_through_when_it_has_to(): void
    {
        // The way out for a deprecation raised by a package, which this code cannot fix.
        $this->withDeprecationHandling();

        trigger_error('deprecated on purpose', E_USER_DEPRECATED);

        $this->addToAssertionCount(1);
    }
}
