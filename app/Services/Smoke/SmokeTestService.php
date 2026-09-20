<?php

declare(strict_types=1);

namespace App\Services\Smoke;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\CertificateInspector;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\Site\SmokeTarget;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the smoke catalogue against a deployed environment (LUMN-49).
 *
 * A smoke test does not check features — the E2E suite does that in CI. It checks the deployment:
 * every way a deployment can break. One probe per class, in SmokeCatalogue order; the shared work —
 * the warm-up, the public pages — happens once, in DeployedSite.
 *
 * Every probe runs, whatever the one before it returned **or threw**. A report that stops at the
 * first failure hides the second, and until 2026-09-20 that principle only held for failures a
 * probe *returned*: one uncaught exception killed the entire run, printing a stack trace and no
 * report at all — the tool whose job is to report failures, failing to report.
 *
 * The catch below is the only one of its kind in the suite, and it lives here on purpose: a probe's
 * own unit test calls check() directly and must keep seeing raw exceptions, or a bug becomes
 * invisible in the one place it is looked for. See docs/smoke-tests.md.
 */
final class SmokeTestService
{
    public function __construct(
        private readonly Container $container,
        private readonly CertificateInspector $certificates,
    ) {}

    /**
     * @return list<SmokeCheck> one result per probe, in catalogue order
     */
    public function run(SmokeTarget $target): array
    {
        $site = new DeployedSite($target, $this->certificates);

        return array_map(
            fn (string $probe): SmokeCheck => $this->runProbe($probe, $site, $target),
            SmokeCatalogue::PROBES,
        );
    }

    /**
     * @param  class-string<SmokeProbe>  $probe
     */
    private function runProbe(string $probe, DeployedSite $site, SmokeTarget $target): SmokeCheck
    {
        // Resolving the probe and asking it its name can fail on their own — an unresolvable
        // dependency, a constructor that throws — and they fail before there is anything to name
        // the result with. Two catches rather than one nested in the other.
        try {
            $instance = $this->container->make($probe);
            $id = $instance->id();
            $label = $instance->label();
        } catch (Throwable $e) {
            return $this->crashed(class_basename($probe), $probe, 'resolve', $e, $target);
        }

        try {
            return $instance->check($site);
        } catch (Throwable $e) {
            return $this->crashed($id, $label, 'check', $e, $target);
        }
    }

    /**
     * A probe that could not answer is never a pass: the deployment may well be healthy, but this
     * run cannot say so, and a release is not promoted on an incomplete report.
     */
    private function crashed(string $id, string $label, string $step, Throwable $e, SmokeTarget $target): SmokeCheck
    {
        Log::error('Smoke probe crashed', [
            'service' => self::class,
            'method' => 'runProbe',
            'step' => $step,
            'probe' => $label,
            'exception' => $e,
        ]);

        return SmokeCheck::fail(
            $id,
            $label,
            'The probe itself failed: '.$target->redact($e::class.' — '.$e->getMessage()),
            'This is a defect in the smoke suite, not necessarily in the deployment — but the run is incomplete, so nothing here says the release is safe. Read the stack trace in storage/logs/laravel.log, fix the probe, and run the command again.',
        );
    }
}
