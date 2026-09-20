<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Smoke\Probes\HealthProbe;
use App\Services\Smoke\SmokeCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesDeployedSite;
use Tests\Support\Smoke\CrashingProbe;
use Tests\TestCase;

/**
 * The command is what a pipeline reads: its exit code decides whether a release is promoted. These
 * tests pin that contract — and that the credentials it is handed never come back out.
 */
class DeploySmokeTest extends TestCase
{
    use FakesDeployedSite;

    private const PASSWORD = 's3cret-basic-pw';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config([
            'smoke.warmup_attempts' => 1,
            'smoke.response_time_warning_ms' => 60_000,
            'smoke.basic_user' => 'preprod-user',
            'smoke.basic_password' => self::PASSWORD,
            'i18n.indexable_locales' => ['fr', 'en', 'de', 'it', 'nl'],
        ]);
    }

    public function test_a_healthy_site_exits_zero(): void
    {
        $this->fakeDeployedSite();

        $this->artisan('deploy:smoke', ['--url' => self::SITE, '--expect-release' => self::SITE_RELEASE])
            ->expectsOutputToContain('PASSED — 19 passed, 0 blocking, 0 warnings, 0 skipped')
            ->assertSuccessful();
    }

    public function test_a_blocking_failure_exits_one(): void
    {
        $this->fakeDeployedSite(['GET '.self::SITE.'/e2e/admin-auth' => fn () => Http::response('', 302)]);

        $this->artisan('deploy:smoke', ['--url' => self::SITE])
            ->expectsOutputToContain('/e2e/admin-auth → 302')
            ->assertFailed();
    }

    public function test_a_warning_alone_exits_zero(): void
    {
        // A certificate with ten good days left must not stop a deployment.
        $this->fakeDeployedSite(certificateExpiry: CarbonImmutable::now()->addDays(10)->addHour());

        $this->artisan('deploy:smoke', ['--url' => self::SITE])
            ->expectsOutputToContain('1 warnings')
            ->assertSuccessful();
    }

    public function test_a_warning_is_logged_as_a_warning_not_an_error(): void
    {
        $log = Log::spy();
        $this->fakeDeployedSite(certificateExpiry: CarbonImmutable::now()->addDays(10)->addHour());

        $this->artisan('deploy:smoke', ['--url' => self::SITE])->assertSuccessful();

        $log->shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $level === 'warning'
                && $message === 'Smoke check failed'
                && $context['check'] === 'certificate',
        )->once();
    }

    public function test_every_check_runs_after_a_failure(): void
    {
        $this->fakeDeployedSite(['GET '.self::SITE.'/up' => fn () => Http::response('down', 500)]);

        Artisan::call('deploy:smoke', ['--url' => self::SITE, '--format' => 'json']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($report);
        $this->assertCount(count(SmokeCatalogue::PROBES), $report['checks']);
    }

    /**
     * The end-to-end shape of the 2026-09-20 defect: a bug inside one probe used to replace the
     * whole report with a stack trace, so a pipeline learned nothing about the other eighteen.
     */
    public function test_a_crashing_probe_still_produces_a_full_report_and_a_non_zero_exit(): void
    {
        $this->app->bind(HealthProbe::class, fn (): CrashingProbe => new CrashingProbe);
        $this->fakeDeployedSite();

        $this->artisan('deploy:smoke', ['--url' => self::SITE])
            ->expectsOutputToContain('The probe itself failed')
            ->assertFailed();
    }

    public function test_a_crashing_probe_leaves_every_other_check_in_the_json_report(): void
    {
        $this->app->bind(HealthProbe::class, fn (): CrashingProbe => new CrashingProbe);
        $this->fakeDeployedSite();

        Artisan::call('deploy:smoke', ['--url' => self::SITE, '--format' => 'json']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($report);
        $this->assertCount(count(SmokeCatalogue::PROBES), $report['checks']);
        $this->assertSame(1, $report['counts']['blocking']);
    }

    public function test_the_credentials_never_appear_in_any_output(): void
    {
        $this->fakeDeployedSite(['GET '.self::SITE.'/.env' => fn () => Http::response('APP_KEY=x')]);

        foreach (['text', 'json', 'junit'] as $format) {
            Artisan::call('deploy:smoke', ['--url' => self::SITE, '--format' => $format]);
            $output = Artisan::output();

            $this->assertStringNotContainsString(self::PASSWORD, $output, "The password leaked in the {$format} output.");
            $this->assertStringNotContainsString('preprod-user', $output, "The user leaked in the {$format} output.");
        }
    }

    public function test_json_and_junit_outputs_are_valid(): void
    {
        $this->fakeDeployedSite(['GET '.self::SITE.'/.env' => fn () => Http::response('APP_KEY=x')]);

        Artisan::call('deploy:smoke', ['--url' => self::SITE, '--format' => 'json']);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);
        $this->assertFalse($json['passed']);

        Artisan::call('deploy:smoke', ['--url' => self::SITE, '--format' => 'junit']);
        $xml = simplexml_load_string(Artisan::output());
        $this->assertNotFalse($xml);
        $this->assertSame('1', (string) $xml['failures']);
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $this->artisan('deploy:smoke', ['--url' => self::SITE, '--format' => 'yaml'])
            ->expectsOutputToContain('--format must be text, json or junit.')
            ->assertExitCode(2);
    }

    public function test_credentials_in_the_url_are_refused(): void
    {
        $this->artisan('deploy:smoke', ['--url' => 'https://user:'.self::PASSWORD.'@lumina.test'])
            ->expectsOutputToContain('SMOKE_BASIC_USER')
            ->assertExitCode(2);
    }

    public function test_a_missing_url_is_refused(): void
    {
        $this->artisan('deploy:smoke')
            ->expectsOutputToContain('The URL must be absolute, http:// or https://.')
            ->assertExitCode(2);
    }

    public function test_the_junit_report_of_a_passing_run_declares_no_failure(): void
    {
        $this->fakeDeployedSite();

        Artisan::call('deploy:smoke', ['--url' => self::SITE, '--format' => 'junit']);
        $xml = simplexml_load_string(Artisan::output());

        $this->assertNotFalse($xml);
        $this->assertSame('0', (string) $xml['failures']);
        // The release probe is skipped without --expect-release, and JUnit must say so rather than
        // count it as a pass: a CI dashboard would otherwise show 17 green checks for 16 run.
        $this->assertSame('1', (string) $xml['skipped']);
    }

    public function test_it_logs_each_failure_and_a_summary_without_the_credentials(): void
    {
        $log = Log::spy();
        $this->fakeDeployedSite(['GET '.self::SITE.'/.env' => fn () => Http::response('APP_KEY=x')]);

        $this->artisan('deploy:smoke', ['--url' => self::SITE])->assertFailed();

        // error, not warning: a blocking failure is an operation failure — the level follows the
        // severity, per the decision tree of docs/logging-conventions.md.
        $log->shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $level === 'error'
                && $message === 'Smoke check failed'
                && $context['check'] === 'exposure'
                && isset($context['service'], $context['method'], $context['step'])
                && ! str_contains((string) json_encode($context), self::PASSWORD),
        )->once();
        $log->shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === 'Smoke test finished'
                && $context['passed'] === false
                && $context['blocking'] === 1
                && isset($context['service'], $context['method'], $context['step']),
        )->once();
    }
}
