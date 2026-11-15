<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\I18nTranslate;
use App\Services\Translation\LangFiles;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsLogs;
use Tests\Concerns\FakesTranslationApi;
use Tests\Concerns\UsesTemporaryDirectory;
use Tests\TestCase;
use Tests\Unit\Services\Translation\LangFileTranslationTest;

/**
 * `php artisan i18n:translate` assembled (LUMN-110): it shows the plan and its cost, asks before
 * spending, and never answers for someone who is not there. What it translates is proven in
 * {@see LangFileTranslationTest}.
 */
class I18nTranslateTest extends TestCase
{
    use AssertsLogs;
    use FakesTranslationApi;
    use UsesTemporaryDirectory;

    private string $lang;

    private string $js;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lang = $this->temporaryDirectory().'/lang';
        $this->js = $this->temporaryDirectory().'/js/i18n';
        (new LangFiles)->write($this->lang.'/fr/nav.php', LangFiles::PHP, ['home' => 'Accueil', 'cv' => 'CV']);
        (new LangFiles)->write($this->js.'/fr.json', LangFiles::JSON, ['boot.skip' => 'Ignorer']);

        $this->app->useLangPath($this->lang);

        config([
            'i18n.js_i18n_path' => $this->js,
            'i18n.supported_locales' => ['fr', 'de', 'en'],
            'i18n.native_names' => ['fr' => 'Français', 'de' => 'Deutsch', 'en' => 'English'],
            'services.anthropic.api_key' => 'test-key',
            'services.anthropic.model' => 'claude-haiku-4-5-20251001',
        ]);

