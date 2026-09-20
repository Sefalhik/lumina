<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke;

use App\Enums\SmokeStatus;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\SmokeTarget;
use App\Services\Smoke\Probes\HealthProbe;
use App\Services\Smoke\SmokeCatalogue;
use App\Services\Smoke\SmokeTestService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Concerns\FakesDeployedSite;
use Tests\Support\Smoke\CrashingProbe;
use Tests\Support\Smoke\UnresolvableProbe;
use Tests\TestCase;

/**
 * The orchestration, not the probes: each probe has its own test beside it, in Probes/.
 *
 * What is pinned here is what only the whole run can show — that every probe runs, in the catalogue
 * order, that a failure never stops the ones after it, and that nothing writes.
 */
class SmokeTestServiceTest extends TestCase
{
    use FakesDeployedSite;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        CrashingProbe::$message = 'Undefined array key "body"';
        config([
            'smoke.warmup_attempts' => 2,
            'smoke.warmup_sleep_ms' => 0,
            'smoke.response_time_warning_ms' => 60_000,
            'i18n.indexable_locales' => ['fr', 'en', 'de', 'it', 'nl'],
        ]);
    }

    /**
     * @return list<SmokeCheck>
     */
    private function run_(array $overrides = []): array
    {
        $this->fakeDeployedSite($overrides);

        return $this->app->make(SmokeTestService::class)->run(new SmokeTarget(self::SITE, self::SITE_RELEASE));
    }

    public function test_every_probe_of_the_catalogue_runs_in_order(): void
    {
        $checks = $this->run_();

        $this->assertCount(count(SmokeCatalogue::PROBES), $checks);
        $expected = array_map(fn (string $probe): string => $this->app->make($probe)->id(), SmokeCatalogue::PROBES);
        $this->assertSame($expected, array_map(fn (SmokeCheck $check): string => $check->id, $checks));
    }

    public function test_a_healthy_site_passes_every_probe(): void
    {
        foreach ($this->run_() as $check) {
            $this->assertSame(SmokeStatus::Passed, $check->status, "{$check->id}: {$check->detail}");
        }
    }

    public function test_a_failure_never_stops_the_probes_after_it(): void
    {
        // The E2E backdoor is the eighth probe: the nine after it must still have run.
        $checks = $this->run_(['GET '.self::SITE.'/e2e/admin-auth' => fn () => Http::response('', 302)]);

        $this->assertCount(count(SmokeCatalogue::PROBES), $checks);
        $this->assertTrue($checks[7]->blocks());
        $this->assertSame(SmokeStatus::Passed, $checks[16]->status);
    }

    public function test_a_site_that_is_entirely_down_still_yields_a_full_report(): void
    {
        $log = Log::spy();
        $down = array_map(fn () => fn () => Http::failedConnection(), $this->healthySiteRoutes());

        $checks = $this->run_($down);

        $this->assertCount(count(SmokeCatalogue::PROBES), $checks);
        // The identities have to hold on the failing path too: a probe returning a neighbour's id
        // when it fails would silently rename the failure in every report and CI annotation.
        $expected = array_map(fn (string $probe): string => $this->app->make($probe)->id(), SmokeCatalogue::PROBES);
        $this->assertSame($expected, array_map(fn (SmokeCheck $check): string => $check->id, $checks));

        foreach ($checks as $check) {
            if ($check->status === SmokeStatus::Failed) {
                $this->assertNotSame('', $check->remedy, "{$check->id} failed without saying what to do.");
            }
        }
        $log->shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'Smoke request could not connect'
                && isset($context['service'], $context['method'], $context['step'], $context['exception']),
        );
    }

    public function test_the_shared_requests_are_made_once_for_the_whole_run(): void
    {
        // Five probes read the public pages and two read /up: fetching them per probe would multiply
        // the load on a site that may already be struggling.
        $this->run_();

        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->url() === self::SITE.'/up'));
        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->url() === self::SITE.'/de'));
    }

    /**
     * The defect this whole group exists for: until 2026-09-20 one uncaught exception killed the
     * run, printing a stack trace instead of a report — on the tool whose job is to report.
     */
    public function test_a_probe_that_throws_never_stops_the_run(): void
    {
        $this->app->bind(HealthProbe::class, fn (): CrashingProbe => new CrashingProbe);

        $checks = $this->run_();

        $this->assertCount(count(SmokeCatalogue::PROBES), $checks);
        $this->assertTrue($checks[1]->blocks(), 'A probe that could not answer is not a pass.');
        $this->assertStringContainsString('The probe itself failed', $checks[1]->detail);
        $this->assertStringContainsString('RuntimeException', $checks[1]->detail);
        $this->assertNotSame('', $checks[1]->remedy);
        // Everything after it still ran: that is the whole point.
        $this->assertSame(SmokeStatus::Passed, $checks[count(SmokeCatalogue::PROBES) - 1]->status);
    }

    public function test_a_crashed_probe_says_which_probe_crashed(): void
    {
        $this->app->bind(HealthProbe::class, fn (): CrashingProbe => new CrashingProbe);

        $checks = $this->run_();

        $this->assertSame('crashing', $checks[1]->id);
        $this->assertSame('A probe that throws instead of answering', $checks[1]->label);
    }

    /**
     * Resolution fails before the probe can say its own name, so the report falls back to the class
     * the catalogue names — which is what the reader has to go and fix.
     */
    public function test_a_probe_the_container_cannot_build_is_reported_rather_than_fatal(): void
    {
        $this->app->bind(HealthProbe::class, UnresolvableProbe::class);

        $checks = $this->run_();

        $this->assertCount(count(SmokeCatalogue::PROBES), $checks);
        $this->assertTrue($checks[1]->blocks());
        $this->assertSame('HealthProbe', $checks[1]->id);
    }

    public function test_a_crash_is_logged_at_error_with_the_conventional_context(): void
    {
        $log = Log::spy();
        $this->app->bind(HealthProbe::class, fn (): CrashingProbe => new CrashingProbe);

        $this->run_();

        $log->shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'Smoke probe crashed'
                && isset($context['service'], $context['method'], $context['step'], $context['probe'])
                && $context['exception'] instanceof RuntimeException,
        );
    }

    /**
     * The rule that credentials never reach a report holds for the lines this suite composes. The
     * message of an exception a probe threw is composed by nobody, and it lands in the report like
     * any other detail.
     */
    public function test_a_crash_never_leaks_the_credentials(): void
    {
        CrashingProbe::$message = 'Failed to authenticate with hunter2';
        $this->app->bind(HealthProbe::class, fn (): CrashingProbe => new CrashingProbe);
        $this->fakeDeployedSite();

        $target = new SmokeTarget(self::SITE, self::SITE_RELEASE, 'smoke', 'hunter2');
        $checks = $this->app->make(SmokeTestService::class)->run($target);

        $this->assertStringNotContainsString('hunter2', $checks[1]->detail);
        $this->assertStringContainsString('***', $checks[1]->detail);
    }

    public function test_no_probe_writes_anything(): void
    {
        $this->run_();

        // PATCH is the one non-GET request, and the router refuses it before any controller runs.
        $methods = Http::recorded()->map(fn (array $pair): string => $pair[0]->method())->unique()->sort()->values()->all();
        $this->assertSame(['GET', 'PATCH'], $methods);
        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->method() === 'PATCH'));
    }
}
