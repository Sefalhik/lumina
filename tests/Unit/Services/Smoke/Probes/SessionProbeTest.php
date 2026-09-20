<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Probes;

use App\Services\Smoke\Probes\SessionProbe;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesDeployedSite;
use Tests\TestCase;

/**
 * Without the sessions table the login page renders, sets no session, and nobody can ever log in. No login is attempted: a wrong password would eat into the rate limit of LUMN-36.
 */
class SessionProbeTest extends TestCase
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
        return (new SessionProbe)->check($this->site($overrides, $expiry, $noCertificate, $target));
    }

    private function assertBlocks(SmokeCheck $check, string $expectedDetail): void
    {
        $this->assertTrue($check->blocks(), "{$check->id} should block: {$check->detail}");
        $this->assertStringContainsString($expectedDetail, $check->detail);
        $this->assertNotSame('', $check->remedy, 'A failure must say what to do.');
    }

    public function test_it_passes_when_the_login_page_sets_its_cookies(): void
    {
        $this->assertTrue($this->check()->passed());
    }

    public function test_it_reads_the_header_whatever_its_case(): void
    {
        // HTTP/2 lower-cases header names. A case-sensitive lookup reported a missing session on a
        // preprod that was setting both cookies — found on 2026-09-20 against the real site.
        $check = $this->check(['GET '.self::SITE.'/fr/login' => fn () => Http::response('<input name="_token">', 200, [
            'SET-COOKIE' => ['XSRF-TOKEN=x', 'cardascia-it-session=y'],
        ])]);

        $this->assertTrue($check->passed());
    }

    public function test_it_blocks_when_no_xsrf_cookie_is_set(): void
    {
        $check = $this->check(['GET '.self::SITE.'/fr/login' => fn () => Http::response('<input name="_token">', 200, ['set-cookie' => 'cardascia-it-session=y'])]);

        $this->assertBlocks($check, 'Missing: XSRF-TOKEN cookie');
    }
}
