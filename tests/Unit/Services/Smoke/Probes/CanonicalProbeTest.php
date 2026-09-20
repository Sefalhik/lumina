<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Services\Smoke\Probes\CanonicalProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * A canonical naming another scheme or host is how the trustProxies defect of 2026-09-14 showed itself.
 */
class CanonicalProbeTest extends TestCase
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
        return (new CanonicalProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    private function assertBlocks(SmokeCheck $check, string $expectedDetail): void
    {
        $this->assertTrue($check->blocks(), "{$check->id} should block: {$check->detail}");
        $this->assertStringContainsString($expectedDetail, $check->detail);
        $this->assertNotSame('', $check->remedy, 'A failure must say what to do.');
    }

    public function test_it_passes_when_each_page_names_the_queried_url(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    public function test_it_blocks_on_a_plain_http_canonical(): void
    {
        $page = str_replace('href="'.self::SITE.'/fr"', 'href="http://lumina.test/fr"', self::deployedPage('fr', '/fr', 'Biographie fr'));
        $check = $this->check(['GET '.self::SITE.'/fr' => fn () => Http::response($page)]);

        $this->assertBlocks($check, '/fr → http://lumina.test/fr');
    }
}
