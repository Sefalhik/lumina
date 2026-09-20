<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Enums\SmokeStatus;
use App\Services\Smoke\Probes\ReleaseProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * Without this probe first, a failed switch to a new release leaves every other probe testing — and passing — on the old one.
 */
class ReleaseProbeTest extends TestCase
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
        return (new ReleaseProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    private function assertBlocks(SmokeCheck $check, string $expectedDetail): void
    {
        $this->assertTrue($check->blocks(), "{$check->id} should block: {$check->detail}");
        $this->assertStringContainsString($expectedDetail, $check->detail);
        $this->assertNotSame('', $check->remedy, 'A failure must say what to do.');
    }

    public function test_it_passes_when_the_expected_release_answers(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    public function test_it_blocks_when_another_release_answers(): void
    {
        $check = $this->check(['GET '.self::SITE.'/up' => fn () => Http::response('OK', 200, ['X-Release' => 'old999'])]);

        $this->assertBlocks($check, 'Serving old999, expected abc123');
    }

    public function test_it_blocks_when_the_header_is_missing(): void
    {
        $check = $this->check(['GET '.self::SITE.'/up' => fn () => Http::response('OK')]);

        $this->assertBlocks($check, 'No X-Release header');
    }

    public function test_it_blocks_when_the_site_does_not_answer(): void
    {
        $check = $this->check(['GET '.self::SITE.'/up' => fn () => Http::failedConnection()]);

        $this->assertBlocks($check, 'The site did not answer.');
    }

    public function test_it_is_skipped_without_an_expectation(): void
    {
        $check = $this->check(target: new SmokeTarget(self::SITE));

        $this->assertSame(SmokeStatus::Skipped, $check->status);
        $this->assertFalse($check->blocks());
    }
}
