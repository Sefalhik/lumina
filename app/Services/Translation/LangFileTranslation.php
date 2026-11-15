<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Services\AnthropicTranslator;
use Illuminate\Support\Facades\Log;

/**
 * The work behind `php artisan i18n:translate` (LUMN-110): plan what each target file needs, then
 * translate those keys and nothing else.
 *
 * **The files of the repository are the reference.** A target is read, the keys the planner
 * names are replaced or added, the keys the French no longer has are removed, and every other
 * value is written back as it was — a correction made by hand survives. The registry
 * ({@see TranslationSources}) only remembers which French each key was translated from.
 */
final class LangFileTranslation
{
    public function __construct(
        private readonly LangFiles $files,
        private readonly TranslationPlanner $planner,
        private readonly TranslationSources $sources,
        private readonly AnthropicTranslator $translator,
        private readonly string $langPath,
        private readonly string $jsPath,
    ) {}

    /**
     * Reads every source and target, and decides what a run would do. Writes nothing.
     *
     * @param  list<string>  $locales
     */
    public function plan(array $locales, bool $force): TranslationPlan
    {
        [$sources, $errors] = $this->discover();
        $tasks = [];
        $unreadable = [];

        foreach ($sources as $source) {
            foreach ($locales as $locale) {
                try {
                    $target = $this->files->read($source->path($locale), $source->format);
                } catch (InvalidTranslationFile $e) {
                    $errors[] = $e->getMessage();
                    $unreadable[$source->id][] = $locale;

                    continue;
                }

                $task = $this->task($source, $locale, $target, $force);
                if ($task !== null) {
                    $tasks[] = $task;
                }
            }
        }

        return new TranslationPlan($sources, $locales, $tasks, $unreadable, $errors);
    }

    /**
     * Runs a plan, then records in the registry what each locale received and what it still owes.
     *
     * @param  list<string>  $allLocales  every target locale, not only this run's
     * @param  (callable(TranslationTask, bool): void)|null  $onTask  told the outcome of each task
     * @return bool whether everything was read, translated and written
     */
    public function apply(TranslationPlan $plan, array $allLocales, ?callable $onTask = null): bool
    {
        $succeeded = ! $plan->hasErrors();
        $owed = [];

        foreach ($plan->tasks as $task) {
            $done = $this->run($task);
            if ($onTask !== null) {
                $onTask($task, $done);
            }
            if (! $done) {
                $owed[$task->source->id][$task->locale] = $task->keys;
                $succeeded = false;
            }
        }

        foreach ($plan->sources as $source) {
            $processed = array_diff($plan->locales, $plan->unreadableTargets[$source->id] ?? []);
            $outcome = ($owed[$source->id] ?? []) + array_fill_keys($processed, []);
            $this->sources->record($source->id, $source->strings, $outcome, $allLocales);
        }

        return $succeeded;
    }

    /**
     * @param  array<array-key, string>  $target
     */
    private function task(LangSource $source, string $locale, array $target, bool $force): ?TranslationTask
    {
        $keys = $this->planner->keysToTranslate(
            $source->strings,
            $this->sources->fingerprints($source->id),
            $target,
            $this->sources->pending($source->id, $locale),
            $force,
        );
        $obsolete = $this->planner->obsoleteKeys($source->strings, $target);

        return $keys === [] && $obsolete === [] ? null : new TranslationTask($source, $locale, $target, $keys, $obsolete);
    }

    private function run(TranslationTask $task): bool
    {
        $ctx = [
            'service' => self::class,
            'method' => __FUNCTION__,
            'source' => $task->source->id,
            'locale' => $task->locale,
        ];

        $translated = $task->keys === [] ? [] : $this->translator->translate($task->strings(), $task->locale);
        if ($translated === null) {
            Log::error('Translation of a target file failed', $ctx + ['step' => 'translate_failed', 'key_count' => count($task->keys)]);

            return false;
        }

        // In the order of the French, every value the run did not translate written back as it was.
        $merged = [];
        foreach (array_keys($task->source->strings) as $key) {
            $merged[$key] = $translated[$key] ?? $task->target[$key];
        }

        $this->files->write($task->source->path($task->locale), $task->source->format, $merged);

        Log::info('Target file written', $ctx + [
            'step' => 'file_written',
            'translated_count' => count($translated),
            'removed_count' => count($task->obsolete),
        ]);

        return true;
    }

    /**
     * @return array{0: list<LangSource>, 1: list<string>} the readable sources, and what could not be read
     */
    private function discover(): array
    {
        $candidates = [];
        foreach (glob($this->langPath.'/fr/*.php') ?: [] as $file) {
            $name = basename($file);
            $candidates[] = ['php:'.basename($name, '.php'), LangFiles::PHP, $this->langPath.'/%s/'.$name, 'lang/%s/'.$name];
        }
        $candidates[] = ['js:ui', LangFiles::JSON, $this->jsPath.'/%s.json', 'resources/js/i18n/%s.json'];

        $sources = [];
        $errors = [];
        foreach ($candidates as [$id, $format, $pathPattern, $labelPattern]) {
            $path = sprintf($pathPattern, 'fr');
            if (! is_file($path)) {
                continue;
            }

            try {
                $sources[] = new LangSource($id, $format, $this->files->read($path, $format), $pathPattern, $labelPattern);
            } catch (InvalidTranslationFile $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [$sources, $errors];
    }
}
