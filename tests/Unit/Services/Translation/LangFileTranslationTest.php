<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Translation;

use App\Services\AnthropicTranslator;
use App\Services\Translation\LangFiles;
use App\Services\Translation\LangFileTranslation;
use App\Services\Translation\TextFormat;
use App\Services\Translation\TranslationContext;
use App\Services\Translation\TranslationEstimate;
use App\Services\Translation\TranslationPlanner;
use App\Services\Translation\TranslationSources;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsLogs;
use Tests\Concerns\FakesTranslationApi;
use Tests\Concerns\UsesTemporaryDirectory;
use Tests\TestCase;

/**
 * What `i18n:translate` does to the files (LUMN-110): the keys that need a translation, and
 * nothing else — the repository is the reference.
 */
class LangFileTranslationTest extends TestCase
{
    use AssertsLogs;
    use FakesTranslationApi;
    use UsesTemporaryDirectory;

    private const LOCALES = ['de', 'en'];

    private string $lang;

    private string $js;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lang = $this->temporaryDirectory().'/lang';
        $this->js = $this->temporaryDirectory().'/js/i18n';
        $this->writePhp('fr', ['home' => 'Accueil', 'cv' => 'CV']);
        $this->writeJson('fr', ['boot' => ['skip' => 'Ignorer']]);
    }

    // ── A first run, and the one after it ────────────────────────────────────

    public function test_a_first_run_translates_every_file_into_every_locale(): void
    {
        $this->fakeTranslations();

        $this->assertTrue($this->translate());

        $this->assertSame(['home' => 'DE Accueil', 'cv' => 'DE CV'], include $this->lang.'/de/nav.php');
        $this->assertSame(['boot' => ['skip' => 'EN Ignorer']], $this->readJson('en'));
    }

    public function test_a_complete_pass_marks_its_sources_and_a_second_pass_does_nothing(): void
    {
        $this->fakeTranslations();
        $this->translate();
        $sent = count(Http::recorded());

        $this->assertSame([], $this->service()->plan(self::LOCALES, false)->tasks);
        $this->assertTrue($this->translate());
        $this->assertCount($sent, Http::recorded());
    }

    public function test_translations_already_in_the_repository_are_adopted_without_a_request(): void
    {
        $this->writeComplete();
        $this->fakeTranslations();

        $this->assertTrue($this->translate());

        Http::assertNothingSent();
        $this->assertFileExists($this->registry());
        $this->assertSame(['cv', 'home'], array_keys((new TranslationSources($this->registry()))->fingerprints('php:nav')));
    }

    // ── Only what needs it ───────────────────────────────────────────────────

    public function test_a_new_french_key_is_translated_everywhere_and_no_other_key_changes(): void
    {
        $this->writeComplete();
        $this->fakeTranslations();
        $this->translate();
        $this->writePhp('fr', ['home' => 'Accueil', 'cv' => 'CV', 'blog' => 'Blog']);

        $this->assertTrue($this->translate());

        $this->assertSame(['de' => ['blog'], 'en' => ['blog']], $this->sentKeysByLocale());
        $this->assertSame(['home' => 'Startseite', 'cv' => 'Lebenslauf', 'blog' => 'DE Blog'], include $this->lang.'/de/nav.php');
    }

    public function test_a_hand_correction_in_a_target_survives_the_next_run(): void
    {
        $this->writeComplete();
        $this->fakeTranslations();
        $this->translate();
        $this->writePhp('de', ['home' => 'Zur Startseite', 'cv' => 'Lebenslauf']);
        $this->writePhp('fr', ['home' => 'Accueil', 'cv' => 'CV', 'blog' => 'Blog']);

        $this->translate();

        $this->assertSame('Zur Startseite', (include $this->lang.'/de/nav.php')['home']);
    }

    public function test_a_key_whose_french_changed_is_translated_again_and_only_it(): void
    {
        $this->writeComplete();
        $this->fakeTranslations();
        $this->translate();
        $this->writePhp('fr', ['home' => 'Accueil', 'cv' => 'Parcours']);

        $this->translate();

        $this->assertSame(['de' => ['cv'], 'en' => ['cv']], $this->sentKeysByLocale());
        $this->assertSame(['home' => 'Startseite', 'cv' => 'DE Parcours'], include $this->lang.'/de/nav.php');
    }

    public function test_a_key_removed_in_french_disappears_from_every_target(): void
    {
        $this->writeComplete();
        $this->fakeTranslations();
        $this->translate();
        $this->writePhp('fr', ['home' => 'Accueil']);

        $this->assertTrue($this->translate());

        Http::assertNothingSent();
        $this->assertSame(['home' => 'Startseite'], include $this->lang.'/de/nav.php');
        $this->assertSame(['home' => 'Home'], include $this->lang.'/en/nav.php');
    }

    public function test_a_file_with_nothing_to_do_is_not_rewritten(): void
    {
        $this->writeComplete();
        file_put_contents($this->lang.'/de/nav.php', "<?php\n// written by hand\nreturn ['home' => 'Startseite', 'cv' => 'Lebenslauf'];\n");
        $this->fakeTranslations();

        $this->translate();

        $this->assertStringContainsString('written by hand', (string) file_get_contents($this->lang.'/de/nav.php'));
    }

    public function test_force_translates_every_key_again(): void
    {
        $this->writeComplete();
        $this->fakeTranslations();

        $this->assertTrue($this->translate(force: true));

        $this->assertSame(['home' => 'DE Accueil', 'cv' => 'DE CV'], include $this->lang.'/de/nav.php');
    }

    // ── A failure leaves its locale to translate ─────────────────────────────

    public function test_a_failed_locale_is_reported_left_untouched_and_retried_alone(): void
    {
        $this->writeComplete();
        $this->fakeTranslations();
        $this->translate();
        $this->writePhp('fr', ['home' => 'Accueil', 'cv' => 'CV', 'blog' => 'Blog']);
        $this->fakeTranslations(failing: ['de']);

        $this->assertFalse($this->translate());
        $this->assertSame(['home' => 'Startseite', 'cv' => 'Lebenslauf'], include $this->lang.'/de/nav.php');
        $this->assertSame('EN Blog', (include $this->lang.'/en/nav.php')['blog']);

        $this->fakeTranslations();
        $this->assertTrue($this->translate());

        $this->assertSame(['de' => ['blog']], $this->sentKeysByLocale());
        $this->assertSame('DE Blog', (include $this->lang.'/de/nav.php')['blog']);
    }

    public function test_a_locale_that_failed_on_a_changed_key_still_owes_it(): void
    {
        // The old translation is still in the target and the fingerprint has moved on: only the
        // record of the failure makes the next run translate it. Without it, defect 1 of LUMN-110.
        $this->writeComplete();
        $this->fakeTranslations();
        $this->translate();
        $this->writePhp('fr', ['home' => 'Accueil', 'cv' => 'Parcours']);
        $this->fakeTranslations(failing: ['de']);
        $this->assertFalse($this->translate());
        $this->assertSame('Lebenslauf', (include $this->lang.'/de/nav.php')['cv']);

        $this->fakeTranslations();
        $this->assertTrue($this->translate());

        $this->assertSame(['de' => ['cv']], $this->sentKeysByLocale());
        $this->assertSame('DE Parcours', (include $this->lang.'/de/nav.php')['cv']);
    }

    public function test_a_target_unreadable_during_a_french_change_gets_it_once_readable_again(): void
    {
        $this->writeComplete();
        $this->fakeTranslations();
        $this->translate();
        file_put_contents($this->lang.'/de/nav.php', "<?php\nreturn 'broken';\n");
        $this->writePhp('fr', ['home' => 'Accueil', 'cv' => 'Parcours']);
        $this->fakeTranslations();
        $this->assertFalse($this->translate());

        $this->writePhp('de', ['home' => 'Startseite', 'cv' => 'Lebenslauf']);
        $this->fakeTranslations();
        $this->assertTrue($this->translate());

        $this->assertSame(['de' => ['cv']], $this->sentKeysByLocale());
    }

    public function test_a_locale_outside_the_run_still_gets_a_french_change_later(): void
    {
        $this->writeComplete();
        $this->fakeTranslations();
        $this->translate();
        $this->writePhp('fr', ['home' => 'Accueil', 'cv' => 'Parcours']);

        $this->translate(['de']);
        $this->fakeTranslations();
        $this->translate();

        $this->assertSame(['en' => ['cv']], $this->sentKeysByLocale());
        $this->assertSame('EN Parcours', (include $this->lang.'/en/nav.php')['cv']);
    }

    // ── What cannot be read ──────────────────────────────────────────────────

    public function test_an_unreadable_target_is_reported_and_never_overwritten(): void
    {
        $this->writeComplete();
        file_put_contents($this->lang.'/de/nav.php', "<?php\nreturn 'broken';\n");
        $this->writePhp('fr', ['home' => 'Accueil', 'cv' => 'CV', 'blog' => 'Blog']);
        $this->fakeTranslations();

        $plan = $this->service()->plan(self::LOCALES, false);
        $this->assertFalse($this->service()->apply($plan, self::LOCALES));

        $this->assertStringContainsString('does not return an array', implode("\n", $plan->errors));
        $this->assertSame("<?php\nreturn 'broken';\n", file_get_contents($this->lang.'/de/nav.php'));
        $this->assertSame(['en' => ['blog']], $this->sentKeysByLocale());
    }

    public function test_an_unreadable_source_is_reported_and_the_others_still_run(): void
    {
        file_put_contents($this->js.'/fr.json', '{{{ not json');
        $this->fakeTranslations();

        $plan = $this->service()->plan(self::LOCALES, false);

        $this->assertFalse($this->service()->apply($plan, self::LOCALES));
        $this->assertStringContainsString('is not a JSON object', implode("\n", $plan->errors));
        $this->assertSame('DE Accueil', (include $this->lang.'/de/nav.php')['home']);
    }

    public function test_a_missing_french_source_leaves_its_targets_alone(): void
    {
        // Read as empty, it would make every key of its 23 targets obsolete, and empty them.
        $this->writeComplete();
        $json = (string) file_get_contents($this->js.'/de.json');
        unlink($this->js.'/fr.json');
        $this->fakeTranslations();

        $this->assertTrue($this->translate());

        Http::assertNothingSent();
        $this->assertSame($json, file_get_contents($this->js.'/de.json'));
    }

    public function test_a_french_file_emptied_of_its_keys_empties_its_targets(): void
    {
        $this->writeComplete();
        $this->writePhp('fr', []);
        $this->fakeTranslations();

        $this->assertTrue($this->translate());

        Http::assertNothingSent();
        $this->assertSame([], include $this->lang.'/de/nav.php');
    }

    public function test_with_no_french_source_at_all_there_is_nothing_to_do(): void
    {
        unlink($this->lang.'/fr/nav.php');
        unlink($this->js.'/fr.json');

        $plan = $this->service()->plan(self::LOCALES, false);

        $this->assertSame([], $plan->sources);
        $this->assertSame([], $plan->tasks);
        $this->assertFalse($plan->hasErrors());
    }

    // ── Keys nobody expects ──────────────────────────────────────────────────

    public function test_numeric_keys_are_translated_like_any_other(): void
    {
        // PHP turns the key '404' into an integer: an error page's file is the first to have one.
        (new LangFiles)->write($this->lang.'/fr/errors.php', LangFiles::PHP, ['404' => 'Page introuvable', '500' => 'Erreur']);
        $this->fakeTranslations();

        $this->assertTrue($this->translate());
        $this->assertSame([404 => 'DE Page introuvable', 500 => 'DE Erreur'], include $this->lang.'/de/errors.php');

        (new LangFiles)->write($this->lang.'/fr/errors.php', LangFiles::PHP, ['404' => 'Page absente', '500' => 'Erreur']);
        $this->fakeTranslations();
        $this->assertTrue($this->translate(['de']));
        $this->assertSame(['de' => ['404']], $this->sentKeysByLocale());
    }

    public function test_an_empty_french_value_is_written_empty_without_a_request_and_stays_done(): void
    {
        $this->writePhp('fr', ['home' => 'Accueil', 'cv' => 'CV', 'blank' => '']);
        $this->fakeTranslations();

        $this->assertTrue($this->translate());

        $this->assertSame(['de' => ['home', 'cv', 'boot.skip'], 'en' => ['home', 'cv', 'boot.skip']], $this->sentKeysByLocale());
        $this->assertSame('', (include $this->lang.'/de/nav.php')['blank']);
        $this->assertSame([], $this->service()->plan(self::LOCALES, false)->tasks);
    }

    // ── What is reported ─────────────────────────────────────────────────────

    public function test_each_task_is_reported_with_its_outcome(): void
    {
        $this->fakeTranslations(failing: ['de']);
        $reported = [];
        $service = $this->service();

        $service->apply($service->plan(self::LOCALES, false), self::LOCALES, function ($task, bool $done) use (&$reported) {
            $reported[] = [$task->source->id, $task->locale, $done];
        });

        $this->assertSame([['php:nav', 'de', false], ['php:nav', 'en', true], ['js:ui', 'de', false], ['js:ui', 'en', true]], $reported);
    }

    public function test_a_written_file_and_a_failure_are_logged_with_their_context(): void
    {
        $this->spyOnLogs();
        $this->fakeTranslations(failing: ['de']);

        $this->translate();

        $this->assertLogged('info', 'Target file written', ['source' => 'php:nav', 'locale' => 'en', 'translated_count' => 2, 'removed_count' => 0, 'step' => 'file_written']);
        $this->assertLogged('error', 'Translation of a target file failed', ['source' => 'php:nav', 'locale' => 'de', 'key_count' => 2, 'step' => 'translate_failed']);
    }

    // ── The registry, wherever the repository is ─────────────────────────────

    public function test_the_registry_is_recognised_from_another_path_of_the_repository(): void
    {
        $this->writeComplete();
        $this->fakeTranslations();
        $this->translate();
        $this->writePhp('fr', ['home' => 'Accueil', 'cv' => 'Parcours']);

        // A worktree: the same files, the same registry, another directory.
        $worktree = $this->temporaryDirectory().'/worktree';
        rename($this->temporaryDirectory().'/lang', $worktree.'-lang');
        $this->lang = $worktree.'-lang';
        $tasks = $this->service()->plan(self::LOCALES, false)->tasks;

        $this->assertSame([['de', ['cv']], ['en', ['cv']]], array_map(fn ($task) => [$task->locale, $task->keys], $tasks));
    }

    public function test_the_estimate_adds_up_every_task_with_the_interface_model_and_its_format(): void
    {
        $plan = $this->service()->plan(['de', 'en'], false);
        $translator = $this->app->make(AnthropicTranslator::class);

        $expected = new TranslationEstimate;
        foreach ($plan->tasks as $task) {
            $expected = $expected->plus($translator->estimate($task->strings(), $task->locale, TranslationContext::Interface, $task->source->textFormat()));
        }

        $this->assertCount(4, $plan->tasks);
        $this->assertEquals($expected, $this->service()->estimate($plan));
        $this->assertSame(4, $expected->requests);
        // The Laravel and vue-i18n prompts differ: a plan priced with one format for all would differ too.
        $this->assertNotEquals(
            $translator->estimate(['home' => 'Accueil'], 'de', TranslationContext::Interface, TextFormat::Laravel),
            $translator->estimate(['home' => 'Accueil'], 'de', TranslationContext::Interface, TextFormat::VueI18n),
        );
    }

    public function test_an_empty_plan_costs_nothing(): void
    {
        $this->fakeTranslations();
        $this->service()->apply($this->service()->plan(self::LOCALES, false), self::LOCALES);

        $this->assertEquals(new TranslationEstimate, $this->service()->estimate($this->service()->plan(self::LOCALES, false)));
    }

    public function test_the_model_named_is_the_interface_one(): void
    {
        config(['i18n.translation.interface.model' => 'interface-model', 'i18n.translation.prose.model' => 'prose-model']);

        $this->assertSame('interface-model', $this->service()->model());
    }

    public function test_the_plan_counts_what_it_would_translate(): void
    {
        $this->writePhp('de', ['home' => 'Startseite']);

        $plan = $this->service()->plan(['de'], false);

        $this->assertSame(2, $plan->keyCount());
        $this->assertTrue($plan->needsTranslation());
        $this->assertSame('lang/de/nav.php', $plan->tasks[0]->source->label('de'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function service(): LangFileTranslation
    {
        return new LangFileTranslation(
            new LangFiles,
            new TranslationPlanner,
            new TranslationSources($this->registry()),
            $this->app->make(AnthropicTranslator::class),
            $this->lang,
            $this->js,
        );
    }

    /**
     * @param  list<string>  $locales
     */
    private function translate(array $locales = self::LOCALES, bool $force = false): bool
    {
        $service = $this->service();

        return $service->apply($service->plan($locales, $force), self::LOCALES);
    }

    private function registry(): string
    {
        return $this->lang.'/.translation-sources.json';
    }

    private function writeComplete(): void
    {
        $this->writePhp('de', ['home' => 'Startseite', 'cv' => 'Lebenslauf']);
        $this->writePhp('en', ['home' => 'Home', 'cv' => 'Resume']);
        $this->writeJson('de', ['boot' => ['skip' => 'Überspringen']]);
        $this->writeJson('en', ['boot' => ['skip' => 'Skip']]);
    }

    /**
     * @param  array<string, string>  $strings
     */
    private function writePhp(string $locale, array $strings): void
    {
        (new LangFiles)->write($this->lang.'/'.$locale.'/nav.php', LangFiles::PHP, $strings);
    }

    /**
     * @param  array<string, mixed>  $nested
     */
    private function writeJson(string $locale, array $nested): void
    {
        (new LangFiles)->write($this->js.'/'.$locale.'.json', LangFiles::JSON, (new LangFiles)->flatten($nested));
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $locale): array
    {
        return (array) json_decode((string) file_get_contents($this->js.'/'.$locale.'.json'), true);
    }
}
