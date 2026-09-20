<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Enums\SmokeStatus;
use App\Services\Smoke\Probes\HttpsRedirectProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * On 2026-09-14 the site asked for a Basic auth password over plain HTTP: base64 is encoding, not encryption.
 */
class HttpsRedirectProbeTest extends TestCase
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
        return (new HttpsRedirectProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    private function assertBlocks(SmokeCheck $check, string $expectedDetail): void
    {
        $this->assertTrue($check->blocks(), "{$check->id} should block: {$check->detail}");
        $this->assertStringContainsString($expectedDetail, $check->detail);
        $this->assertNotSame('', $check->remedy, 'A failure must say what to do.');
    }

    public function test_it_passes_on_a_permanent_redirect(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    public function test_it_blocks_on_a_temporary_redirect(): void
    {
        $check = $this->check(['GET http://lumina.test/fr' => fn () => Http::response('', 302, ['Location' => self::SITE.'/fr'])]);

        $this->assertBlocks($check, 'http:// answered 302');
    }

    public function test_it_blocks_when_plain_http_serves_the_site(): void
    {
        $check = $this->check(['GET http://lumina.test/fr' => fn () => Http::response('<html>')]);

        $this->assertBlocks($check, 'http:// answered 200');
    }

    public function test_it_is_skipped_on_a_plain_http_target(): void
    {
        Http::fake(['http://localhost:8001/*' => Http::response('Not Found', 404)]);

        $check = $this->check(target: new SmokeTarget('http://localhost:8001'));

        $this->assertSame(SmokeStatus::Skipped, $check->status);
    }
}