        $this->fakeTranslations();
    }

    // ── Before anything is planned ───────────────────────────────────────────

    public function test_fails_without_api_key(): void
    {
        config(['services.anthropic.api_key' => null]);

        $this->artisan('i18n:translate', ['--yes' => true])
            ->expectsOutputToContain('ANTHROPIC_API_KEY')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_fails_for_an_unsupported_locale(): void
    {
        $this->artisan('i18n:translate', ['--locale' => 'xx', '--yes' => true])
            ->expectsOutputToContain("'xx'")
            ->assertFailed();
    }

    public function test_french_as_a_target_does_nothing(): void
    {
        $this->artisan('i18n:translate', ['--locale' => 'fr', '--yes' => true])
            ->expectsOutputToContain('French is the source of truth')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertFileDoesNotExist($this->registry());
    }

    // ── The plan, and what it costs ──────────────────────────────────────────

    public function test_the_plan_names_each_file_and_prices_the_whole_run(): void
    {
        $this->artisan('i18n:translate', ['--dry-run' => true])
            ->expectsOutputToContain('lang/de/nav.php — 2 to translate, 0 to remove')
            ->expectsOutputToContain('resources/js/i18n/en.json — 1 to translate, 0 to remove')
            ->expectsOutputToContain('6 keys in 4 files: 4 API requests to claude-haiku-4-5-20251001')
            ->assertSuccessful();
    }

    public function test_a_dry_run_needs_no_key_sends_nothing_and_writes_nothing(): void
    {
        config(['services.anthropic.api_key' => null]);

        $this->artisan('i18n:translate', ['--dry-run' => true])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertFileDoesNotExist($this->lang.'/de/nav.php');
        $this->assertFileDoesNotExist($this->registry());
    }

    public function test_a_dry_run_fails_when_a_file_cannot_be_read(): void
    {
        file_put_contents($this->js.'/fr.json', '{{{');

        $this->artisan('i18n:translate', ['--dry-run' => true])
            ->expectsOutputToContain('Unreadable:')
            ->assertFailed();
    }

    public function test_nothing_to_translate_asks_nothing_and_records_the_sources(): void
    {
        (new LangFiles)->write($this->lang.'/de/nav.php', LangFiles::PHP, ['home' => 'Startseite', 'cv' => 'Lebenslauf']);
        (new LangFiles)->write($this->lang.'/en/nav.php', LangFiles::PHP, ['home' => 'Home', 'cv' => 'Resume']);
        (new LangFiles)->write($this->js.'/de.json', LangFiles::JSON, ['boot.skip' => 'Überspringen']);
        (new LangFiles)->write($this->js.'/en.json', LangFiles::JSON, ['boot.skip' => 'Skip']);

        $this->artisan('i18n:translate', ['--no-interaction' => true])
            ->expectsOutputToContain('Nothing to translate')
            ->doesntExpectOutputToContain('API requests')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertFileExists($this->registry());
    }

    // ── Consent ──────────────────────────────────────────────────────────────

    public function test_outside_a_terminal_without_yes_it_stops_before_writing_or_marking_anything(): void
    {
        $this->artisan('i18n:translate', ['--no-interaction' => true])
            ->expectsOutputToContain('accept the plan with --yes')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertFileDoesNotExist($this->lang.'/de/nav.php');
        $this->assertFileDoesNotExist($this->registry());
    }

    public function test_with_yes_it_runs_outside_a_terminal(): void
    {
        $this->artisan('i18n:translate', ['--no-interaction' => true, '--yes' => true])
            ->expectsOutputToContain('All translations completed.')
            ->assertSuccessful();

        $this->assertSame(['home' => 'DE Accueil', 'cv' => 'DE CV'], include $this->lang.'/de/nav.php');
        $this->assertFileExists($this->registry());
    }

    public function test_with_yes_only_the_file_whose_source_changed_is_translated(): void
    {
        $this->artisan('i18n:translate', ['--yes' => true])->assertSuccessful();
        $json = (string) file_get_contents($this->js.'/de.json');
        (new LangFiles)->write($this->lang.'/fr/nav.php', LangFiles::PHP, ['home' => 'Accueil', 'cv' => 'Parcours']);
        $this->fakeTranslations();

        $this->artisan('i18n:translate', ['--yes' => true])->assertSuccessful();

        $this->assertSame(['de' => ['cv'], 'en' => ['cv']], $this->sentKeysByLocale());
        $this->assertSame($json, file_get_contents($this->js.'/de.json'));
    }

    public function test_in_a_terminal_it_asks_once_and_translates_on_yes(): void
    {
        $this->artisan('i18n:translate')
            ->expectsConfirmation('Translate these keys?', 'yes')
            ->assertSuccessful();

        $this->assertFileExists($this->lang.'/en/nav.php');
    }

    public function test_in_a_terminal_no_writes_nothing_and_is_not_an_error(): void
    {
        $this->artisan('i18n:translate')
            ->expectsConfirmation('Translate these keys?', 'no')
            ->expectsOutputToContain('Nothing translated, nothing written.')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertFileDoesNotExist($this->lang.'/de/nav.php');
        $this->assertFileDoesNotExist($this->registry());
    }

    public function test_force_still_asks(): void
    {
        $this->artisan('i18n:translate', ['--force' => true, '--no-interaction' => true])
            ->assertFailed();

        Http::assertNothingSent();
    }

    // ── Outcome ──────────────────────────────────────────────────────────────

    public function test_one_locale_is_enough_with_the_locale_option(): void
    {
        $this->artisan('i18n:translate', ['--locale' => 'de', '--yes' => true])->assertSuccessful();

        $this->assertSame(['de' => ['home', 'cv', 'boot.skip']], $this->sentKeysByLocale());
        $this->assertFileDoesNotExist($this->lang.'/en/nav.php');
    }

    public function test_a_failure_is_named_and_fails_the_run_instead_of_reporting_success(): void
    {
        $this->fakeTranslations(failing: ['de']);

        $this->artisan('i18n:translate', ['--yes' => true])
            ->expectsOutputToContain('lang/de/nav.php — translation failed, left to translate')
            ->expectsOutputToContain('Completed with errors')
            ->doesntExpectOutputToContain('All translations completed.')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->lang.'/de/nav.php');
        $this->assertFileExists($this->lang.'/en/nav.php');
    }

    // ── What is logged ───────────────────────────────────────────────────────

    public function test_a_run_is_logged_from_start_to_outcome(): void
    {
        $this->spyOnLogs();
        $this->fakeTranslations(failing: ['de']);

        $this->artisan('i18n:translate', ['--yes' => true])->assertFailed();

        $this->assertLogged('info', 'i18n:translate started', ['step' => 'start', 'target_locales' => ['de', 'en'], 'key_count' => 6]);
        $this->assertLogged('info', 'i18n:translate completed', ['step' => 'complete', 'success' => false]);
    }

    public function test_a_refusal_without_consent_is_logged_as_a_warning(): void
    {
        $this->spyOnLogs();

        $this->artisan('i18n:translate', ['--no-interaction' => true])->assertFailed();

        $this->assertLogged('warning', 'i18n:translate refused to run without consent', ['step' => 'refused_without_consent', 'key_count' => 6]);
        $this->assertNotLogged('info', 'i18n:translate started');
    }

    public function test_the_command_describes_itself(): void
    {
        $this->assertStringContainsString('French', $this->app->make(I18nTranslate::class)->getDescription());
    }

    private function registry(): string
    {
        return $this->lang.'/.translation-sources.json';
    }
}
