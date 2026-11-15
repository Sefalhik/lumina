<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CmsTranslate;
use App\Models\Contracts\TranslatableContent;
use App\Models\Experience;
use App\Models\HomepageContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Translatable\HasTranslations;
use Tests\Concerns\AssertsLogs;
use Tests\Concerns\FakesTranslationApi;
use Tests\Concerns\UsesTemporaryDirectory;
use Tests\TestCase;

class CmsTranslateTest extends TestCase
{
    use AssertsLogs;
    use FakesTranslationApi;
    use RefreshDatabase;
    use UsesTemporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'i18n.cache_path' => $this->temporaryDirectory(),
            'i18n.supported_locales' => ['fr', 'de', 'en'],
            'i18n.native_names' => ['fr' => 'Français', 'de' => 'Deutsch', 'en' => 'English'],
            'i18n.cms_models' => [HomepageContent::class],
            'services.anthropic.api_key' => 'test-key',
            'services.anthropic.model' => 'claude-haiku-4-5-20251001',
        ]);
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function test_fails_without_api_key(): void
    {
        config(['services.anthropic.api_key' => null]);

        $this->artisan('cms:translate', ['--locale' => 'de'])
            ->expectsOutputToContain('ANTHROPIC_API_KEY')
            ->assertFailed();
    }

    public function test_fails_for_unsupported_locale(): void
    {
        $this->artisan('cms:translate', ['--locale' => 'xx'])
            ->expectsOutputToContain("'xx' is not in the supported locales list")
            ->assertFailed();
    }

    public function test_skips_fr_source_locale(): void
    {
        Http::fake();

        $this->artisan('cms:translate', ['--locale' => 'fr'])
            ->expectsOutputToContain('French is the source of truth')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_fails_when_model_class_does_not_exist(): void
    {
        config(['i18n.cms_models' => ['App\\Models\\NonExistentModel']]);
        Http::fake();

        $this->artisan('cms:translate', ['--locale' => 'de'])
            ->expectsOutputToContain('is not a model implementing TranslatableContent')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_succeeds_with_warning_when_no_cms_models_registered(): void
    {
        config(['i18n.cms_models' => []]);

        Http::fake();

        $this->artisan('cms:translate', ['--locale' => 'de'])
            ->expectsOutputToContain('No CMS models')
            ->doesntExpectOutputToContain('All CMS translations completed')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    // ── Dry-run ──────────────────────────────────────────────────────────────

    public function test_dry_run_succeeds_without_api_key(): void
    {
        config(['services.anthropic.api_key' => null]);

        $this->artisan('cms:translate', ['--locale' => 'de', '--dry-run' => true])
            ->assertSuccessful();
    }

    public function test_dry_run_sends_no_http_requests(): void
    {
        HomepageContent::create(['tagline' => ['fr' => 'Accroche'], 'subtitle' => ['fr' => 'Sous-titre'], 'bio' => ['fr' => 'Bio']]);
        Http::fake();

        $this->artisan('cms:translate', ['--locale' => 'de', '--dry-run' => true])
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_dry_run_does_not_write_to_database(): void
    {
        $content = HomepageContent::create(['tagline' => ['fr' => 'Accroche'], 'subtitle' => ['fr' => 'Sous-titre'], 'bio' => ['fr' => 'Bio']]);
        Http::fake();

        $this->artisan('cms:translate', ['--locale' => 'de', '--dry-run' => true]);

        $content->refresh();
        $this->assertEmpty($content->getTranslation('tagline', 'de', false));
    }

    // ── No records ───────────────────────────────────────────────────────────

    public function test_skips_model_with_no_records(): void
    {
        Http::fake();

        $this->artisan('cms:translate', ['--locale' => 'de'])
            ->expectsOutputToContain('no records found')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function test_translates_homepage_content_to_single_locale(): void
    {
        HomepageContent::create([
            'tagline' => ['fr' => 'Mon accroche'],
            'subtitle' => ['fr' => 'Mon sous-titre'],
            'bio' => ['fr' => 'Ma bio'],
        ]);

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(
                $this->apiResponse(['tagline' => 'Mein Slogan', 'subtitle' => 'Mein Untertitel', 'bio' => 'Meine Bio']),
                200,
            ),
        ]);

        $this->artisan('cms:translate', ['--locale' => 'de', '--force' => true])
            ->assertSuccessful();

        $content = HomepageContent::firstOrFail();
        $this->assertSame('Mein Slogan', $content->getTranslation('tagline', 'de', false));
        $this->assertSame('Mein Untertitel', $content->getTranslation('subtitle', 'de', false));
        $this->assertSame('Meine Bio', $content->getTranslation('bio', 'de', false));
    }

    public function test_translates_to_all_non_fr_locales_when_no_option_given(): void
    {
        HomepageContent::create([
            'tagline' => ['fr' => 'Accroche'],
            'subtitle' => ['fr' => 'Sous-titre'],
            'bio' => ['fr' => 'Bio'],
        ]);

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push($this->apiResponse(['tagline' => 'DE Slogan', 'subtitle' => 'DE Untertitel', 'bio' => 'DE Bio']), 200)
                ->push($this->apiResponse(['tagline' => 'EN tagline', 'subtitle' => 'EN subtitle', 'bio' => 'EN bio']), 200),
        ]);

        $this->artisan('cms:translate', ['--force' => true])
            ->assertSuccessful();

        Http::assertSentCount(2);

        $content = HomepageContent::firstOrFail();
        $this->assertSame('DE Slogan', $content->getTranslation('tagline', 'de', false));
        $this->assertSame('EN tagline', $content->getTranslation('tagline', 'en', false));
    }

    // ── Only what needs it (LUMN-110) ────────────────────────────────────────

    public function test_translations_already_in_the_database_are_adopted_without_a_request(): void
    {
        // Seeded content arrives in 24 languages with no registry beside it: it is not translated again.
        HomepageContent::create([
            'tagline' => ['fr' => 'Accroche', 'de' => 'Slogan'],
            'subtitle' => ['fr' => 'Sous-titre', 'de' => 'Untertitel'],
            'bio' => ['fr' => 'Bio', 'de' => 'Biografie'],
        ]);
        $this->fakeTranslations();

        $this->artisan('cms:translate', ['--locale' => 'de'])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertFileExists($this->temporaryDirectory().'/cms-sources.json');
    }

    public function test_a_field_whose_french_changed_is_translated_again_and_only_it(): void
    {
        $content = HomepageContent::create([
            'tagline' => ['fr' => 'Accroche', 'de' => 'Slogan'],
            'subtitle' => ['fr' => 'Sous-titre', 'de' => 'Untertitel'],
            'bio' => ['fr' => 'Bio', 'de' => 'Biografie'],
        ]);
        $this->fakeTranslations();
        $this->artisan('cms:translate', ['--locale' => 'de'])->assertSuccessful();
        $content->setTranslation('bio', 'fr', 'Nouvelle bio')->save();

        $this->artisan('cms:translate', ['--locale' => 'de'])->assertSuccessful();

        $this->assertSame(['de' => ['bio']], $this->sentKeysByLocale());
        $content->refresh();
        $this->assertSame('DE Nouvelle bio', $content->getTranslation('bio', 'de', false));
        $this->assertSame('Slogan', $content->getTranslation('tagline', 'de', false));
    }

    public function test_a_failed_locale_is_retried_alone_on_the_next_run(): void
    {
        HomepageContent::create(['tagline' => ['fr' => 'Accroche'], 'subtitle' => ['fr' => 'Sous-titre'], 'bio' => ['fr' => 'Bio']]);
        $this->fakeTranslations(failing: ['de']);
        $this->artisan('cms:translate')->assertFailed();

        $this->fakeTranslations();
        $this->artisan('cms:translate')->assertSuccessful();

        $this->assertSame(['de' => ['tagline', 'subtitle', 'bio']], $this->sentKeysByLocale());
    }

    public function test_a_locale_outside_the_run_gets_a_french_change_later(): void
    {
        $content = HomepageContent::create([
            'tagline' => ['fr' => 'Accroche', 'de' => 'Slogan', 'en' => 'Tagline'],
            'subtitle' => ['fr' => 'Sous-titre', 'de' => 'Untertitel', 'en' => 'Subtitle'],
            'bio' => ['fr' => 'Bio', 'de' => 'Biografie', 'en' => 'Biography'],
        ]);
        $this->fakeTranslations();
        $this->artisan('cms:translate')->assertSuccessful();
        $content->setTranslation('bio', 'fr', 'Nouvelle bio')->save();
        $this->artisan('cms:translate', ['--locale' => 'de'])->assertSuccessful();

        $this->fakeTranslations();
        $this->artisan('cms:translate')->assertSuccessful();

        $this->assertSame(['en' => ['bio']], $this->sentKeysByLocale());
    }

    public function test_force_translates_every_field_again(): void
    {
        $content = HomepageContent::create([
            'tagline' => ['fr' => 'Accroche', 'de' => 'Vieux'],
            'subtitle' => ['fr' => 'Sous-titre', 'de' => 'Vieux'],
            'bio' => ['fr' => 'Bio', 'de' => 'Vieux'],
        ]);
        $this->fakeTranslations();

        $this->artisan('cms:translate', ['--locale' => 'de', '--force' => true])->assertSuccessful();

        $content->refresh();
        $this->assertSame('DE Accroche', $content->getTranslation('tagline', 'de', false));
    }

    public function test_a_dry_run_records_nothing(): void
    {
        HomepageContent::create(['tagline' => ['fr' => 'Accroche'], 'subtitle' => ['fr' => 'Sous-titre'], 'bio' => ['fr' => 'Bio']]);

        $this->artisan('cms:translate', ['--dry-run' => true])->assertSuccessful();

        $this->assertFileDoesNotExist($this->temporaryDirectory().'/cms-sources.json');
    }

    // ── API error handling ────────────────────────────────────────────────────

    public function test_api_failure_returns_failure_exit_code(): void
    {
        HomepageContent::create([
            'tagline' => ['fr' => 'Accroche'],
            'subtitle' => ['fr' => 'Sous-titre'],
            'bio' => ['fr' => 'Bio'],
        ]);

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'error' => ['message' => 'rate limit exceeded'],
            ], 429),
        ]);

        $this->artisan('cms:translate', ['--locale' => 'de', '--force' => true])
            ->expectsOutputToContain('Translation failed')
            ->assertFailed();
    }

    public function test_incomplete_translation_returns_failure_exit_code(): void
    {
        HomepageContent::create([
            'tagline' => ['fr' => 'Accroche'],
            'subtitle' => ['fr' => 'Sous-titre'],
            'bio' => ['fr' => 'Bio'],
        ]);

        // API only returns 2 of 3 fields
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(
                $this->apiResponse(['tagline' => 'Mein Slogan', 'subtitle' => 'Untertitel']),
                200,
            ),
        ]);

        $this->artisan('cms:translate', ['--locale' => 'de', '--force' => true])
            ->expectsOutputToContain('Translation failed')
            ->assertFailed();
    }

    // ── What is shown and logged ─────────────────────────────────────────────

    public function test_a_dry_run_shows_each_record_and_what_each_locale_would_receive(): void
    {
        $content = HomepageContent::create(['tagline' => ['fr' => 'Accroche', 'de' => 'Slogan'], 'subtitle' => ['fr' => 'Sous-titre'], 'bio' => ['fr' => 'Bio']]);

        $this->artisan('cms:translate', ['--dry-run' => true])
            ->expectsOutputToContain('HomepageContent #'.$content->id)
            ->expectsOutputToContain('de 2 to translate')
            ->expectsOutputToContain('en 3 to translate')
            ->assertSuccessful();
    }

    public function test_a_successful_run_says_so_and_is_logged_from_start_to_outcome(): void
    {
        HomepageContent::create(['tagline' => ['fr' => 'Accroche'], 'subtitle' => ['fr' => 'Sous-titre'], 'bio' => ['fr' => 'Bio']]);
        $this->spyOnLogs();
        $this->fakeTranslations();

        $this->artisan('cms:translate')
            ->expectsOutputToContain('All CMS translations completed.')
            ->assertSuccessful();

        $this->assertLogged('info', 'cms:translate started', ['step' => 'start', 'target_locales' => ['de', 'en'], 'model_count' => 1]);
        $this->assertLogged('info', 'cms:translate completed', ['step' => 'complete', 'success' => true]);
    }

    public function test_a_failed_run_says_so_and_logs_what_was_left(): void
    {
        $content = HomepageContent::create(['tagline' => ['fr' => 'Accroche'], 'subtitle' => ['fr' => 'Sous-titre'], 'bio' => ['fr' => 'Bio']]);
        $this->spyOnLogs();
        $this->fakeTranslations(failing: ['de']);

        $this->artisan('cms:translate')
            ->expectsOutputToContain('Completed with errors')
            ->doesntExpectOutputToContain('All CMS translations completed.')
            ->assertFailed();

        $this->assertLogged('error', 'cms:translate left a record untranslated', ['step' => 'translate_failed', 'source' => 'cms_HomepageContent_'.$content->id, 'locale' => 'de']);
        $this->assertLogged('info', 'cms:translate completed', ['step' => 'complete', 'success' => false]);
    }

    public function test_the_command_describes_itself(): void
    {
        $this->assertStringContainsString('French', $this->app->make(CmsTranslate::class)->getDescription());
    }

    public function test_one_record_failing_fails_the_run_even_when_a_later_one_succeeds(): void
    {
        HomepageContent::create(['tagline' => ['fr' => 'Une'], 'subtitle' => ['fr' => 'Un'], 'bio' => ['fr' => 'Bio']]);
        HomepageContent::create(['tagline' => ['fr' => 'Deux'], 'subtitle' => ['fr' => 'Deux'], 'bio' => ['fr' => 'Bio']]);
        Http::fake([self::MESSAGES_URL => Http::sequence()
            ->push(['error' => ['message' => 'overloaded']], 529)
            ->push($this->apiResponse(['tagline' => 'Zwei', 'subtitle' => 'Zwei', 'bio' => 'Bio']))]);

        $this->artisan('cms:translate', ['--locale' => 'de'])->assertFailed();
    }

    public function test_one_model_failing_fails_the_run_even_when_a_later_one_succeeds(): void
    {
        config(['i18n.cms_models' => [HomepageContent::class, Experience::class]]);
        HomepageContent::create(['tagline' => ['fr' => 'Une'], 'subtitle' => ['fr' => 'Un'], 'bio' => ['fr' => 'Bio']]);
        Experience::create(['employer' => 'SNCF', 'job_title' => 'Tech Lead', 'started_at' => '2017-01-01', 'description' => ['fr' => 'Maintenance'], 'achievements' => ['fr' => 'Rames']]);
        Http::fake([self::MESSAGES_URL => Http::sequence()
            ->push(['error' => ['message' => 'overloaded']], 529)
            ->push($this->apiResponse(['description' => 'Wartung', 'achievements' => 'Züge']))]);

        $this->artisan('cms:translate', ['--locale' => 'de'])->assertFailed();

        $this->assertSame('Wartung', Experience::firstOrFail()->getTranslation('description', 'de', false));
    }

    public function test_a_model_with_no_translatable_field_is_walked_without_a_request(): void
    {
        Schema::create('untranslated_models', function ($table) {
            $table->id();
            $table->timestamps();
        });

        try {
            UntranslatedModel::create();
            config(['i18n.cms_models' => [UntranslatedModel::class]]);
            $this->fakeTranslations();

            $this->artisan('cms:translate', ['--locale' => 'de'])->assertSuccessful();

            Http::assertNothingSent();
        } finally {
            Schema::dropIfExists('untranslated_models');
        }
    }

    public function test_a_translatable_class_that_is_not_a_model_is_refused(): void
    {
        config(['i18n.cms_models' => [NotAModel::class]]);
        $this->fakeTranslations();

        $this->artisan('cms:translate', ['--locale' => 'de'])
            ->expectsOutputToContain('is not a model implementing TranslatableContent')
            ->assertFailed();
    }

    // ── A model that cannot hold a translation ───────────────────────────────

    public function test_a_model_without_translations_is_refused_before_any_request(): void
    {
        Schema::create('bare_translatable_models', function ($table) {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        try {
            BareTranslatableModel::create(['title' => 'Mon titre']);
            config(['i18n.cms_models' => [BareTranslatableModel::class]]);
            $this->fakeTranslations();

            // Before LUMN-110 it was translated, billed, and the translation thrown away.
            $this->spyOnLogs();

            $this->artisan('cms:translate', ['--locale' => 'de', '--force' => true])
                ->expectsOutputToContain('is not a model implementing TranslatableContent')
                ->assertFailed();

            Http::assertNothingSent();
            $this->assertLogged('error', 'cms:translate refused a model that cannot hold translations', ['step' => 'not_translatable', 'model' => BareTranslatableModel::class]);
        } finally {
            Schema::dropIfExists('bare_translatable_models');
        }
    }
}

// A model declaring $translatable without being able to store a translation: refused (LUMN-110).
class BareTranslatableModel extends Model
{
    protected $table = 'bare_translatable_models';

    /** @var array<int, string> */
    public array $translatable = ['title'];

    protected $fillable = ['title'];
}

// Implements the contract without being a model: it has no records to walk.
class NotAModel implements TranslatableContent
{
    public function getTranslation(string $key, string $locale, bool $useFallbackLocale = true): mixed
    {
        return null;
    }

    public function setTranslation(string $key, string $locale, mixed $value): self
    {
        return $this;
    }

    public function getTranslatableAttributes(): array
    {
        return [];
    }
}

// Translatable in form, with nothing to translate.
class UntranslatedModel extends Model implements TranslatableContent
{
    use HasTranslations;

    protected $table = 'untranslated_models';

    /** @var list<string> */
    public array $translatable = [];
}
