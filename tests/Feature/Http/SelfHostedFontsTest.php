<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * The site serves its own fonts (LUMN-102): Google Fonts would send every visitor's address to
 * Google. This proves that nothing points there any more, and that the policy would not allow it.
 * The fonts named by the stylesheets are checked against the packages by scripts/__tests__/fonts.test.js
 * (it reads node_modules, which only the JS job installs); what the browser renders, by
 * tests/e2e/fonts.spec.js.
 */
class SelfHostedFontsTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE_FONT_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com'];

    public function test_nothing_in_the_front_end_loads_a_font_from_google(): void
    {
        $offending = [];
        foreach (['views', 'css', 'js'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path($directory)));
            foreach ($files as $file) {
                /** @var SplFileInfo $file */
                if (! $file->isFile()) {
                    continue;
                }
                $content = (string) file_get_contents($file->getPathname());
                foreach (self::GOOGLE_FONT_HOSTS as $host) {
                    if (str_contains($content, $host)) {
                        $offending[] = str_replace(base_path().'/', '', $file->getPathname())." → {$host}";
                    }
                }
            }
        }

        $this->assertSame([], $offending, 'Fonts are served by the site, never by Google: docs/architecture.md#fonts.');
    }

    public function test_every_layout_loads_the_stylesheet_that_imports_the_fonts(): void
    {
        // The fonts are imported by resources/css/app.css alone. A layout that stopped loading it
        // would render its pages in the fallback font, and only the public and authentication
        // layouts are checked in a browser (tests/e2e/fonts.spec.js).
        foreach (['app', 'auth', 'admin'] as $layout) {
            $view = (string) file_get_contents(resource_path("views/layouts/{$layout}.blade.php"));

            $this->assertSame(1, preg_match('/@vite\(\[([^\]]*)\]\)/', $view, $assets), "The {$layout} layout has no @vite([...]).");
            $this->assertStringContainsString("'resources/css/app.css'", $assets[1], "The {$layout} layout does not load the fonts.");
        }
    }

    public function test_the_policy_allows_fonts_and_stylesheets_from_the_site_alone(): void
    {
        $policy = (string) $this->get('https://localhost/fr')->assertOk()->headers->get('Content-Security-Policy-Report-Only');

        foreach (['font-src', 'style-src'] as $directive) {
            $this->assertSame(1, preg_match("/(?:^|; ){$directive} ([^;]+)/", $policy, $match), "No {$directive} in the policy.");
            $sources = explode(' ', $match[1]);
            $foreign = array_filter($sources, fn (string $source) => $source !== "'self'" && ! str_starts_with($source, "'nonce-"));

            $this->assertSame([], array_values($foreign), "{$directive} allows a source outside the site.");
        }
    }
}
