<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;

/**
 * Asserts a log call the way `docs/logging-conventions.md` requires it: a static message, and a
 * context carrying `service`, `method` and `step` — plus whatever the test names.
 *
 * Call `spyOnLogs()` before the code under test.
 */
trait AssertsLogs
{
    private ?MockInterface $logSpy = null;

    protected function spyOnLogs(): void
    {
        $this->logSpy = Log::spy();
    }

    /**
     * @param  array<string, mixed>  $context  keys the context must hold, with these exact values
     */
    protected function assertLogged(string $level, string $message, array $context = []): void
    {
        $this->logSpy()->shouldHaveReceived($level)->withArgs(
            fn (string $logged, array $actual = []): bool => $logged === $message && $this->contextMatches($actual, $context),
        )->atLeast()->once();
    }

    /**
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $expected
     */
    private function contextMatches(array $actual, array $expected): bool
    {
        foreach (['service', 'method', 'step'] as $mandatory) {
            if (! is_string($actual[$mandatory] ?? null) || $actual[$mandatory] === '') {
                return false;
            }
        }

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual) || $actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    protected function assertNotLogged(string $level, string $message): void
    {
        $this->logSpy()->shouldNotHaveReceived($level, [$message, Mockery::any()]);
    }

    private function logSpy(): MockInterface
    {
        if ($this->logSpy === null) {
            $this->fail('Call spyOnLogs() before the code under test.');
        }

        return $this->logSpy;
    }
}
