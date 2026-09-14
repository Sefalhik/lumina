<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Tests\TestCase;

/**
 * One driver everywhere a browser or a deployment is involved.
 *
 * Until 2026-09-14 this project ran three session drivers at once: `redis` in
 * development, `file` in CI, `array` under PHPUnit — with a fourth still to be
 * chosen for preproduction. Redis was never a requirement (no job, no queue, no
 * `Redis::` call in `app/`) and it was not free: its write happens in
 * `StartSession::terminate()`, after the response is sent, which cost an explicit
 * `session()->save()` in `routes/e2e.php` and a driver override in `ci.yml`.
 *
 * These assertions pin the decision, not an implementation detail. Reverting any
 * of the three files fails a named test rather than producing a defect three
 * environments away.
 */
class EnvironmentParityTest extends TestCase
{
    private function envExample(): string
    {
        return (string) file_get_contents(base_path('.env.example'));
    }

    /** @return array<string, string> */
    private function declaredVariables(): array
    {
        $variables = [];

        foreach (preg_split('/\R/', $this->envExample()) ?: [] as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $matches) === 1) {
                $variables[$matches[1]] = trim($matches[2], '"');
            }
        }

        return $variables;
    }

    public function test_the_reference_environment_uses_database_backed_drivers(): void
    {
        $declared = $this->declaredVariables();

        $this->assertSame('database', $declared['SESSION_DRIVER'] ?? null);
        $this->assertSame('database', $declared['CACHE_STORE'] ?? null);
        $this->assertSame('sync', $declared['QUEUE_CONNECTION'] ?? null);
    }

    public function test_maintenance_mode_does_not_depend_on_the_database(): void
    {
        // Maintenance mode is often engaged *because* the database is unavailable.
        // The `cache` driver over a database store would need the very thing that
        // failed in order to render the page saying it failed.
        $this->assertSame('file', $this->declaredVariables()['APP_MAINTENANCE_DRIVER'] ?? null);
        $this->assertArrayNotHasKey('APP_MAINTENANCE_STORE', $this->declaredVariables());
    }

    public function test_no_redis_driver_is_designated_anywhere_in_the_reference_environment(): void
    {
        foreach ($this->declaredVariables() as $name => $value) {
            $this->assertNotSame(
                'redis',
                $value,
                "[{$name}] designates Redis again; the hosting provider does not offer it.",
            );
            $this->assertStringStartsNotWith(
                'REDIS_',
                $name,
                "[{$name}] is back in .env.example while nothing reads it.",
            );
        }
    }

    /**
     * The tables those drivers need. `database` session and cache storage fail at
     * the first request if the migrations that create them ever move or vanish.
     */
    public function test_the_tables_those_drivers_need_are_migrated(): void
    {
        foreach (['sessions', 'cache', 'cache_locks', 'jobs'] as $table) {
            $this->assertTrue(
                collect(glob(database_path('migrations/*.php')) ?: [])
                    ->contains(fn (string $file): bool => str_contains(
                        (string) file_get_contents($file),
                        "Schema::create('{$table}'",
                    )),
                "No migration creates the [{$table}] table.",
            );
        }
    }

    public function test_the_ci_workflow_does_not_restate_those_drivers(): void
    {
        // Restating a value that already matches .env.example is exactly how the
        // two drifted apart before: the override outlived the reason for it, and
        // CI became the one place running a different session driver.
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        foreach (['SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION', 'APP_MAINTENANCE_DRIVER'] as $variable) {
            $this->assertStringNotContainsString(
                $variable.':',
                $workflow,
                "ci.yml overrides [{$variable}] again instead of reading .env.example.",
            );
        }
    }

    /**
     * The deliberate exception, pinned so nobody "aligns" it.
     *
     * In-memory doubles inside a single process are what a unit harness is for,
     * and they have no behaviour a real driver lacks. The divergence that cost
     * this project was Redis's asynchronous write — a property of one
     * implementation, not of test doubles.
     */
    public function test_the_test_harness_keeps_in_memory_drivers_on_purpose(): void
    {
        $phpunit = (string) file_get_contents(base_path('phpunit.xml'));

        $this->assertStringContainsString('<env name="SESSION_DRIVER" value="array"/>', $phpunit);
        $this->assertStringContainsString('<env name="CACHE_STORE" value="array"/>', $phpunit);
    }
}
