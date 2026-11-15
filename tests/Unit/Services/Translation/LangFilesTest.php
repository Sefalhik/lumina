<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Translation;

use App\Services\Translation\InvalidTranslationFile;
use App\Services\Translation\LangFiles;
use Tests\Concerns\UsesTemporaryDirectory;
use Tests\TestCase;

/**
 * The two formats translations live in, read and written as flat maps (LUMN-110).
 */
class LangFilesTest extends TestCase
{
    use UsesTemporaryDirectory;

    private LangFiles $files;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new LangFiles;
        $this->directory = $this->temporaryDirectory();
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public function test_a_file_that_does_not_exist_reads_as_empty(): void
    {
        $this->assertSame([], $this->files->read($this->directory.'/de/nav.php', LangFiles::PHP));
    }

    public function test_a_php_file_reads_flat(): void
    {
        file_put_contents($this->directory.'/v.php', "<?php\nreturn ['required' => 'Requis', 'attributes' => ['email' => 'e-mail']];\n");

        $this->assertSame(
            ['required' => 'Requis', 'attributes.email' => 'e-mail'],
            $this->files->read($this->directory.'/v.php', LangFiles::PHP),
        );
    }

    public function test_a_json_file_reads_flat(): void
    {
        file_put_contents($this->directory.'/fr.json', '{"boot": {"skip": "Ignorer"}}');

        $this->assertSame(['boot.skip' => 'Ignorer'], $this->files->read($this->directory.'/fr.json', LangFiles::JSON));
    }

    public function test_a_php_file_that_returns_no_array_is_refused(): void
    {
        file_put_contents($this->directory.'/nav.php', "<?php\nreturn 'oops';\n");

        $this->assertRefused('does not return an array', fn () => $this->files->read($this->directory.'/nav.php', LangFiles::PHP));
    }

    public function test_a_json_file_that_does_not_decode_is_refused(): void
    {
        file_put_contents($this->directory.'/de.json', '{{{ not json');

        $this->assertRefused('is not a JSON object', fn () => $this->files->read($this->directory.'/de.json', LangFiles::JSON));
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    public function test_a_php_file_is_written_nested_and_creates_its_directory(): void
    {
        $path = $this->directory.'/de/validation.php';

        $this->files->write($path, LangFiles::PHP, ['required' => 'Erforderlich', 'attributes.email' => 'E-Mail']);

        $this->assertSame(['required' => 'Erforderlich', 'attributes' => ['email' => 'E-Mail']], include $path);
    }

    public function test_quotes_and_backslashes_survive_a_php_round_trip(): void
    {
        $path = $this->directory.'/de/nav.php';
        $strings = ['quote' => "l'accueil", 'backslash' => 'C:\\dossier\\', 'both' => "\\'"];

        $this->files->write($path, LangFiles::PHP, $strings);

        $this->assertSame($strings, $this->files->read($path, LangFiles::PHP));
    }

    public function test_a_json_file_keeps_unicode_and_slashes_readable(): void
    {
        $path = $this->directory.'/el.json';

        $this->files->write($path, LangFiles::JSON, ['boot.skip' => 'Παράλειψη', 'url' => 'a/b']);

        $content = (string) file_get_contents($path);
        $this->assertStringContainsString('Παράλειψη', $content);
        $this->assertStringContainsString('"a/b"', $content);
        $this->assertStringEndsWith("}\n", $content);
    }

    /**
     * The seam between the writer and the files it writes: every committed translation, read and
     * written back in the order of its French source, must come out byte for byte. Otherwise the
     * first run after a change of the writer rewrites 184 files for two keys.
     */
    public function test_every_committed_translation_round_trips_byte_for_byte(): void
    {
        $sources = array_map(fn (string $file) => [LangFiles::PHP, lang_path('%s/'.basename($file))], glob(lang_path('fr/*.php')) ?: []);
        $sources[] = [LangFiles::JSON, resource_path('js/i18n/%s.json')];
        $this->assertGreaterThan(1, count($sources));

        foreach ($sources as [$format, $pattern]) {
            $french = $this->files->read(sprintf($pattern, 'fr'), $format);
            foreach (array_diff(config('i18n.supported_locales'), ['fr']) as $locale) {
                $original = sprintf($pattern, $locale);
                $target = $this->files->read($original, $format);
                $copy = $this->directory.'/'.$locale.'-'.basename($original);

                $this->files->write($copy, $format, array_map(fn (string $key) => $target[$key], array_combine(array_keys($french), array_keys($french))));

                $this->assertFileEquals($original, $copy, "{$original} does not round-trip");
            }
        }
    }

    // ── Flattening ───────────────────────────────────────────────────────────

    public function test_flatten_uses_dot_notation_at_any_depth(): void
    {
        $this->assertSame(['a.b.c' => 'valeur', 'd' => 'x'], $this->files->flatten(['a' => ['b' => ['c' => 'valeur']], 'd' => 'x']));
    }

    public function test_flatten_casts_scalars_to_strings(): void
    {
        $this->assertSame(['count' => '42', 'on' => '1'], $this->files->flatten(['count' => 42, 'on' => true]));
    }

    public function test_flatten_reads_a_value_that_is_no_text_as_empty(): void
    {
        // A `null` left in a French file is an empty source: never sent, never due.
        $this->assertSame(['vide' => ''], $this->files->flatten(['vide' => null]));
    }

    public function test_unflatten_is_the_inverse_of_flatten(): void
    {
        $nested = ['nav' => ['home' => 'Accueil', 'cv' => 'CV'], 'attributes' => ['email' => 'e-mail'], 'top' => 'x'];

        $this->assertSame($nested, $this->files->unflatten($this->files->flatten($nested)));
    }

    private function assertRefused(string $reason, callable $read): void
    {
        try {
            $read();
        } catch (InvalidTranslationFile $e) {
            $this->assertStringContainsString($reason, $e->getMessage());

            return;
        }

        $this->fail('The file was read instead of refused.');
    }
}
