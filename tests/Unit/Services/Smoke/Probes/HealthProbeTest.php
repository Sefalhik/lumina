<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Services\Smoke\Probes\HealthProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * A health check that answers 200 with the database down is worse than none: it is a green light on the most likely failure of a deployment.
 */
class HealthProbeTest extends TestCase
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
        return (new HealthProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    private function assertBlocks(SmokeCheck $check, string $expectedDetail): void
    {
        $this->assertTrue($check->blocks(), "{$check->id} should block: {$check->detail}");
        $this->assertStringContainsString($expectedDetail, $check->detail);
        $this->assertNotSame('', $check->remedy, 'A failure must say what to do.');
    }

    public function test_it_passes_when_up_answers(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    public function test_it_blocks_when_up_fails_after_the_warm_up(): void
    {
        $check = $this->check(['GET '.self::SITE.'/up' => fn () => Http::response('down', 500)]);

        $this->assertBlocks($check, '/up answered 500');
        $this->assertCount(2, Http::recorded(fn ($request) => $request->url() === self::SITE.'/up'));
    }

    public function test_a_500_sends_the_reader_to_the_database(): void
    {
        $check = $this->check(['GET '.self::SITE.'/up' => fn () => Http::response('down', 500)]);

        $this->assertStringContainsString('the application answered and refused', $check->detail);
        $this->assertStringContainsString('DB_*', $check->remedy);
    }

    /**
     * A gateway answers alone when PHP never ran. Blaming the database there sends the reader to
     * the one place the problem is not — the failure this probe exists to describe, misdescribed.
     *
     * @param  int  $status  a gateway status the warm-up could not clear
     */
    #[DataProvider('gatewayStatuses')]
    public function test_a_gateway_failure_never_blames_the_database(int $status): void
    {
        $check = $this->check(['GET '.self::SITE.'/up' => fn () => Http::response('', $status)]);

        $this->assertBlocks($check, "/up answered {$status}: a gateway answer");
        $this->assertStringContainsString('PHP-FPM', $check->remedy);
        $this->assertStringContainsString('not the database', $check->remedy);
        $this->assertStringNotContainsString('DB_*', $check->remedy);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function gatewayStatuses(): iterable
    {
        yield 'bad gateway' => [502];
        yield 'service unavailable' => [503];
        yield 'gateway timeout' => [504];
    }

    public function test_a_401_points_at_the_basic_auth_credentials(): void
    {
        $check = $this->check(['GET '.self::SITE.'/up' => fn () => Http::response('', 401)]);

        $this->assertBlocks($check, '/up answered 401: the request was refused');
        $this->assertStringContainsString('SMOKE_BASIC_USER', $check->remedy);
        // 4xx is not a cold release: retrying it would only delay the report.
        $this->assertCount(1, Http::recorded(fn ($request) => $request->url() === self::SITE.'/up'));
    }

    public function test_a_404_points_at_the_document_root(): void
    {
        $check = $this->check(['GET '.self::SITE.'/up' => fn () => Http::response('', 404)]);

        $this->assertBlocks($check, '/up answered 404: the health route is not served');
        $this->assertStringContainsString('public/', $check->remedy);
    }

    public function test_the_warm_up_waits_for_a_cold_release(): void
    {
        $calls = 0;
        $check = $this->check(['GET '.self::SITE.'/up' => function () use (&$calls) {
            return ++$calls === 1 ? Http::response('starting', 503) : Http::response('OK');
        }]);

        $this->assertTrue($check->passed());
        Sleep::assertSleptTimes(1);
    }
}
