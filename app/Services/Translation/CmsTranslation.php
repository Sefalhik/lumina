<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\Contracts\TranslatableContent;
use App\Services\AnthropicTranslator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The work behind `php artisan cms:translate` (LUMN-110, LUMN-33): plan what each record needs,
 * price it, then translate those fields and nothing else.
 *
 * **The database is the reference**, as the files are for {@see LangFileTranslation}: a field is
 * translated when its translation is missing, its French changed, or a previous run failed for it —
 * never because of its age. The registry, kept beside the local database, only remembers which
 * French each field was translated from. The text is the author's prose: it goes with the prose
 * prompt, to the prose model.
 */
final class CmsTranslation
{
    public function __construct(
        private readonly AnthropicTranslator $translator,
        private readonly TranslationPlanner $planner,
        private readonly TranslationSources $sources,
    ) {}

    /**
     * Reads every record of every model, and decides what a run would do. Writes nothing.
     *
     * @param  list<string>  $modelClasses
     * @param  list<string>  $locales
     */
    public function plan(array $modelClasses, array $locales, bool $force): CmsPlan
    {
        $sources = [];
        $tasks = [];
        $emptyModels = [];
        $errors = [];

        foreach ($modelClasses as $modelClass) {
            if (! is_subclass_of($modelClass, Model::class) || ! is_subclass_of($modelClass, TranslatableContent::class)) {
                // A model that cannot store a translation would be translated, billed, and thrown away.
                $errors[] = "{$modelClass} is not a model implementing TranslatableContent.";
                Log::error('cms:translate refused a model that cannot hold translations', $this->context(__FUNCTION__, 'not_translatable', ['model' => $modelClass]));

                continue;
            }

            /** @var class-string<Model&TranslatableContent> $modelClass */
            $records = $modelClass::all();
            if ($records->isEmpty()) {
                $emptyModels[] = class_basename($modelClass);
            }

            foreach ($records as $record) {
                $source = new CmsSource($record, 'cms_'.class_basename($record).'_'.$record->getKey(), $this->translations($record, 'fr'));
                $sources[] = $source;
                foreach ($locales as $locale) {
                    $fields = $this->fieldsToTranslate($source, $locale, $force);
                    if ($fields !== []) {
                        $tasks[] = new CmsTask($source, $locale, $fields);
                    }
                }
            }
        }

        return new CmsPlan($sources, $locales, $tasks, $emptyModels, $errors);
    }

    /**
     * What running the plan would send, with the prose model.
     */
    public function estimate(CmsPlan $plan): TranslationEstimate
    {
        $estimate = new TranslationEstimate;
        foreach ($plan->tasks as $task) {
            $estimate = $estimate->plus($this->translator->estimate($task->strings(), $task->locale, TranslationContext::Prose, TextFormat::Plain));
        }

        return $estimate;
    }

    public function model(): string
    {
        return $this->translator->model(TranslationContext::Prose);
    }

    /**
     * Runs a plan, then records in the registry what each locale received and what it still owes.
     *
     * @param  list<string>  $allLocales  every target locale, not only this run's
     * @param  (callable(CmsTask, bool): void)|null  $onTask  told the outcome of each task
     * @return bool whether every model was accepted and every field translated
     */
    public function apply(CmsPlan $plan, array $allLocales, ?callable $onTask = null): bool
    {
        $succeeded = ! $plan->hasErrors();
        $owed = [];

        foreach ($plan->tasks as $task) {
            $done = $this->run($task);
            if ($onTask !== null) {
                $onTask($task, $done);
            }
            if (! $done) {
                $owed[$task->source->id][$task->locale] = $task->fields;
                $succeeded = false;
            }
        }

        foreach ($plan->sources as $source) {
            $outcome = ($owed[$source->id] ?? []) + array_fill_keys($plan->locales, []);
            $this->sources->record($source->id, $source->strings, $outcome, $allLocales);
        }

        return $succeeded;
    }

    /**
     * @return list<string>
     */
    private function fieldsToTranslate(CmsSource $source, string $locale, bool $force): array
    {
        return $this->planner->keysToTranslate(
            $source->strings,
            $this->sources->fingerprints($source->id),
            $this->translations($source->record, $locale),
            $this->sources->pending($source->id, $locale),
            $force,
        );
    }

    private function run(CmsTask $task): bool
    {
        $translated = $this->translator->translate($task->strings(), $task->locale, TranslationContext::Prose, TextFormat::Plain);
        if ($translated === null) {
            Log::error('cms:translate left a record untranslated', $this->context(__FUNCTION__, 'translate_failed', [
                'source' => $task->source->id,
                'locale' => $task->locale,
            ]));

            return false;
        }

        foreach ($translated as $field => $value) {
            $task->source->record->setTranslation((string) $field, $task->locale, $value);
        }
        $task->source->record->save();

        return true;
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
    private function context(string $method, string $step, array $extra): array
    {
        return ['service' => self::class, 'method' => $method, 'step' => $step] + $extra;
    }
}
