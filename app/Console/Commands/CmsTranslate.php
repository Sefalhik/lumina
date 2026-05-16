<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AnthropicTranslator;
use App\Services\TranslationCache;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class CmsTranslate extends Command
{
    protected $signature = 'cms:translate
                            {--locale= : Target locale (default: all non-French EU locales)}
                            {--force   : Bypass cache and retranslate all fields}
                            {--dry-run : Show what would be translated without writing to DB}';

    protected $description = 'Translate CMS content from French (source of truth) to EU locales using the Anthropic API';

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

        /** @var list<class-string<Model>> $cmsModels */
        $cmsModels = config('i18n.cms_models', []);

        if (empty($cmsModels)) {
            $this->warn('No CMS models registered in config/i18n.php (cms_models).');

            return self::SUCCESS;
        }

        $this->info('Source of truth: <fg=cyan>fr</> (French)');
        $this->info('Target locales : <fg=cyan>'.implode(', ', $this->targetLocales).'</>');
        $this->newLine();

        Log::info('cms:translate started', [
            'service' => self::class,
            'method' => 'handle',
            'step' => 'start',
            'target_locales' => $this->targetLocales,
            'model_count' => count($cmsModels),
            'dry_run' => (bool) $this->option('dry-run'),
            'force' => (bool) $this->option('force'),
        ]);

        $exitCode = self::SUCCESS;

        foreach ($cmsModels as $modelClass) {
            if ($this->translateModel($modelClass) === self::FAILURE) {
                $exitCode = self::FAILURE;
            }
        }

        $this->newLine();
        $this->info($exitCode === self::SUCCESS ? 'All CMS translations completed.' : 'Completed with errors.');

        Log::info('cms:translate completed', [
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

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function translateModel(string $modelClass): int
    {
        if (! class_exists($modelClass)) {
            $this->error("Model class {$modelClass} does not exist.");
            Log::error('cms:translate model class not found', [
                'service' => self::class,
                'method' => 'translateModel',
                'step' => 'class_not_found',
                'model' => $modelClass,
            ]);

            return self::FAILURE;
        }

        $shortName = class_basename($modelClass);
        /** @var Collection<int, Model&object{translatable: array<int, string>}> $records */
        $records = $modelClass::all();

        if ($records->isEmpty()) {
            $this->line("  <fg=cyan>{$shortName}</> — no records found, skipping");
            Log::info('cms:translate model has no records', [
                'service' => self::class,
                'method' => 'translateModel',
                'step' => 'skip_empty',
                'model' => $shortName,
            ]);

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;

        foreach ($records as $record) {
            if ($this->translateRecord($record, $shortName) === self::FAILURE) {
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }

    /**
     * @param  Model&object{translatable: array<int, string>}  $record
     */
    private function translateRecord(Model $record, string $shortName): int
    {
        /** @var array<int, string> $translatableFields */
        $translatableFields = $record->translatable ?? [];
        $id = $record->getKey();
        $cacheKey = 'cms_'.$shortName.'_'.$id;

        $frValues = [];
        foreach ($translatableFields as $field) {
            /** @var mixed $val */
            $val = method_exists($record, 'getTranslation')
                ? $record->getTranslation($field, 'fr', false)
                : $record->getAttribute($field);
            $frValues[$field] = is_string($val) ? $val : '';
        }

        $changedFields = $this->option('force')
            ? $frValues
            : $this->cache->getChangedCmsFields($cacheKey, $frValues);

        $this->line("  <fg=yellow>{$shortName} #{$id}</>");

        $exitCode = self::SUCCESS;

        foreach ($this->targetLocales as $locale) {
            if ($this->translateRecordForLocale($record, $frValues, $changedFields, $cacheKey, $locale) === self::FAILURE) {
                $exitCode = self::FAILURE;
            }
        }

        if (! $this->option('dry-run')) {
            $this->cache->snapshotCmsRecord($cacheKey, $frValues);
        }

        return $exitCode;
    }

    /**
     * @param  Model&object{translatable: array<int, string>}  $record
     * @param  array<string, string>  $frValues
     * @param  array<string, string>  $changedFields
     */
    private function translateRecordForLocale(
        Model $record,
        array $frValues,
        array $changedFields,
        string $cacheKey,
        string $locale,
    ): int {
        $fieldKeys = array_keys($frValues);
        $missingKeys = $this->cache->getMissingKeys($locale, $cacheKey, $fieldKeys);

        $toTranslate = array_merge(
            $changedFields,
            array_intersect_key($frValues, array_flip($missingKeys)),
        );

        $this->line("    → <fg=green>{$locale}</>");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $logCtx = [
            'service' => self::class,
            'method' => 'translateRecordForLocale',
            'model' => get_class($record),
            'record_id' => $record->getKey(),
            'locale' => $locale,
            'cache_key' => $cacheKey,
        ];

        if (! empty($toTranslate)) {
            $translated = $this->translator->translate($toTranslate, $locale);
            if ($translated === null) {
                $this->error("      Translation failed for locale {$locale}");
                Log::error('cms:translate API call failed', $logCtx + ['step' => 'api_failure']);

                return self::FAILURE;
            }
            $this->cache->putTranslations($locale, $cacheKey, $translated);
        }

        $cached = $this->cache->getCachedTranslations($locale, $cacheKey, $fieldKeys);

        if (count($cached) !== count($frValues)) {
            $this->error("      Incomplete translation for locale {$locale}");
            Log::error('cms:translate incomplete translation', $logCtx + [
                'step' => 'incomplete',
                'cached_count' => count($cached),
                'expected_count' => count($frValues),
            ]);

            return self::FAILURE;
        }

        foreach ($cached as $field => $value) {
            if (method_exists($record, 'setTranslation')) {
                $record->setTranslation($field, $locale, $value);
            }
        }
        $record->save();

        Log::info('cms:translate record translated', $logCtx + [
            'step' => 'record_saved',
            'field_count' => count($cached),
            'api_called' => ! empty($toTranslate),
        ]);

        return self::SUCCESS;
    }
}
