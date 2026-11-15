<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * No test reaches the network: a request no fake answers throws.
 *
 * `Tests\TestCase` calls `Http::preventStrayRequests()` for the whole suite since 2026-11-15
 * (LUMN-110). Before, each test had to remember it, and `Http::fake()` limited to one URL let every
 * other one through — a test could reach a real service and stay green. This test fails the day
 * that call is removed, which nothing else would notice: a suite that sends real requests passes
 * as long as the services answer.
 *
 * @see docs/testing-conventions.md
 */
class StrayRequestsAreRefusedTest extends TestCase
{
    public function test_a_request_no_fake_answers_is_refused(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://example.invalid/never-sent');
    }

    public function test_a_request_outside_a_partial_fake_is_refused_too(): void
    {
        Http::fake(['https://answered.invalid/*' => Http::response('ok')]);

        $this->assertSame('ok', Http::get('https://answered.invalid/yes')->body());

        $this->expectException(StrayRequestException::class);
        Http::get('https://elsewhere.invalid/no');
    }
}
