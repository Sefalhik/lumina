<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Services\Smoke\Probes\ResponseTimeProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use DateTimeImmutable;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * Never blocking: a slow page is a reason to look, not to refuse a release.
 */
class ResponseTimeProbeTest extends TestCase
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
        return (new ResponseTimeProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    public function test_it_passes_within_its_budget(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    public function test_it_only_warns_above_its_budget(): void
    {
        config(['smoke.response_time_warning_ms' => -1]);
        $check = $this->check();

        $this->assertTrue($check->warns());
        $this->assertFalse($check->blocks());
    }
}
