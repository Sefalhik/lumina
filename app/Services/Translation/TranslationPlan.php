<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * Everything a run of `i18n:translate` would do, computed before it does any of it — so it can be
 * shown, priced and confirmed first.
 */
final readonly class TranslationPlan
{
    /**
     * @param  list<LangSource>  $sources  the sources that could be read
     * @param  list<string>  $locales  the locales of this run
     * @param  list<TranslationTask>  $tasks  only targets with something to do
     * @param  array<string, list<string>>  $unreadableTargets  source id → locales whose target could not be read
     * @param  list<string>  $errors  what could not be read, for display
     */
    public function __construct(
        public array $sources,
        public array $locales,
        public array $tasks,
        public array $unreadableTargets,
        public array $errors,
    ) {}

    public function keyCount(): int
    {
        return array_sum(array_map(fn (TranslationTask $task) => count($task->keys), $this->tasks));
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Whether running it calls the API. A plan that only removes obsolete keys does not.
     */
    public function needsTranslation(): bool
    {
        return $this->keyCount() > 0;
    }
}
