<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Services\Smoke\Probes\ErrorPagesProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * A 404 cannot reveal APP_DEBUG — Laravel renders its own page even in debug mode. A refused method has no such page, so it prints the stack trace.
 */
class ErrorPagesProbeTest extends TestCase
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
        return (new ErrorPagesProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    private function assertBlocks(SmokeCheck $check, string $expectedDetail): void
    {
        $this->assertTrue($check->blocks(), "{$check->id} should block: {$check->detail}");
        $this->assertStringContainsString($expectedDetail, $check->detail);
        $this->assertNotSame('', $check->remedy, 'A failure must say what to do.');
    }

    public function test_it_passes_on_a_clean_404_and_a_silent_405(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    public function test_it_blocks_when_a_missing_page_is_not_a_404(): void
    {
        $check = $this->check(['GET '.self::SITE.'/fr/lumina-smoke-missing-page' => fn () => Http::response('Server Error', 500)]);

        $this->assertBlocks($check, 'answered 500 instead of 404');
    }

    public function test_it_blocks_when_debug_output_leaks(): void
    {
        $check = $this->check(['PATCH '.self::SITE.'/fr' => fn () => Http::response('MethodNotAllowedHttpException in Illuminate\\Routing\\AbstractRouteCollection', 405)]);

        $this->assertBlocks($check, 'APP_DEBUG is on');
    }
}
