<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use Illuminate\Foundation\Vite;
use Tests\Concerns\RebootsInEnvironment;
use Tests\TestCase;

/**
 * In `testing`, the application serves the compiled build even while a Vite dev server runs.
 *
 * The end-to-end servers run in `testing` (LUMN-106). Until then they ran in `local`, and on a
 * developer's machine, where `npm start` keeps `public/hot` in place, every page they served loaded
 * its scripts from the dev server instead of the build: what was tested was not what is shipped,
 * and a module lost on the network unmounted every Vue island at random. Two specs failed that way
 * on 2026-11-21; CI, which has no dev server, never saw it.
 *
 * The instance asserted is the one `@vite` renders with — `app(Vite::class)`, a singleton — after
 * Tests\TestCase's fake is put back: the fake answers anything, and would pass with the provider's
 * line deleted. The real `public/hot` is never touched: it exists while a developer's Vite runs.
 *
 * @see docs/testing/README.md
 */
class ViteDevServerIsIgnoredInTestingTest extends TestCase
{
    use RebootsInEnvironment;

    public function test_testing_never_reads_the_hot_file_a_dev_server_writes(): void
    {
        $this->withVite();
        $vite = app(Vite::class);

        $this->assertNotSame(public_path('hot'), $vite->hotFile());
        $this->assertFileDoesNotExist($vite->hotFile());
    }

    public function test_a_developer_machine_still_uses_the_dev_server(): void
    {
        // No withVite() here: it would put back the instance captured before the reboot, the
        // `testing` one. The rebooted application has the real Vite already — the fake is only
        // installed by Tests\TestCase::setUp().
        $this->rebootIn('local');

        $this->assertSame(public_path('hot'), app(Vite::class)->hotFile());
    }
}
