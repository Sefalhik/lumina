<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Report\SmokeReport;
use App\Services\Smoke\Site\SmokeTarget;
use App\Services\Smoke\SmokeTestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Smoke-tests a deployed environment from the outside (LUMN-49). Exit code 1 when a blocking check
 * fails, 0 otherwise — warnings are printed, never fatal. See docs/smoke-tests.md.
 *
 * Basic credentials come from SMOKE_BASIC_USER / SMOKE_BASIC_PASSWORD, never from an option: an
 * option ends up in shell history and in CI logs.
 */
class DeploySmoke extends Command
{
    protected $signature = 'deploy:smoke
        {--url= : Base URL of the environment, e.g. https://preprod.cardascia-it.org}
        {--expect-release= : Commit SHA the environment must be serving}
        {--format=text : text, json or junit}';

    protected $description = 'Smoke-test a deployed environment from the outside';

    public function handle(SmokeTestService $smoke): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['text', 'json', 'junit'], true)) {
            $this->error('--format must be text, json or junit.');

            return self::INVALID;
        }

        try {
            $target = new SmokeTarget(
                (string) $this->option('url'),
                $this->option('expect-release') !== null ? (string) $this->option('expect-release') : null,
                is_string(config('smoke.basic_user')) ? config('smoke.basic_user') : null,
                is_string(config('smoke.basic_password')) ? config('smoke.basic_password') : null,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $checks = $smoke->run($target);
        $report = new SmokeReport($target, $checks);

        $this->line(match ($format) {
            'json' => $report->toJson(),
            'junit' => $report->toJunit(),
            default => $report->toText(),
        });

        $this->log($target, $report, $checks);

        return $report->blocked() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<SmokeCheck>  $checks
     */
    private function log(SmokeTarget $target, SmokeReport $report, array $checks): void
    {
        $context = ['service' => self::class, 'method' => 'handle', 'host' => $target->host()];

        // The level follows the severity, per the decision tree of docs/logging-conventions.md: a
        // blocking failure is an operation failure (error), a warning is an expected, handled one.
        foreach ($checks as $check) {
            if ($check->blocks() || $check->warns()) {
                Log::log($check->blocks() ? 'error' : 'warning', 'Smoke check failed', $context + [
                    'step' => 'check',
                    'check' => $check->id,
                    'severity' => $check->severity->value,
                    'detail' => $check->detail,
                ]);
            }
        }

        Log::info('Smoke test finished', $context + ['step' => 'summary', 'passed' => ! $report->blocked()] + $report->counts());
    }
}
