<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\HomepageContent;
use App\Services\TranslationCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CmsTranslateTest extends TestCase
{
    use RefreshDatabase;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = sys_get_temp_dir().'/cms_test_'.uniqid('', true);
        mkdir($this->cacheDir, 0755, true);

        $this->app->bind(TranslationCache::class, fn () => new TranslationCache($this->cacheDir));

        config([
            'i18n.supported_locales' => ['fr', 'de', 'en'],
            'i18n.native_names' => ['fr' => 'Français', 'de' => 'Deutsch', 'en' => 'English'],
            'i18n.cms_models' => [HomepageContent::class],
            'services.anthropic.api_key' => 'test-key',
            'services.anthropic.model' => 'claude-haiku-4-5-20251001',
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->cacheDir);
        parent::tearDown();
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
            ->assertFailed();
    }

    public function test_skips_fr_source_locale(): void
    {
        Http::fake();

        $this->artisan('cms:translate', ['--locale' => 'fr'])
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_fails_when_model_class_does_not_exist(): void
    {
        config(['i18n.cms_models' => ['App\\Models\\NonExistentModel']]);
        Http::fake();

        $this->artisan('cms:translate', ['--locale' => 'de'])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_succeeds_with_warning_when_no_cms_models_registered(): void
    {
        config(['i18n.cms_models' => []]);

        Http::fake();

        $this->artisan('cms:translate', ['--locale' => 'de'])
            ->expectsOutputToContain('No CMS models')
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

    // ── Cache behaviour ──────────────────────────────────────────────────────

    public function test_cached_fields_are_not_sent_to_api(): void
    {
        $content = HomepageContent::create([
            'tagline' => ['fr' => 'Accroche'],
            'subtitle' => ['fr' => 'Sous-titre'],
            'bio' => ['fr' => 'Bio'],
        ]);

        $cache = new TranslationCache($this->cacheDir);
        $cacheKey = 'cms_HomepageContent_'.$content->id;
        $cache->snapshotCmsRecord($cacheKey, ['tagline' => 'Accroche', 'subtitle' => 'Sous-titre', 'bio' => 'Bio']);
        $cache->putTranslations('de', $cacheKey, ['tagline' => 'Mein Slogan', 'subtitle' => 'Mein Untertitel', 'bio' => 'Meine Bio']);

        Http::fake();

        $this->artisan('cms:translate', ['--locale' => 'de'])
            ->assertSuccessful();

        Http::assertNothingSent();

        $content->refresh();
        $this->assertSame('Mein Slogan', $content->getTranslation('tagline', 'de', false));
    }

    public function test_force_bypasses_cache_and_retranslates(): void
    {
        $content = HomepageContent::create([
            'tagline' => ['fr' => 'Accroche'],
            'subtitle' => ['fr' => 'Sous-titre'],
            'bio' => ['fr' => 'Bio'],
        ]);

        $cache = new TranslationCache($this->cacheDir);
        $cacheKey = 'cms_HomepageContent_'.$content->id;
        $cache->snapshotCmsRecord($cacheKey, ['tagline' => 'Accroche', 'subtitle' => 'Sous-titre', 'bio' => 'Bio']);
        $cache->putTranslations('de', $cacheKey, ['tagline' => 'Vieux', 'subtitle' => 'Vieux', 'bio' => 'Vieux']);

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(
                $this->apiResponse(['tagline' => 'Neu', 'subtitle' => 'Neu Untertitel', 'bio' => 'Neue Bio']),
                200,
            ),
        ]);

        $this->artisan('cms:translate', ['--locale' => 'de', '--force' => true])
            ->assertSuccessful();

        Http::assertSentCount(1);

        $content->refresh();
        $this->assertSame('Neu', $content->getTranslation('tagline', 'de', false));
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
            ->expectsOutputToContain('Incomplete translation')
            ->assertFailed();
    }

    // ── getAttribute fallback (no HasTranslations) ───────────────────────────

    public function test_uses_get_attribute_when_model_lacks_has_translations(): void
    {
        Schema::create('bare_translatable_models', function ($table) {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        try {
            BareTranslatableModel::create(['title' => 'Mon titre']);

            config(['i18n.cms_models' => [BareTranslatableModel::class]]);

            Http::fake([
                'https://api.anthropic.com/v1/messages' => Http::response(
                    $this->apiResponse(['title' => 'Mein Titel']),
                    200,
                ),
            ]);

            $this->artisan('cms:translate', ['--locale' => 'de', '--force' => true])
                ->assertSuccessful();

            Http::assertSentCount(1);
        } finally {
            Schema::dropIfExists('bare_translatable_models');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function apiResponse(array $data): array
    {
        return [
            'id' => 'msg_test_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => json_encode($data)]],
            'model' => 'claude-haiku-4-5-20251001',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ];
    }

    private function deleteDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.'/'.$item;
            is_dir($full) ? $this->deleteDir($full) : unlink($full);
        }
        rmdir($path);
    }
}

// Minimal model without HasTranslations — used to cover the getAttribute() fallback branch.
class BareTranslatableModel extends Model
{
    protected $table = 'bare_translatable_models';

    /** @var array<int, string> */
    public array $translatable = ['title'];

    protected $fillable = ['title'];
}
