<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Services\Smoke\Probes\GeoProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * The only outbound dependency of the deployment. Every other probe goes inwards, so a firewall
 * rule or a resolver that exists only on the server is invisible to all of them.
 */
class GeoProbeTest extends TestCase
{
    use FakesDeployedSite;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config([
            'smoke.warmup_attempts' => 2,
            'smoke.warmup_sleep_ms' => 0,
            'smoke.response_time_warning_ms' => 60_000,
            'i18n.indexable_locales' => ['fr', 'en', 'de', 'it', 'nl'],
        ]);
    }

    private function check(array $overrides = [], ?DateTimeImmutable $expiry = null, bool $noCertificate = false, ?SmokeTarget $target = null): SmokeCheck
    {
        return (new GeoProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    public function test_it_passes_when_the_upstream_answers(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    /**
     * The defect this probe exists for: GeoController turns a failed lookup into a 200 carrying
     * {"error": true}. A probe reading the status code alone would pass with the API unreachable.
     */
    public function test_it_warns_when_a_200_carries_an_error_payload(): void
    {
        $check = $this->check(['GET '.self::SITE.'/api/geo' => fn () => Http::response(['error' => true])]);

        $this->assertTrue($check->warns(), "geo should warn: {$check->detail}");
        $this->assertFalse($check->blocks(), 'A third-party outage must never refuse a release.');
        $this->assertStringContainsString('the upstream call failed', $check->detail);
        $this->assertStringContainsString('outbound HTTP', $check->remedy);
    }

    public function test_an_empty_payload_is_not_a_location(): void
    {
        $check = $this->check(['GET '.self::SITE.'/api/geo' => fn () => Http::response([])]);

        $this->assertTrue($check->warns());
    }

    public function test_it_warns_when_the_endpoint_itself_is_broken(): void
    {
        $check = $this->check(['GET '.self::SITE.'/api/geo' => fn () => Http::response('', 500)]);

        $this->assertTrue($check->warns());
        $this->assertStringContainsString('/api/geo answered 500', $check->detail);
        $this->assertStringContainsString('laravel.log', $check->remedy);
    }

    public function test_it_warns_when_the_endpoint_does_not_answer(): void
    {
        $check = $this->check(['GET '.self::SITE.'/api/geo' => fn () => Http::failedConnection()]);

        $this->assertTrue($check->warns());
        $this->assertStringContainsString('nothing', $check->detail);
    }
}
