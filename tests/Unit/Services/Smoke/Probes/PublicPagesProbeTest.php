<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Services\Smoke\Probes\PublicPagesProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * The missing .htaccess of 2026-09-14 left only "/" answering; the fatal Telescope provider of the same day made every page a 500.
 */
class PublicPagesProbeTest extends TestCase
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
        return (new PublicPagesProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    private function assertBlocks(SmokeCheck $check, string $expectedDetail): void
    {
        $this->assertTrue($check->blocks(), "{$check->id} should block: {$check->detail}");
        $this->assertStringContainsString($expectedDetail, $check->detail);
        $this->assertNotSame('', $check->remedy, 'A failure must say what to do.');
    }

    public function test_it_passes_when_every_page_answers(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    public function test_it_blocks_when_one_page_is_missing(): void
    {
        $check = $this->check(['GET '.self::SITE.'/fr/cv' => fn () => Http::response('Not Found', 404)]);

        $this->assertBlocks($check, '/fr/cv → 404');
    }
}
