<?php

declare(strict_types=1);

namespace Tests\Feature\Development;

use Tests\TestCase;

/**
 * The paths the development server watches are written twice, and nothing but this test makes them agree.
 *
 * `npm start` runs FrankenPHP on the repository's Caddyfile, whose `watch` directives restart the
 * workers when the code changes — they keep the application booted between requests, so without
 * them an edit is only seen after a manual restart. `php artisan octane:start --watch` generates
 * its own Caddyfile from the `watch` list of config/octane.php. A Caddyfile cannot read a PHP
 * configuration file, and generating one for `npm start` would cost a build step to save nine
 * lines (LUMN-88).
 *
 * A list repeated by hand drifts without a sound: a directory added to one is watched by one
 * command and not by the other, and nothing says so until an edit seems to be ignored.
 *
 * What it does not prove: that FrankenPHP restarts the workers. CI has no FrankenPHP binary — it
 * serves development only — so that proof is manual, recorded on LUMN-88.
 *
 * @see docs/architecture.md#the-development-server
 */
class DevServerWatchParityTest extends TestCase
{
    public function test_the_caddyfile_watches_what_octane_watches(): void
    {
        $this->assertSame(
            config('octane.watch'),
            $this->watchedByCaddyfile(),
            "The Caddyfile and config/octane.php no longer watch the same paths.\n".
            '  → One list was changed alone. Write the same paths, in the same order, in both.',
        );
    }

    public function test_something_is_watched(): void
    {
        // Two empty lists agree, and leave every edit unseen. Octane would then fall back to
        // FrankenPHP's default pattern — the bare `watch` the next test refuses.
        $this->assertNotEmpty(
            config('octane.watch'),
            "config/octane.php watches nothing.\n".
            '  → Without a list, the workers never restart, or Octane falls back to a bare `watch`.',
        );
    }

    public function test_no_watch_directive_is_bare(): void
    {
        // A bare `watch` takes FrankenPHP's default pattern, every PHP file under the working
        // directory. Blade rewrites storage/framework/views on each compile: the workers would
        // restart on their own output, again and again.
        $this->assertNotContains(
            '',
            $this->watchedByCaddyfile(),
            "The Caddyfile has a `watch` directive without a path.\n".
            '  → Name the paths: the default pattern covers storage/framework/views.',
        );
    }

    public function test_no_watched_path_reaches_storage(): void
    {
        // The same loop through an explicit path: `storage`, `.`, a pattern starting with a
        // wildcard, a `..` that climbs back out of a source directory, or an absolute path — whose
        // first segment is empty — all can cover the compiled views. A path is accepted only when
        // it names a source directory or file of the project, relative to it, and never leaves it.
        foreach (config('octane.watch') as $path) {
            $segments = explode('/', (string) preg_replace('#^(\./)+#', '', $path));

            $this->assertTrue(
                ! in_array('..', $segments, true)
                    && ! in_array($segments[0], ['', '.', 'storage'], true)
                    && ! str_contains($segments[0], '*'),
                "The watched path '{$path}' can cover storage/framework/views.\n".
                '  → Blade writes there on every compile: name a source directory, relative to the project.',
            );
        }
    }

    /**
     * @return list<string>
     */
    private function watchedByCaddyfile(): array
    {
        $caddyfile = file_get_contents(base_path('Caddyfile'));

        $this->assertIsString($caddyfile, 'The Caddyfile cannot be read.');

        // Comments start with `#` and never match: the explanation above the list names `watch`.
        preg_match_all('/^[ \t]*watch\b[ \t]*(\S*)[ \t]*$/m', $caddyfile, $directives);

        return $directives[1];
    }
}
