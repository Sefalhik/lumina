<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\UsesTemporaryDirectory;
use Tests\TestCase;

/**
 * A test that checks nothing has to fail the suite, not pass it.
 *
 * It did not, until LUMN-149. PHPUnit flags a test that performs no assertion as *risky*, prints a
 * line about it, and exits 0. Delete the one line of a test that checks something and the test
 * stays green: the mutation pass of LUMN-149 met three such lines in a single file. `phpunit.xml`
 * now sets `failOnRisky`, and these tests fail the day it is removed — which nothing else would
 * notice, since a suite that stops failing on risky tests stays green.
 *
 * The effect is asserted, not the attribute: a nested PHPUnit runs, under this project's own
 * `phpunit.xml`, a test that checks nothing, and has to fail. A twin that checks something has to
 * pass, so the failure cannot come from a run that breaks for another reason.
 *
 * @see docs/testing/README.md
 */
class RiskyTestsFailTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function test_a_test_that_checks_nothing_fails_the_suite(): void
    {
        $result = $this->runAlone('ChecksNothingTest', '// Nothing is checked.');

        $this->assertSame(1, $result->exitCode(), $result->output());
        $this->assertStringContainsString('did not perform any assertions', $result->output());
    }

    public function test_a_test_that_checks_something_passes_under_the_same_configuration(): void
    {
        $result = $this->runAlone('ChecksSomethingTest', '$this->assertTrue(true);');

        $this->assertSame(0, $result->exitCode(), $result->output());
    }

    private function runAlone(string $class, string $body): ProcessResult
    {
        $file = $this->temporaryDirectory()."/{$class}.php";
        file_put_contents($file, <<<PHP
            <?php

            final class {$class} extends \\PHPUnit\\Framework\\TestCase
            {
                public function test_it(): void
                {
                    {$body}
                }
            }
            PHP);

        // PHP_INI_SCAN_DIR unset as in ComplexityThresholdsTest: the local .env points it at
        // FrankenPHP's configuration. No coverage: phpunit.xml configures a report, which a nested
        // run under PCOV would otherwise produce.
        return Process::path(base_path())
            ->env(['PHP_INI_SCAN_DIR' => false])
            ->run(['vendor/bin/phpunit', '--configuration', 'phpunit.xml', '--no-coverage', $file]);
    }
}
