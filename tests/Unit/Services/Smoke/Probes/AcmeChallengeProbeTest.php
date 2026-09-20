<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Services\Smoke\Probes\AcmeChallengeProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * Behind Basic auth the challenge path answers 401, renewal fails silently, and the certificate expires ninety days later. Found on preprod at the first real run (LUMN-61).
 */
class AcmeChallengeProbeTest extends TestCase
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
        return (new AcmeChallengeProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    private function assertBlocks(SmokeCheck $check, string $expectedDetail): void
    {
        $this->assertTrue($check->blocks(), "{$check->id} should block: {$check->detail}");
        $this->assertStringContainsString($expectedDetail, $check->detail);
        $this->assertNotSame('', $check->remedy, 'A failure must say what to do.');
    }

    public function test_it_passes_when_the_challenge_path_is_exempted(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    public function test_it_blocks_when_basic_auth_covers_the_challenge_path(): void
    {
        $check = $this->check(
            ['GET '.self::SITE.'/.well-known/acme-challenge/lumina-smoke' => fn (Request $request) => $request->hasHeader('Authorization')
                ? Http::response('Not Found', 404)
                : Http::response('Unauthorized', 401)],
            target: new SmokeTarget(self::SITE, self::SITE_RELEASE, 'laurent', 's3cret'),
        );

        $this->assertBlocks($check, 'Basic auth covers it');
    }

    public function test_it_is_probed_without_the_credentials(): void
    {
        // Sent with them, it would pass even if the exemption were gone.
        $this->check(target: new SmokeTarget(self::SITE, self::SITE_RELEASE, 'laurent', 's3cret'));

        $acme = Http::recorded(fn (Request $request) => str_contains($request->url(), 'acme-challenge'));
        $this->assertCount(1, $acme);
        $this->assertFalse($acme->first()[0]->hasHeader('Authorization'));
    }
}
