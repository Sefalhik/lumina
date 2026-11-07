<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * How SITE_INDEXABLE becomes config('seo.indexable') (LUMN-37), and what stays around it.
 *
 * The direction of an error is the point: a site left out of the index by mistake shows and is
 * fixed in one line; a preprod indexed by mistake is not taken back. Only a recognised boolean may
 * make the site indexable.
 */
class IndexabilityTest extends TestCase
{
    private const VARIABLE = 'SITE_INDEXABLE';

    protected function tearDown(): void
    {
        $this->forget();

        parent::tearDown();
    }

    /** Written where Laravel's Env repository reads: $_ENV, $_SERVER and getenv(). */
    private function set(string $value): void
    {
        putenv(self::VARIABLE.'='.$value);
        $_ENV[self::VARIABLE] = $value;
        $_SERVER[self::VARIABLE] = $value;
    }

    private function forget(): void
    {
        putenv(self::VARIABLE);
        unset($_ENV[self::VARIABLE], $_SERVER[self::VARIABLE]);
    }

    private function indexable(): mixed
    {
        /** @var array{indexable: mixed} $config */
        $config = require base_path('config/seo.php');

        return $config['indexable'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesThatAllowIndexing(): iterable
    {
        yield 'true' => ['true'];
        yield 'TRUE' => ['TRUE'];
        yield '1' => ['1'];
        yield 'yes' => ['yes'];
        yield 'on' => ['on'];
    }

    #[DataProvider('valuesThatAllowIndexing')]
    public function test_a_recognised_true_makes_the_site_indexable(string $value): void
    {
        $this->set($value);

        $this->assertTrue($this->indexable());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesThatForbidIndexing(): iterable
    {
        yield 'false' => ['false'];
        yield '0' => ['0'];
        yield 'empty' => [''];
        yield 'a typo' => ['flase'];
        yield 'French' => ['oui'];
        yield 'a near miss' => ['truee'];
        yield 'two' => ['2'];
        yield 'null' => ['null'];
    }

    #[DataProvider('valuesThatForbidIndexing')]
    public function test_anything_else_keeps_the_site_out_of_the_index(string $value): void
    {
        $this->set($value);

        $this->assertFalse($this->indexable());
    }

    public function test_an_absent_variable_keeps_the_site_out_of_the_index(): void
    {
        $this->forget();

        $this->assertFalse($this->indexable());
    }

    public function test_the_value_is_a_boolean_never_the_raw_string(): void
    {
        // A string "false" reaching the service would be truthy in any loose comparison downstream.
        $this->set('false');

        $this->assertIsBool($this->indexable());
    }

    public function test_robots_txt_stays_static_and_forbids_no_crawling(): void
    {
        // A crawler forbidden to fetch a page never sees its noindex — and may index the bare URL
        // anyway, from a link. Non-indexing goes through X-Robots-Tag alone.
        $path = public_path('robots.txt');
        $this->assertFileExists($path);

        $rules = array_filter(array_map('trim', (array) file($path)), fn (string $line) => $line !== '');
        $this->assertContains('User-agent: *', $rules);

        foreach ($rules as $rule) {
            if (stripos($rule, 'disallow:') === 0) {
                $this->assertSame('', trim(substr($rule, strlen('disallow:'))), "robots.txt forbids crawling: {$rule}");
            }
        }
    }

    public function test_the_example_environment_declares_the_variable_in_a_commented_seo_block(): void
    {
        $lines = file(base_path('.env.example'), FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);

        $at = array_search(self::VARIABLE.'=true', $lines, true);
        $this->assertIsInt($at, '.env.example must declare SITE_INDEXABLE=true, the nominal case.');

        // Its block starts at the nearest blank line above, and opens with an "# SEO" comment.
        $start = $at;
        while ($start > 0 && trim($lines[$start - 1]) !== '') {
            $start--;
        }
        $this->assertStringStartsWith('# SEO', $lines[$start], 'SITE_INDEXABLE opens its own commented block.');
        $this->assertNotSame(count($lines) - 1, $at, 'Not appended at the end of the file.');
    }
}
