<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TranslationCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class I18nTranslate extends Command
{
    protected $signature = 'i18n:translate
                            {--locale= : Target locale (default: all non-French EU locales)}
                            {--force   : Bypass checksum check and overwrite without prompting}
                            {--dry-run : Show what would be generated without writing files}';

    protected $description = 'Translate i18n files from French (source of truth) to EU locales using the Anthropic API';

    /** @var array<string, string> */
    private array $nativeNames;

    /** @var list<string> */
    private array $targetLocales;

    public function __construct(private readonly TranslationCache $cache)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apiKey = (string) config('services.anthropic.api_key', '');
        if (empty($apiKey) && ! $this->option('dry-run')) {
            $this->error('ANTHROPIC_API_KEY is not set. Add it to your .env file.');

            return self::FAILURE;
        }

        $this->nativeNames = config('i18n.native_names', []);
        $allLocales = config('i18n.supported_locales', []);
        $resolved = $this->resolveTargetLocales($allLocales);

        if ($resolved === null) {
            return self::FAILURE;
        }

        $this->targetLocales = $resolved;

        if (empty($this->targetLocales)) {
            $this->warn('No target locales to process.');

            return self::SUCCESS;
        }

        $this->info('Source of truth: <fg=cyan>fr</> (French)');
        $this->info('Target locales : <fg=cyan>'.implode(', ', $this->targetLocales).'</>');
        $this->newLine();

        $exitCode = self::SUCCESS;

        $exitCode = $this->translatePhpFiles($apiKey) === self::FAILURE ? self::FAILURE : $exitCode;
        $exitCode = $this->translateJsFiles($apiKey) === self::FAILURE ? self::FAILURE : $exitCode;

        $this->newLine();
        $this->info($exitCode === self::SUCCESS ? 'All translations completed.' : 'Completed with errors.');

        return $exitCode;
    }

    /**
     * Returns null on validation error, empty array when intentionally skipped, list otherwise.
     *
     * @param  list<string>  $allLocales
     * @return list<string>|null
     */
    private function resolveTargetLocales(array $allLocales): ?array
    {
        $requested = $this->option('locale');
        if ($requested !== null) {
            if (! in_array($requested, $allLocales, true)) {
                $this->error("Locale '{$requested}' is not in the supported locales list.");

                return null;
            }
            if ($requested === 'fr') {
                $this->warn('French is the source of truth — skipping.');

                return [];
            }

            return [$requested];
        }

        return array_values(array_filter($allLocales, fn (string $l) => $l !== 'fr'));
    }

    private function translatePhpFiles(string $apiKey): int
    {
        $sourceDir = lang_path('fr');
        $files = glob($sourceDir.'/*.php');

        if ($files === false || empty($files)) {
            $this->warn('No PHP translation files found in lang/fr/');

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;

        foreach ($files as $sourceFile) {
            $filename = basename($sourceFile);
            $fileKey = pathinfo($filename, PATHINFO_FILENAME);
            $source = include $sourceFile;

            if (! is_array($source)) {
                $this->warn("Skipping {$filename}: not a valid PHP array file.");

                continue;
            }

            $flatSource = $this->flattenJson($source);

            if (! $this->option('force') && $this->cache->isFileUnchanged($sourceFile)) {
                $this->line("  <fg=cyan>{$filename}</> — unchanged, skipping");

                continue;
            }

            $changedKeys = $this->cache->getNewOrChangedKeys($sourceFile, $flatSource);

            foreach ($this->targetLocales as $locale) {
                $targetFile = lang_path("{$locale}/{$filename}");

                if (! $this->option('force') && ! $this->option('dry-run') && file_exists($targetFile)) {
                    if (! $this->confirm("  lang/{$locale}/{$filename} already exists. Overwrite?")) {
                        continue;
                    }
                }

                $this->line("  <fg=yellow>lang/fr/{$filename}</> → <fg=green>lang/{$locale}/{$filename}</>");

                if ($this->option('dry-run')) {
                    continue;
                }

                $toTranslate = $this->keysToTranslate($apiKey, $locale, $fileKey, $flatSource, $changedKeys);
                if ($toTranslate === null) {
                    $exitCode = self::FAILURE;

                    continue;
                }

                $cached = $this->cache->getCachedTranslations($locale, $fileKey, array_keys($flatSource));

                if (count($cached) !== count($flatSource)) {
                    $this->error("    Incomplete translation for {$locale}/{$filename}");
                    $exitCode = self::FAILURE;

                    continue;
                }

                $this->writePhpFile($targetFile, $cached);
            }

            if (! $this->option('dry-run')) {
                $this->cache->snapshotFile($sourceFile, $flatSource);
            }
        }

        return $exitCode;
    }

    private function translateJsFiles(string $apiKey): int
    {
        $sourceFile = $this->jsI18nPath('fr.json');

        if (! file_exists($sourceFile)) {
            $this->warn('No JS i18n source file found at resources/js/i18n/fr.json');

            return self::SUCCESS;
        }

        $raw = file_get_contents($sourceFile);
        $source = json_decode($raw !== false ? $raw : '{}', true);

        if (! is_array($source)) {
            $this->error('resources/js/i18n/fr.json is not valid JSON.');

            return self::FAILURE;
        }

        $fileKey = 'ui';
        $flatSource = $this->flattenJson($source);

        if (! $this->option('force') && $this->cache->isFileUnchanged($sourceFile)) {
            $this->line('  <fg=cyan>fr.json</> — unchanged, skipping');

            return self::SUCCESS;
        }

        $changedKeys = $this->cache->getNewOrChangedKeys($sourceFile, $flatSource);
        $exitCode = self::SUCCESS;

        foreach ($this->targetLocales as $locale) {
            $targetFile = $this->jsI18nPath("{$locale}.json");

            $this->line("  <fg=yellow>resources/js/i18n/fr.json</> → <fg=green>resources/js/i18n/{$locale}.json</>");

            if ($this->option('dry-run')) {
                continue;
            }

            $toTranslate = $this->keysToTranslate($apiKey, $locale, $fileKey, $flatSource, $changedKeys);
            if ($toTranslate === null) {
                $exitCode = self::FAILURE;

                continue;
            }

            $cached = $this->cache->getCachedTranslations($locale, $fileKey, array_keys($flatSource));

            if (count($cached) !== count($flatSource)) {
                $this->error("    Incomplete translation for {$locale}/{$fileKey}");
                $exitCode = self::FAILURE;

                continue;
            }

            file_put_contents(
                $targetFile,
                json_encode($this->unflattenJson($cached), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n",
            );
        }

        if (! $this->option('dry-run')) {
            $this->cache->snapshotFile($sourceFile, $flatSource);
        }

        return $exitCode;
    }

    /**
     * Resolves which keys need a fresh API call, calls the API for them, stores in cache.
     * Returns null on API failure. On success the cache is updated and ready to be read.
     *
     * @param  array<string, string>  $flatSource
     * @param  array<string, string>  $changedKeys
     * @return array<string, string>|null null on API error
     */
    private function keysToTranslate(
        string $apiKey,
        string $locale,
        string $fileKey,
        array $flatSource,
        array $changedKeys,
    ): ?array {
        $unchangedKeys = array_diff_key($flatSource, $changedKeys);
        $missingKeys = $this->cache->getMissingKeys($locale, $fileKey, array_keys($unchangedKeys));

        $toTranslate = array_merge(
            $changedKeys,
            array_intersect_key($flatSource, array_flip($missingKeys)),
        );

        if (empty($toTranslate)) {
            return [];
        }

        $translated = $this->callApi($apiKey, $toTranslate, $locale);
        if ($translated === null) {
            return null;
        }

        $this->cache->putTranslations($locale, $fileKey, $translated);

        return $translated;
    }

    /**
     * @param  array<string, string>  $keysToTranslate
     * @return array<string, string>|null
     */
    private function callApi(string $apiKey, array $keysToTranslate, string $locale): ?array
    {
        $nativeName = $this->nativeNames[$locale] ?? $locale;
        $sourceJson = json_encode($keysToTranslate, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
You are a professional translator specializing in UI localization.

Translate the following JSON key-value pairs from French to {$nativeName} ({$locale}).

Rules:
- Translate only the VALUES, never the keys
- Keep all placeholders like {theme}, {name}, {count} exactly as-is
- Keep proper names, brand names, and technical terms untranslated
- Output ONLY the translated JSON object, nothing else — no explanation, no markdown code blocks

Source JSON (French):
{$sourceJson}
PROMPT;

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model', 'claude-haiku-4-5-20251001'),
            'max_tokens' => 8192,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        if ($response->failed()) {
            $body = $response->json();
            $message = $body['error']['message'] ?? $response->body();
            $this->error("    API error for locale {$locale} (HTTP {$response->status()}): {$message}");
            Log::error('i18n:translate API request failed', [
                'service' => 'I18nTranslate',
                'method' => 'callApi',
                'step' => 'http_request',
                'locale' => $locale,
                'http_status' => $response->status(),
                'api_message' => $message,
            ]);

            return null;
        }

        $body = $response->json();
        $content = $body['content'][0]['text'] ?? null;

        if (! is_string($content) || empty($content)) {
            $this->error("    Empty response from API for locale {$locale}");
            Log::error('i18n:translate API returned empty content', [
                'service' => 'I18nTranslate',
                'method' => 'callApi',
                'step' => 'parse_response',
                'locale' => $locale,
                'stop_reason' => $body['stop_reason'] ?? null,
            ]);

            return null;
        }

        $content = preg_replace('/^```(?:json)?\s*/m', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/m', '', $content) ?? $content;

        $decoded = json_decode(trim($content), true);

        if (! is_array($decoded)) {
            $this->error("    Invalid JSON in API response for locale {$locale}");
            Log::error('i18n:translate API response is not valid JSON', [
                'service' => 'I18nTranslate',
                'method' => 'callApi',
                'step' => 'json_decode',
                'locale' => $locale,
                'stop_reason' => $body['stop_reason'] ?? null,
                'raw_excerpt' => mb_substr(trim($content), 0, 200),
            ]);

            return null;
        }

        return $decoded;
    }

    private function jsI18nPath(string $filename = ''): string
    {
        $base = (string) config('i18n.js_i18n_path', resource_path('js/i18n'));

        return $filename !== '' ? $base.'/'.$filename : $base;
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, string>
     */
    private function flattenJson(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix.'.'.$key : (string) $key;
            if (is_array($value)) {
                $result += $this->flattenJson($value, $fullKey);
            } else {
                $result[$fullKey] = (string) $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $flat
     * @return array<string, mixed>
     */
    private function unflattenJson(array $flat): array
    {
        $result = [];
        foreach ($flat as $key => $value) {
            $parts = explode('.', $key);
            $ref = &$result;
            foreach ($parts as $part) {
                if (! isset($ref[$part]) || ! is_array($ref[$part])) {
                    $ref[$part] = [];
                }
                $ref = &$ref[$part];
            }
            $ref = $value;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writePhpFile(string $path, array $data): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $nested = $this->unflattenJson($data);
        $content = "<?php\n\nreturn ".$this->exportPhpArray($nested, 0).";\n";

        file_put_contents($path, $content);
    }

    /**
     * @param  array<string, mixed>  $array
     */
    private function exportPhpArray(array $array, int $depth): string
    {
        $indent = str_repeat('    ', $depth + 1);
        $closing = str_repeat('    ', $depth);
        $lines = ['['];

        foreach ($array as $key => $value) {
            $escapedKey = str_replace("'", "\\'", (string) $key);
            if (is_array($value)) {
                $lines[] = $indent."'{$escapedKey}' => ".$this->exportPhpArray($value, $depth + 1).',';
            } else {
                $escapedValue = str_replace("'", "\\'", (string) $value);
                $lines[] = $indent."'{$escapedKey}' => '{$escapedValue}',";
            }
        }

        $lines[] = $closing.']';

        return implode("\n", $lines);
    }
}
