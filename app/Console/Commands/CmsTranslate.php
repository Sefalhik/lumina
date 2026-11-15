<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contracts\TranslatableContent;
use App\Services\AnthropicTranslator;
use App\Services\Translation\TranslationPlanner;
use App\Services\Translation\TranslationSources;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Translates the French of every CMS record into the other locales — the fields that need it and
 * no others (LUMN-110).
 *
 * **The database is the reference**, as the files are for `i18n:translate`: a field is translated
 * when its translation is missing, its French changed, or a previous run failed for it — never
 * because of its age. The same {@see TranslationPlanner} decides it, from a registry kept beside
 * the local database.
 */
class CmsTranslate extends Command
{
    protected $signature = 'cms:translate
                            {--locale= : Target locale (default: all non-French EU locales)}
                            {--force   : Translate every field again, not only those that need it}
                            {--dry-run : Show what would be translated without writing to DB}';

    protected $description = 'Translate CMS content from French (source of truth) to EU locales using the Anthropic API';

    public function __construct(
        private readonly AnthropicTranslator $translator,
        private readonly TranslationPlanner $planner,
        private readonly TranslationSources $sources,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $locales = $this->targetLocales();
        if ($locales === null || $locales === []) {
            return $locales === null ? self::FAILURE : self::SUCCESS;
        }

        /** @var list<class-string<Model>> $cmsModels */
        $cmsModels = config('i18n.cms_models', []);

        if (empty($cmsModels)) {
            $this->warn('No CMS models registered in config/i18n.php (cms_models).');

            return self::SUCCESS;
        }

        $this->info('Target locales : <fg=cyan>'.implode(', ', $locales).'</>');
        Log::info('cms:translate started', $this->logContext(__FUNCTION__, 'start', ['target_locales' => $locales, 'model_count' => count($cmsModels)]));

        $succeeded = true;
        foreach ($cmsModels as $modelClass) {
            $succeeded = $this->translateModel($modelClass, $locales) && $succeeded;
        }

        $this->newLine();
        $succeeded ? $this->info('All CMS translations completed.') : $this->error('Completed with errors: what failed is left to translate.');
        Log::info('cms:translate completed', $this->logContext(__FUNCTION__, 'complete', ['success' => $succeeded]));

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>|null null when the run must not go further
     */
    private function targetLocales(): ?array
    {
        if ((string) config('services.anthropic.api_key', '') === '' && ! $this->option('dry-run')) {
            $this->error('ANTHROPIC_API_KEY is not set. Add it to your .env file.');

            return null;
        }

        $requested = $this->option('locale');
        if ($requested === 'fr') {
            $this->warn('French is the source of truth — skipping.');

            return [];
        }

        if ($requested !== null && ! in_array($requested, $this->allTargetLocales(), true)) {
            $this->error("Locale '{$requested}' is not in the supported locales list.");

            return null;
        }

        return $requested === null ? $this->allTargetLocales() : [$requested];
    }

    /**
     * @return list<string>
     */
    private function allTargetLocales(): array
    {
        /** @var list<string> $supported */
        $supported = config('i18n.supported_locales', []);

        return array_values(array_diff($supported, ['fr']));
    }

    /**
     * @param  list<string>  $locales
     */
    private function translateModel(string $modelClass, array $locales): bool
    {
        if (! is_subclass_of($modelClass, Model::class) || ! is_subclass_of($modelClass, TranslatableContent::class)) {
            // A model that cannot store a translation would be translated, billed, and thrown away.
            $this->error("{$modelClass} is not a model implementing TranslatableContent.");
            Log::error('cms:translate refused a model that cannot hold translations', $this->logContext(__FUNCTION__, 'not_translatable', ['model' => $modelClass]));

            return false;
        }

        /** @var class-string<Model&TranslatableContent> $modelClass */
        $records = $modelClass::all();
        if ($records->isEmpty()) {
            $this->line('  <fg=cyan>'.class_basename($modelClass).'</> — no records found, skipping');
        }

        $succeeded = true;
        foreach ($records as $record) {
            $succeeded = $this->translateRecord($record, $locales) && $succeeded;
        }

        return $succeeded;
    }

    /**
     * @param  list<string>  $locales
     */
    private function translateRecord(Model&TranslatableContent $record, array $locales): bool
    {
        $source = 'cms_'.class_basename($record).'_'.$record->getKey();
        $french = $this->translations($record, 'fr');
        $owed = [];

        $this->line('  <fg=yellow>'.class_basename($record).' #'.$record->getKey().'</>');

        foreach ($locales as $locale) {
            $owed[$locale] = $this->translateForLocale($record, $source, $french, $locale);
        }

        if (! $this->option('dry-run')) {
            $this->sources->record($source, $french, $owed, $this->allTargetLocales());
        }

        return array_merge(...array_values($owed)) === [];
    }

    /**
     * @param  array<string, string>  $french
     * @return list<string> the fields this locale still owes after the run
     */
    private function translateForLocale(Model&TranslatableContent $record, string $source, array $french, string $locale): array
    {
        $fields = $this->planner->keysToTranslate(
            $french,
            $this->sources->fingerprints($source),
            $this->translations($record, $locale),
            $this->sources->pending($source, $locale),
            (bool) $this->option('force'),
        );

        $this->line("    → <fg=green>{$locale}</> ".count($fields).' to translate');

        if ($fields === [] || $this->option('dry-run')) {
            return [];
        }

        $translated = $this->translator->translate(array_intersect_key($french, array_flip($fields)), $locale);
        if ($translated === null) {
            $this->error("      Translation failed for locale {$locale}");
            Log::error('cms:translate left a record untranslated', $this->logContext(__FUNCTION__, 'translate_failed', ['source' => $source, 'locale' => $locale]));

            return $fields;
        }

        foreach ($translated as $field => $value) {
            $record->setTranslation($field, $locale, $value);
        }
        $record->save();

        return [];
    }

    /**
     * The values of every translatable field, in one locale. An empty French value is the
     * planner's to handle: it never sends one, and takes an empty translation of it as done.
     *
     * @return array<string, string>
     */
    private function translations(TranslatableContent $record, string $locale): array
    {
        $values = [];
        foreach ($record->getTranslatableAttributes() as $field) {
            $value = $record->getTranslation($field, $locale, false);
            if (is_string($value)) {
                $values[$field] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(string $method, string $step, array $extra): array
    {
        return ['service' => self::class, 'method' => $method, 'step' => $step] + $extra;
    }
}
