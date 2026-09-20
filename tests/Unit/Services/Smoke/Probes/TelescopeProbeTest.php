<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Services\Smoke\Probes\TelescopeProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * Telescope is registered on APP_ENV=local plus the package being installed — the same environment
 * drift that publishes the E2E backdoor. That one hands out an admin session; this one hands out
 * every SQL query, exception and session the site has seen.
 */
class TelescopeProbeTest extends TestCase
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
        return (new TelescopeProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    public function test_it_passes_when_the_console_is_absent(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    /**
     * 200 is the console itself; 403 means the package is on the server and only its gate stands in
     * the way, which `composer install --no-dev` was supposed to make impossible. Both block.
     *
     * @param  int  $status  what /telescope answers instead of 404
     */
    #[DataProvider('servedStatuses')]
    public function test_it_blocks_on_anything_but_a_404(int $status): void
    {
        $check = $this->check(['GET '.self::SITE.'/telescope' => fn () => Http::response('', $status)]);

        $this->assertTrue($check->blocks(), "telescope should block on {$status}: {$check->detail}");
        $this->assertStringContainsString("/telescope answered {$status}", $check->detail);
        $this->assertStringContainsString('--no-dev', $check->remedy);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function servedStatuses(): iterable
    {
        yield 'console served' => [200];
        yield 'installed but gated' => [403];
        yield 'redirected to a login' => [302];
    }

    public function test_it_blocks_when_the_site_does_not_answer(): void
    {
        $check = $this->check(['GET '.self::SITE.'/telescope' => fn () => Http::failedConnection()]);

        $this->assertTrue($check->blocks());
        $this->assertStringContainsString('nothing', $check->detail);
    }
}
