<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Tests\TestCase;

/**
 * `public/.htaccess` was absent from this repository until 2026-09-14.
 *
 * Development runs on FrankenPHP, which routes every request to
 * `public/index.php` itself and never reads that file, so nothing reported it
 * missing. Under Apache — which is what the hosting provider serves the site
 * with — its absence means only `/` resolves and every other route returns 404.
 *
 * These assertions read the file rather than exercise Apache: this suite has no
 * Apache. That is a real limit, and it is the reason each rule is checked by its
 * effect rather than by a string match on the whole file.
 */
class ApacheFrontControllerTest extends TestCase
{
    private function htaccess(): string
    {
        $path = public_path('.htaccess');

        $this->assertFileExists(
            $path,
            'public/.htaccess is missing: under Apache every route but "/" returns 404.',
        );

        return (string) file_get_contents($path);
    }

    public function test_it_is_versioned_rather_than_ignored(): void
    {
        // It lives next to /public/build, /public/hot and /public/storage, all of
        // which are gitignored. A pattern widened to /public/* would take it out
        // silently, and the failure would only appear on a deployed server.
        $ignored = shell_exec('cd '.escapeshellarg(base_path()).' && git check-ignore public/.htaccess; echo $?');

        $this->assertSame(
            '1',
            trim((string) $ignored),
            'public/.htaccess is gitignored: it will never reach a server.',
        );
    }

    public function test_it_sends_unresolved_paths_to_the_front_controller(): void
    {
        $contents = $this->htaccess();

        $this->assertMatchesRegularExpression(
            '/RewriteCond\s+%\{REQUEST_FILENAME\}\s+!-f/',
            $contents,
            'Nothing excludes existing files from the rewrite.',
        );
        $this->assertMatchesRegularExpression(
            '/RewriteRule\s+\^\s+index\.php\s+\[L\]/',
            $contents,
            'Unresolved paths are not routed to index.php: every route returns 404.',
        );
    }

    public function test_it_refuses_directory_listing(): void
    {
        // Without -Indexes, a directory with no index file is served as a browsable
        // listing. public/ holds the built assets and the favicons directory.
        $this->assertStringContainsString('-Indexes', $this->htaccess());
    }

    public function test_it_forwards_the_authorization_header(): void
    {
        // Under CGI/FastCGI the Authorization header is stripped before PHP sees it.
        // Sanctum's token guard and any future API authentication depend on this rule.
        $this->assertStringContainsString('HTTP_AUTHORIZATION', $this->htaccess());
    }

    public function test_it_redirects_trailing_slashes(): void
    {
        // Routes are declared as Route::prefix('{lang}'), so route('home') generates
        // "/fr" with no trailing slash. Without this rule "/fr/" is a second address
        // for one page, and the canonical tag names only one of them.
        $this->assertMatchesRegularExpression(
            '/RewriteCond\s+%\{REQUEST_URI\}\s+\(\.\+\)\/\$/',
            $this->htaccess(),
        );
        $this->assertMatchesRegularExpression('/RewriteRule\s+\^\s+%1\s+\[L,R=301\]/', $this->htaccess());
    }

    public function test_the_route_it_protects_really_has_no_trailing_slash(): void
    {
        // Pins the premise of the rule above. If the home route ever gained a
        // trailing slash, the .htaccess would be redirecting away from it.
        $path = (string) parse_url(route('home', ['lang' => 'fr']), PHP_URL_PATH);

        $this->assertSame('/fr', $path);
    }
}
