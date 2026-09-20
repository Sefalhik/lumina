<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Services\Smoke\Probes\TranslationsProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * Comparing whole pages proves nothing: interface strings differ between locales even when the biography stayed in French.
 */
class TranslationsProbeTest extends TestCase
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
        return (new TranslationsProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    private function assertBlocks(SmokeCheck $check, string $expectedDetail): void
    {
        $this->assertTrue($check->blocks(), "{$check->id} should block: {$check->detail}");
        $this->assertStringContainsString($expectedDetail, $check->detail);
        $this->assertNotSame('', $check->remedy, 'A failure must say what to do.');
    }

    public function test_it_passes_when_each_locale_has_its_own_biography(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    public function test_it_blocks_when_a_locale_shows_the_french_biography(): void
    {
        $check = $this->check(['GET '.self::SITE.'/nl' => fn () => Http::response(self::deployedPage('nl', '/nl', 'Biographie fr'))]);

        $this->assertBlocks($check, '/nl show the French biography');
    }

    public function test_it_blocks_when_the_marker_is_gone(): void
    {
        $check = $this->check(['GET '.self::SITE.'/de' => fn () => Http::response('<html><link rel="canonical" href="'.self::SITE.'/de"><p>Bio</p></html>')]);

        $this->assertBlocks($check, 'No data-smoke="bio" element on /de');
    }
}
