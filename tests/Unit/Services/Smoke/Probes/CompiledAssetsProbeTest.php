<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Services\Smoke\Probes\CompiledAssetsProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * public/build is gitignored: the assets exist nowhere until something builds them on the server, and a page whose CSS 404s still answers 200.
 */
class CompiledAssetsProbeTest extends TestCase
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
        return (new CompiledAssetsProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    private function assertBlocks(SmokeCheck $check, string $expectedDetail): void
    {
        $this->assertTrue($check->blocks(), "{$check->id} should block: {$check->detail}");
        $this->assertStringContainsString($expectedDetail, $check->detail);
        $this->assertNotSame('', $check->remedy, 'A failure must say what to do.');
    }

    public function test_it_passes_when_the_compiled_files_load(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    public function test_it_blocks_when_served_by_a_vite_dev_server(): void
    {
        $check = $this->check(['GET '.self::SITE.'/fr' => fn () => Http::response('<script type="module" src="https://lumina.test:5173/@vite/client"></script>')]);

        $this->assertBlocks($check, 'Vite development server');
    }

    public function test_it_blocks_when_a_compiled_file_is_missing(): void
    {
        $check = $this->check(['GET '.self::SITE.'/build/assets/app-2.js' => fn () => Http::response('Not Found', 404)]);

        $this->assertBlocks($check, 'app-2.js → 404');
    }

    public function test_it_blocks_when_served_with_the_wrong_type(): void
    {
        $check = $this->check(['GET '.self::SITE.'/build/assets/app-1.css' => fn () => Http::response('<html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertBlocks($check, 'app-1.css → 200');
    }

    public function test_it_blocks_when_the_page_references_none(): void
    {
        $check = $this->check(['GET '.self::SITE.'/fr' => fn () => Http::response('<p data-smoke="bio">Biographie fr</p>')]);

        $this->assertBlocks($check, 'references no compiled asset');
    }
}
