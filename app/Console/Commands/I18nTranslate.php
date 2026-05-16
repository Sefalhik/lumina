<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AnthropicTranslator;
use App\Services\TranslationCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class I18nTranslate extends Command
{
    protected $signature = 'i18n:translate
                            {--locale= : Target locale (default: all non-French EU locales)}
                            {--force   : Bypass checksum check and overwrite without prompting}
                            {--dry-run : Show what would be generated without writing files}';

    protected $description = 'Translate i18n files from French (source of truth) to EU locales using the Anthropic API';

    /** @var list<string> */
    private array $targetLocales;

    public function __construct(
        private readonly AnthropicTranslator $translator,
        private readonly TranslationCache $cache,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apiKey = (string) config('services.anthropic.api_key', '');
        if (empty($apiKey) && ! $this->option('dry-run')) {
            $this->error('ANTHROPIC_API_KEY is not set. Add it to your .env file.');

            return self::FAILURE;
        }

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

        Log::info('i18n:translate started', [
            'service' => self::class,
            'method' => 'handle',
            'step' => 'start',
            'target_locales' => $this->targetLocales,
            'dry_run' => (bool) $this->option('dry-run'),
            'force' => (bool) $this->option('force'),
        ]);

        $exitCode = self::SUCCESS;

        $exitCode = $this->translatePhpFiles() === self::FAILURE ? self::FAILURE : $exitCode;
        $exitCode = $this->translateJsFiles() === self::FAILURE ? self::FAILURE : $exitCode;

        $this->newLine();
        $this->info($exitCode === self::SUCCESS ? 'All translations completed.' : 'Completed with errors.');

        Log::info('i18n:translate completed', [
            'service' => self::class,
            'method' => 'handle',
            'step' => 'complete',
            'success' => $exitCode === self::SUCCESS,
        ]);

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

    private function translatePhpFiles(): int
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
                Log::warning('i18n:translate skipped invalid PHP file', [
                    'service' => self::class,
                    'method' => 'translatePhpFiles',
                    'step' => 'skip_invalid',
                    'file' => $filename,
                ]);

                continue;
            }

            $flatSource = $this->translator->flattenJson($source);

            if (! $this->option('force') && $this->cache->isFileUnchanged($sourceFile)) {
                $this->line("  <fg=cyan>{$filename}</> — unchanged, skipping");
                Log::debug('i18n:translate PHP file unchanged', [
                    'service' => self::class,
                    'method' => 'translatePhpFiles',
                    'step' => 'skip_unchanged',
                    'file' => $filename,
                ]);

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

                $toTranslate = $this->keysToTranslate($locale, $fileKey, $flatSource, $changedKeys);
                if ($toTranslate === null) {
                    $exitCode = self::FAILURE;

                    continue;
                }

                $cached = $this->cache->getCachedTranslations($locale, $fileKey, array_keys($flatSource));

                if (count($cached) !== count($flatSource)) {
                    $this->error("    Incomplete translation for {$locale}/{$filename}");
                    Log::error('i18n:translate incomplete PHP translation', [
                        'service' => self::class,
                        'method' => 'translatePhpFiles',
                        'step' => 'incomplete',
                        'file' => $filename,
                        'locale' => $locale,
                        'cached_count' => count($cached),
                        'expected_count' => count($flatSource),
                    ]);
                    $exitCode = self::FAILURE;

                    continue;
                }

                $this->writePhpFile($targetFile, $cached);
                Log::info('i18n:translate PHP file written', [
                    'service' => self::class,
                    'method' => 'translatePhpFiles',
                    'step' => 'file_written',
                    'file' => $filename,
                    'locale' => $locale,
                    'key_count' => count($cached),
                ]);
            }

            if (! $this->option('dry-run')) {
                $this->cache->snapshotFile($sourceFile, $flatSource);
            }
        }

        return $exitCode;
    }

    private function translateJsFiles(): int
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
        $flatSource = $this->translator->flattenJson($source);

        if (! $this->option('force') && $this->cache->isFileUnchanged($sourceFile)) {
            $this->line('  <fg=cyan>fr.json</> — unchanged, skipping');
            Log::debug('i18n:translate JS file unchanged', [
                'service' => self::class,
                'method' => 'translateJsFiles',
                'step' => 'skip_unchanged',
                'file' => 'fr.json',
            ]);

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

            $toTranslate = $this->keysToTranslate($locale, $fileKey, $flatSource, $changedKeys);
            if ($toTranslate === null) {
                $exitCode = self::FAILURE;

                continue;
            }

            $cached = $this->cache->getCachedTranslations($locale, $fileKey, array_keys($flatSource));

            if (count($cached) !== count($flatSource)) {
                $this->error("    Incomplete translation for {$locale}/{$fileKey}");
                Log::error('i18n:translate incomplete JS translation', [
                    'service' => self::class,
                    'method' => 'translateJsFiles',
                    'step' => 'incomplete',
                    'locale' => $locale,
                    'cached_count' => count($cached),
                    'expected_count' => count($flatSource),
                ]);
                $exitCode = self::FAILURE;

                continue;
            }

            file_put_contents(
                $targetFile,
                json_encode($this->translator->unflattenJson($cached), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n",
            );
            Log::info('i18n:translate JS file written', [
                'service' => self::class,
                'method' => 'translateJsFiles',
                'step' => 'file_written',
                'locale' => $locale,
                'key_count' => count($cached),
            ]);
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

        $translated = $this->translator->translate($toTranslate, $locale);
        if ($translated === null) {
            $this->error("    Translation failed for locale {$locale}");
            Log::error('i18n:translate API call failed', [
                'service' => self::class,
                'method' => 'keysToTranslate',
                'step' => 'api_failure',
                'locale' => $locale,
                'file_key' => $fileKey,
            ]);

            return null;
        }

        $this->cache->putTranslations($locale, $fileKey, $translated);

        return $translated;
    }

    private function jsI18nPath(string $filename = ''): string
    {
        $base = (string) config('i18n.js_i18n_path', resource_path('js/i18n'));

        return $filename !== '' ? $base.'/'.$filename : $base;
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

        $nested = $this->translator->unflattenJson($data);
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
