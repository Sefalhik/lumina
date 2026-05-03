<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\TranslationCache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class I18nTranslateTest extends TestCase
{
    private string $langDir;

    private string $jsI18nDir;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        $id = uniqid('i18n_test_', true);
        $base = sys_get_temp_dir().'/'.$id;

        $this->langDir = $base.'/lang';
        $this->jsI18nDir = $base.'/js/i18n';
        $this->cacheDir = $base.'/cache';

        mkdir($this->langDir.'/fr', 0755, true);
        mkdir($this->jsI18nDir, 0755, true);
        mkdir($this->cacheDir, 0755, true);

        file_put_contents(
            $this->langDir.'/fr/nav.php',
            "<?php\nreturn ['home' => 'Accueil', 'cv' => 'CV'];\n",
        );
        file_put_contents(
            $this->jsI18nDir.'/fr.json',
            json_encode(['boot' => ['skip' => 'Ignorer']])."\n",
        );

        $this->app->useLangPath($this->langDir);
        $this->app->bind(TranslationCache::class, fn () => new TranslationCache($this->cacheDir));

        config([
            'i18n.js_i18n_path' => $this->jsI18nDir,
            'i18n.supported_locales' => ['fr', 'de', 'en'],
            'i18n.native_names' => ['fr' => 'Français', 'de' => 'Deutsch', 'en' => 'English'],
            'services.anthropic.api_key' => 'test-key',
            'services.anthropic.model' => 'claude-haiku-4-5-20251001',
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteDir(dirname($this->langDir));
        parent::tearDown();
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function test_fails_without_api_key(): void
    {
        config(['services.anthropic.api_key' => null]);

        $this->artisan('i18n:translate', ['--locale' => 'de'])
            ->expectsOutputToContain('ANTHROPIC_API_KEY')
            ->assertFailed();
    }

    public function test_fails_for_unsupported_locale(): void
    {
        $this->artisan('i18n:translate', ['--locale' => 'xx'])
            ->assertFailed();
    }

    public function test_skips_fr_source_locale(): void
    {
        Http::fake();

        $this->artisan('i18n:translate', ['--locale' => 'fr'])
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    // ── Dry-run ──────────────────────────────────────────────────────────────

    public function test_dry_run_succeeds_without_api_key(): void
    {
        config(['services.anthropic.api_key' => null]);

        $this->artisan('i18n:translate', ['--locale' => 'de', '--dry-run' => true])
            ->assertSuccessful();
    }

    public function test_dry_run_sends_no_http_requests(): void
    {
        Http::fake();

        $this->artisan('i18n:translate', ['--locale' => 'de', '--dry-run' => true])
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_dry_run_lists_files_to_translate(): void
    {
        Http::fake();

        $this->artisan('i18n:translate', ['--locale' => 'de', '--dry-run' => true])
            ->expectsOutputToContain('nav.php')
            ->expectsOutputToContain('de')
            ->assertSuccessful();
    }

    public function test_dry_run_writes_no_files(): void
    {
        Http::fake();

        $this->artisan('i18n:translate', ['--locale' => 'de', '--dry-run' => true]);

        $this->assertFileDoesNotExist($this->langDir.'/de/nav.php');
        $this->assertFileDoesNotExist($this->jsI18nDir.'/de.json');
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function test_translates_php_nav_file(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push($this->apiResponse(['home' => 'Startseite', 'cv' => 'Lebenslauf']), 200)
                ->push($this->apiResponse(['boot.skip' => 'Überspringen']), 200),
        ]);

        $this->artisan('i18n:translate', ['--locale' => 'de', '--force' => true])
            ->assertSuccessful();

        $this->assertFileExists($this->langDir.'/de/nav.php');
        $translated = include $this->langDir.'/de/nav.php';
        $this->assertSame('Startseite', $translated['home']);
        $this->assertSame('Lebenslauf', $translated['cv']);
    }

    public function test_translates_js_i18n_file(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push($this->apiResponse(['home' => 'Startseite', 'cv' => 'Lebenslauf']), 200)
                ->push($this->apiResponse(['boot.skip' => 'Überspringen']), 200),
        ]);

        $this->artisan('i18n:translate', ['--locale' => 'de', '--force' => true])
            ->assertSuccessful();

        $this->assertFileExists($this->jsI18nDir.'/de.json');
        $content = json_decode((string) file_get_contents($this->jsI18nDir.'/de.json'), true);
        $this->assertSame('Überspringen', $content['boot']['skip']);
    }

    public function test_translates_all_non_fr_locales_when_no_locale_option_given(): void
    {
        // 2 locales × 2 files = 4 API calls: nav.php-de, nav.php-en, fr.json-de, fr.json-en
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push($this->apiResponse(['home' => 'übersetzt', 'cv' => 'übersetzt']), 200)
                ->push($this->apiResponse(['home' => 'translated', 'cv' => 'translated']), 200)
                ->push($this->apiResponse(['boot.skip' => 'Überspringen']), 200)
                ->push($this->apiResponse(['boot.skip' => 'Skip']), 200),
        ]);

        $this->artisan('i18n:translate', ['--force' => true])
            ->assertSuccessful();

        $this->assertFileExists($this->langDir.'/de/nav.php');
        $this->assertFileExists($this->langDir.'/en/nav.php');
    }

    // ── Checksum / skip logic ─────────────────────────────────────────────────

    public function test_unchanged_source_file_skips_all_api_calls(): void
    {
        $cache = new TranslationCache($this->cacheDir);
        $cache->snapshotFile($this->langDir.'/fr/nav.php', ['home' => 'Accueil', 'cv' => 'CV']);
        $cache->snapshotFile($this->jsI18nDir.'/fr.json', ['boot.skip' => 'Ignorer']);

        Http::fake();

        $this->artisan('i18n:translate', ['--locale' => 'de'])
            ->expectsOutputToContain('unchanged')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_force_bypasses_checksum_and_retranslates(): void
    {
        $cache = new TranslationCache($this->cacheDir);
        $cache->snapshotFile($this->langDir.'/fr/nav.php', ['home' => 'Accueil', 'cv' => 'CV']);

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push($this->apiResponse(['home' => 'Startseite', 'cv' => 'Lebenslauf']), 200)
                ->push($this->apiResponse(['boot.skip' => 'Überspringen']), 200),
        ]);

        $this->artisan('i18n:translate', ['--locale' => 'de', '--force' => true])
            ->assertSuccessful();

        Http::assertSentCount(2); // once for nav.php, once for fr.json
    }

    // ── Translation key cache ─────────────────────────────────────────────────

    public function test_cached_keys_are_not_sent_to_api(): void
    {
        $cache = new TranslationCache($this->cacheDir);
        // Snapshot the file so no key appears as "new or changed"
        $cache->snapshotFile($this->langDir.'/fr/nav.php', ['home' => 'Accueil', 'cv' => 'CV']);
        // Pre-cache both translations
        $cache->putTranslations('de', 'nav', ['home' => 'Startseite', 'cv' => 'Lebenslauf']);

        Http::fake([
            // Only fr.json should trigger an API call
            'https://api.anthropic.com/v1/messages' => Http::response(
                $this->apiResponse(['boot.skip' => 'Überspringen']),
                200,
            ),
        ]);

        $this->artisan('i18n:translate', ['--locale' => 'de', '--force' => true])
            ->assertSuccessful();

        $translated = include $this->langDir.'/de/nav.php';
        $this->assertSame('Startseite', $translated['home']);
        $this->assertSame('Lebenslauf', $translated['cv']);

        Http::assertSentCount(1); // nav.php served from cache, only fr.json hit the API
    }

    public function test_new_key_in_source_triggers_partial_api_call(): void
    {
        $cache = new TranslationCache($this->cacheDir);
        // Snapshot with old content (no 'cv' key)
        $cache->snapshotFile($this->langDir.'/fr/nav.php', ['home' => 'Accueil']);
        // Pre-cache 'home' translation
        $cache->putTranslations('de', 'nav', ['home' => 'Startseite']);

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                // First call: only 'cv' (new key) should be sent
                ->push($this->apiResponse(['cv' => 'Lebenslauf']), 200)
                // Second call: fr.json (unchanged? No — no snapshot for it yet)
                ->push($this->apiResponse(['boot.skip' => 'Überspringen']), 200),
        ]);

        $this->artisan('i18n:translate', ['--locale' => 'de', '--force' => true])
            ->assertSuccessful();

        $translated = include $this->langDir.'/de/nav.php';
        $this->assertSame('Startseite', $translated['home']); // from cache
        $this->assertSame('Lebenslauf', $translated['cv']);   // freshly translated
    }

    // ── Confirmation prompt ───────────────────────────────────────────────────

    public function test_confirms_before_overwriting_existing_php_file_and_overwrites_on_yes(): void
    {
        mkdir($this->langDir.'/de', 0755, true);
        file_put_contents($this->langDir.'/de/nav.php', "<?php\nreturn ['home' => 'Alt'];\n");

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push($this->apiResponse(['home' => 'Startseite', 'cv' => 'Lebenslauf']), 200)
                ->push($this->apiResponse(['boot.skip' => 'Überspringen']), 200),
        ]);

        $this->artisan('i18n:translate', ['--locale' => 'de'])
            ->expectsConfirmation('  lang/de/nav.php already exists. Overwrite?', 'yes')
            ->assertSuccessful();

        $translated = include $this->langDir.'/de/nav.php';
        $this->assertSame('Startseite', $translated['home']);
    }

    public function test_confirms_before_overwriting_existing_php_file_and_skips_on_no(): void
    {
        mkdir($this->langDir.'/de', 0755, true);
        file_put_contents($this->langDir.'/de/nav.php', "<?php\nreturn ['home' => 'Alt'];\n");

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(
                $this->apiResponse(['boot.skip' => 'Überspringen']),
                200,
            ),
        ]);

        $this->artisan('i18n:translate', ['--locale' => 'de'])
            ->expectsConfirmation('  lang/de/nav.php already exists. Overwrite?', 'no')
            ->assertSuccessful();

        $kept = include $this->langDir.'/de/nav.php';
        $this->assertSame('Alt', $kept['home']);
    }

    // ── Source file edge cases ────────────────────────────────────────────────

    public function test_succeeds_with_warning_when_no_php_source_files_exist(): void
    {
        unlink($this->langDir.'/fr/nav.php');
        unlink($this->jsI18nDir.'/fr.json');

        Http::fake();

        $this->artisan('i18n:translate', ['--locale' => 'de'])
            ->expectsOutputToContain('No PHP translation files found')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_skips_php_file_that_does_not_return_array(): void
    {
        file_put_contents($this->langDir.'/fr/nav.php', "<?php\nreturn 'not an array';\n");
        unlink($this->jsI18nDir.'/fr.json');

        Http::fake();

        $this->artisan('i18n:translate', ['--locale' => 'de'])
            ->expectsOutputToContain('not a valid PHP array file')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertFileDoesNotExist($this->langDir.'/de/nav.php');
    }

    public function test_fails_when_js_source_file_contains_invalid_json(): void
    {
        file_put_contents($this->jsI18nDir.'/fr.json', 'not valid json {{{');

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push($this->apiResponse(['home' => 'Startseite', 'cv' => 'Lebenslauf']), 200),
        ]);

        $this->artisan('i18n:translate', ['--locale' => 'de', '--force' => true])
            ->expectsOutputToContain('not valid JSON')
            ->assertFailed();
    }

    public function test_fails_when_api_returns_incomplete_translation(): void
    {
        // API only translates 'home', missing 'cv' → cache incomplete → FAILURE
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push($this->apiResponse(['home' => 'Startseite']), 200)
                ->push($this->apiResponse(['boot.skip' => 'Überspringen']), 200),
        ]);

        $this->artisan('i18n:translate', ['--locale' => 'de', '--force' => true])
            ->expectsOutputToContain('Incomplete translation')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->langDir.'/de/nav.php');
    }

    public function test_fails_when_api_returns_incomplete_translation_for_js_file(): void
    {
        // nav.php succeeds, but fr.json API response is missing 'boot.skip' → FAILURE
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push($this->apiResponse(['home' => 'Startseite', 'cv' => 'Lebenslauf']), 200)
                ->push($this->apiResponse(['wrong.key' => 'Valeur']), 200),
        ]);

        $this->artisan('i18n:translate', ['--locale' => 'de', '--force' => true])
            ->expectsOutputToContain('Incomplete translation')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->jsI18nDir.'/de.json');
    }

    // ── API error handling ────────────────────────────────────────────────────

    public function test_api_http_error_displays_anthropic_message(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'error' => ['type' => 'invalid_request_error', 'message' => 'model not found'],
            ], 400),
        ]);

        $this->artisan('i18n:translate', ['--locale' => 'de', '--force' => true])
            ->expectsOutputToContain('model not found')
            ->assertFailed();
    }

    public function test_invalid_json_in_api_response_is_reported(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'voici la traduction : {{{invalid json']],
            ], 200),
        ]);

        $this->artisan('i18n:translate', ['--locale' => 'de', '--force' => true])
            ->expectsOutputToContain('Invalid JSON')
            ->assertFailed();
    }

    public function test_empty_content_in_api_response_is_reported(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => '']],
            ], 200),
        ]);

        $this->artisan('i18n:translate', ['--locale' => 'de', '--force' => true])
            ->expectsOutputToContain('Empty response')
            ->assertFailed();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
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
