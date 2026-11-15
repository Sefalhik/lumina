<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Translation;

use App\Services\Translation\InvalidTranslationFile;
use App\Services\Translation\TranslationSources;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsLogs;
use Tests\Concerns\UsesTemporaryDirectory;
use Tests\TestCase;

/**
 * The registry of where every translation comes from (LUMN-110).
 */
class TranslationSourcesTest extends TestCase
{
    use AssertsLogs;
    use UsesTemporaryDirectory;

    private string $path;

    private const LOCALES = ['de', 'en', 'it'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = $this->temporaryDirectory().'/registry/sources.json';
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public function test_a_missing_registry_knows_nothing(): void
    {
        $sources = new TranslationSources($this->path);

        $this->assertSame([], $sources->fingerprints('php:nav'));
        $this->assertSame([], $sources->pending('php:nav', 'de'));
    }

    public function test_an_unreadable_registry_is_refused_rather_than_read_as_empty(): void
    {
        mkdir(dirname($this->path), 0755, true);
        file_put_contents($this->path, 'not json');

        $this->expectException(InvalidTranslationFile::class);

        (new TranslationSources($this->path))->fingerprints('php:nav');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function misshapenRegistries(): iterable
    {
        yield 'a source that is not an object' => ['{"php:nav": "oops"}'];
        yield 'sources that are not an object' => ['{"php:nav": {"sources": "x", "pending": {}}}'];
        yield 'pending that is not an object' => ['{"php:nav": {"sources": {}, "pending": 3}}'];
        yield 'a part missing' => ['{"php:nav": {"sources": {}}}'];
        yield 'a fingerprint that is not a string' => ['{"php:nav": {"sources": {"home": 4}, "pending": {}}}'];
        yield 'owed keys that are not a list' => ['{"php:nav": {"sources": {}, "pending": {"de": "home"}}}'];
        yield 'owed keys that are an object' => ['{"php:nav": {"sources": {}, "pending": {"de": {"a": "home"}}}}'];
        yield 'a registry that is a string' => ['"text"'];
    }

    /**
     * A merge conflict resolved by hand can leave valid JSON of the wrong shape: refused, never
     * half-read.
     */
    #[DataProvider('misshapenRegistries')]
    public function test_a_registry_of_the_wrong_shape_is_refused(string $json): void
    {
        mkdir(dirname($this->path), 0755, true);
        file_put_contents($this->path, $json);

        $this->expectException(InvalidTranslationFile::class);

        (new TranslationSources($this->path))->fingerprints('php:nav');
    }

    public function test_an_empty_registry_is_valid(): void
    {
        mkdir(dirname($this->path), 0755, true);
        file_put_contents($this->path, '{}');

        $this->assertSame([], (new TranslationSources($this->path))->fingerprints('php:nav'));
    }

    public function test_the_fingerprint_depends_on_the_value_only(): void
    {
        $this->assertSame(TranslationSources::fingerprint('Accueil'), TranslationSources::fingerprint('Accueil'));
        $this->assertNotSame(TranslationSources::fingerprint('Accueil'), TranslationSources::fingerprint('Accueil '));
    }

    // ── Recording ────────────────────────────────────────────────────────────

    public function test_a_recorded_source_is_read_back_from_another_instance(): void
    {
        (new TranslationSources($this->path))->record('php:nav', ['home' => 'Accueil'], ['de' => []], self::LOCALES);

        $this->assertSame(
            ['home' => TranslationSources::fingerprint('Accueil')],
            (new TranslationSources($this->path))->fingerprints('php:nav'),
        );
    }

    public function test_a_failed_locale_owes_its_keys_and_the_others_owe_nothing(): void
    {
        $sources = new TranslationSources($this->path);
        $sources->record('php:nav', ['home' => 'Accueil', 'cv' => 'CV'], ['de' => ['home', 'cv'], 'en' => [], 'it' => []], self::LOCALES);

        $this->assertSame(['cv', 'home'], $sources->pending('php:nav', 'de'));
        $this->assertSame([], $sources->pending('php:nav', 'en'));
    }

    public function test_a_locale_that_succeeds_later_owes_nothing_any_more(): void
    {
        $sources = new TranslationSources($this->path);
        $sources->record('php:nav', ['home' => 'Accueil'], ['de' => ['home']], self::LOCALES);
        $sources->record('php:nav', ['home' => 'Accueil'], ['de' => []], self::LOCALES);

        $this->assertSame([], $sources->pending('php:nav', 'de'));
    }

    public function test_a_locale_outside_the_run_keeps_what_it_owed(): void
    {
        $sources = new TranslationSources($this->path);
        $sources->record('php:nav', ['home' => 'Accueil'], ['en' => ['home']], self::LOCALES);
        $sources->record('php:nav', ['home' => 'Accueil'], ['de' => []], self::LOCALES);

        $this->assertSame(['home'], $sources->pending('php:nav', 'en'));
    }

    public function test_a_locale_outside_the_run_owes_every_key_whose_french_changed(): void
    {
        // The fingerprints move forward for every locale at once: without this, a run on --locale=de
        // would let en take the new French for a translation it never received.
        $sources = new TranslationSources($this->path);
        $sources->record('php:nav', ['home' => 'Accueil', 'cv' => 'CV'], ['de' => [], 'en' => [], 'it' => []], self::LOCALES);
        $sources->record('php:nav', ['home' => 'Accueil', 'cv' => 'Parcours'], ['de' => []], self::LOCALES);

        $this->assertSame([], $sources->pending('php:nav', 'de'));
        $this->assertSame(['cv'], $sources->pending('php:nav', 'en'));
        $this->assertSame(['cv'], $sources->pending('php:nav', 'it'));
    }

    public function test_a_new_key_is_not_owed_by_the_locales_outside_the_run(): void
    {
        // A new key is missing from their targets: the planner already translates it there.
        $sources = new TranslationSources($this->path);
        $sources->record('php:nav', ['home' => 'Accueil'], ['de' => []], self::LOCALES);
        $sources->record('php:nav', ['home' => 'Accueil', 'cv' => 'CV'], ['de' => []], self::LOCALES);

        $this->assertSame([], $sources->pending('php:nav', 'en'));
    }

    public function test_a_key_removed_from_the_french_is_forgotten_and_owed_no_more(): void
    {
        $sources = new TranslationSources($this->path);
        $sources->record('php:nav', ['home' => 'Accueil', 'cv' => 'CV'], ['de' => ['cv']], self::LOCALES);
        $sources->record('php:nav', ['home' => 'Accueil'], [], self::LOCALES);

        $this->assertSame(['home' => TranslationSources::fingerprint('Accueil')], $sources->fingerprints('php:nav'));
        $this->assertSame([], $sources->pending('php:nav', 'de'));
    }

    public function test_recording_one_source_leaves_the_others_alone(): void
    {
        $sources = new TranslationSources($this->path);
        $sources->record('php:nav', ['home' => 'Accueil'], ['de' => ['home']], self::LOCALES);
        $sources->record('js:ui', ['boot.skip' => 'Ignorer'], ['de' => []], self::LOCALES);

        $this->assertSame(['home'], (new TranslationSources($this->path))->pending('php:nav', 'de'));
    }

    public function test_the_file_is_the_same_whatever_the_order_things_were_recorded_in(): void
    {
        $first = new TranslationSources($this->path);
        $first->record('php:nav', ['home' => 'Accueil', 'cv' => 'CV'], ['en' => ['home'], 'de' => ['cv', 'home']], self::LOCALES);
        $first->record('js:ui', ['boot.skip' => 'Ignorer'], [], self::LOCALES);
        $one = (string) file_get_contents($this->path);
        unlink($this->path);

        $second = new TranslationSources($this->path);
        $second->record('js:ui', ['boot.skip' => 'Ignorer'], [], self::LOCALES);
        $second->record('php:nav', ['cv' => 'CV', 'home' => 'Accueil'], ['de' => ['home', 'cv'], 'en' => ['home']], self::LOCALES);

        $this->assertSame($one, (string) file_get_contents($this->path));
    }

    public function test_numeric_keys_are_recorded_and_owed_as_strings(): void
    {
        $sources = new TranslationSources($this->path);
        $sources->record('php:errors', ['404' => 'Introuvable'], ['de' => [], 'en' => [], 'it' => []], self::LOCALES);
        $sources->record('php:errors', ['404' => 'Absente'], ['de' => []], self::LOCALES);

        $this->assertSame(['404'], (new TranslationSources($this->path))->pending('php:errors', 'en'));
    }

    public function test_the_locales_owing_keys_are_sorted_whatever_order_the_run_gave_them(): void
    {
        (new TranslationSources($this->path))->record('php:nav', ['home' => 'Accueil'], ['it' => ['home'], 'de' => ['home']], ['it', 'en', 'de']);

        $json = (string) file_get_contents($this->path);
        $this->assertLessThan(strpos($json, '"it"'), strpos($json, '"de"'));
    }

    public function test_recording_is_logged_with_its_context(): void
    {
        $this->spyOnLogs();

        (new TranslationSources($this->path))->record('php:nav', ['home' => 'Accueil', 'cv' => 'CV'], ['de' => ['cv']], self::LOCALES);

        $this->assertLogged('info', 'Translation sources recorded', ['step' => 'recorded', 'source' => 'php:nav', 'key_count' => 2, 'pending_locale_count' => 1]);
    }

    public function test_an_entry_with_nothing_pending_stays_an_object_in_json(): void
    {
        (new TranslationSources($this->path))->record('php:nav', ['home' => 'Accueil'], ['de' => []], self::LOCALES);

        $this->assertStringContainsString('"pending": {}', (string) file_get_contents($this->path));
        $this->assertSame([], (new TranslationSources($this->path))->pending('php:nav', 'de'));
    }
}
