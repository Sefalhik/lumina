<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Re-bootstraps the application under a different APP_ENV, inside a test.
 *
 * `bootstrap/app.php` decides at boot time which routes exist and which providers
 * are registered, so those decisions cannot be asserted by changing config: the
 * only way to observe them is to boot again. `createApplication()` re-requires
 * `bootstrap/app.php`, and the environment is read from the process environment
 * during that boot — hence three writes, because Laravel's Env repository reads
 * `$_ENV`, `$_SERVER` and `getenv()`, and phpunit.xml populates the first two.
 */
trait RebootsInEnvironment
{
    private ?string $originalAppEnv = null;

    protected function rebootIn(string $environment): void
    {
        $this->originalAppEnv ??= (string) ($_ENV['APP_ENV'] ?? 'testing');

        putenv('APP_ENV='.$environment);
        $_ENV['APP_ENV'] = $environment;
        $_SERVER['APP_ENV'] = $environment;

        $this->refreshApplication();

        // Without this, a reboot that silently stopped working would leave every
        // assertion in every test using this trait passing for the wrong reason —
        // they would all be observing the `testing` environment and reporting
        // green. A guard that cannot fail is worse than no guard.
        if (app()->environment() !== $environment) {
            self::fail("The application did not reboot in [{$environment}]; it is in [".app()->environment().'].');
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalAppEnv !== null) {
            putenv('APP_ENV='.$this->originalAppEnv);
            $_ENV['APP_ENV'] = $this->originalAppEnv;
            $_SERVER['APP_ENV'] = $this->originalAppEnv;
        }

        parent::tearDown();
    }
}
