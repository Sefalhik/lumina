<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Services\Smoke\Probes\E2eBackdoorProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * One of the E2E helper routes logs anyone in as an admin. This probe watches the guard from outside, on the machine it protects.
 */
class E2eBackdoorProbeTest extends TestCase
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
        return (new E2eBackdoorProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    private function assertBlocks(SmokeCheck $check, string $expectedDetail): void
    {
        $this->assertTrue($check->blocks(), "{$check->id} should block: {$check->detail}");
        $this->assertStringContainsString($expectedDetail, $check->detail);
        $this->assertNotSame('', $check->remedy, 'A failure must say what to do.');
    }

    public function test_it_passes_when_the_backdoor_is_absent(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    public function test_it_blocks_when_the_backdoor_answers(): void
    {
        $check = $this->check(['GET '.self::SITE.'/e2e/admin-auth' => fn () => Http::response('', 302, ['Location' => self::SITE.'/fr/admin'])]);

        $this->assertBlocks($check, '/e2e/admin-auth → 302');
    }

    /**
     * The three routes load together today, so a probe reading one would pass on the strength of
     * what the code looks like rather than what the site answers. Each is read on its own.
     *
     * @param  string  $path  one helper route left published
     */
    #[DataProvider('helperRoutes')]
    public function test_it_blocks_on_any_published_helper_route(string $path): void
    {
        $check = $this->check(['GET '.self::SITE.$path => fn () => Http::response('{}', 200)]);

        $this->assertBlocks($check, $path.' → 200');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function helperRoutes(): iterable
    {
        yield 'admin backdoor' => ['/e2e/admin-auth'];
        yield 'homepage content' => ['/e2e/homepage-content'];
        yield 'site identity' => ['/e2e/site-identity'];
    }

    public function test_it_never_sends_the_writing_half_of_the_helper_routes(): void
    {
        // Two of them accept a POST that overwrites site-wide content. A POST that answers is a
        // POST that already wrote: the probe must only ever read.
        $this->check();

        $this->assertCount(0, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }
}
