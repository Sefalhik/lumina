<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Enums\SmokeSeverity;
use App\Enums\SmokeStatus;
use App\Services\Smoke\Probes\CertificateProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * A renewal that stops working says nothing: the certificate simply expires, and every visitor meets a security warning.
 */
class CertificateProbeTest extends TestCase
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
        return (new CertificateProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    private function assertBlocks(SmokeCheck $check, string $expectedDetail): void
    {
        $this->assertTrue($check->blocks(), "{$check->id} should block: {$check->detail}");
        $this->assertStringContainsString($expectedDetail, $check->detail);
        $this->assertNotSame('', $check->remedy, 'A failure must say what to do.');
    }

    public function test_it_passes_on_a_certificate_with_ninety_days_left(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    public function test_it_blocks_when_none_can_be_read(): void
    {
        $this->assertBlocks($this->check(noCertificate: true), 'No valid certificate');
    }

    public function test_it_blocks_under_three_days(): void
    {
        $this->assertBlocks($this->check(expiry: CarbonImmutable::now()->addDays(2)->addHour()), 'Expires in 2 days');
    }

    public function test_it_only_warns_under_fourteen_days(): void
    {
        $check = $this->check(expiry: CarbonImmutable::now()->addDays(10)->addHour());

        $this->assertTrue($check->warns());
        $this->assertFalse($check->blocks());
        $this->assertSame(SmokeSeverity::Warning, $check->severity);
    }

    public function test_it_is_skipped_on_a_plain_http_target(): void
    {
        Http::fake(['http://localhost:8001/*' => Http::response('Not Found', 404)]);

        $check = $this->check(target: new SmokeTarget('http://localhost:8001'));

        $this->assertSame(SmokeStatus::Skipped, $check->status);
    }
}
