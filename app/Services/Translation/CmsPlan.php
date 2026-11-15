<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * What a `cms:translate` run would do, decided before anything is sent: the records walked, the
 * fields each locale needs, and the models that were refused or held no record.
 */
final readonly class CmsPlan
{
    /**
     * @param  list<CmsSource>  $sources  every record walked, a record with nothing to do included
     * @param  list<string>  $locales  the locales of this run
     * @param  list<CmsTask>  $tasks  only the record and locale pairs with something to translate
     * @param  list<string>  $emptyModels  models that hold no record, for display
     * @param  list<string>  $errors  models refused, for display
     */
    public function __construct(
        public array $sources,
        public array $locales,
        public array $tasks,
        public array $emptyModels,
        public array $errors,
    ) {}

    public function fieldCount(): int
    {
        return array_sum(array_map(fn (CmsTask $task) => count($task->fields), $this->tasks));
    }

    /**
     * How many fields of one record one locale gets — none when no task names the pair.
     */
    public function fieldsFor(CmsSource $source, string $locale): int
    {
        foreach ($this->tasks as $task) {
            if ($task->source === $source && $task->locale === $locale) {
                return count($task->fields);
            }
        }

        return 0;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function needsTranslation(): bool
    {
        return $this->fieldCount() > 0;
    }
}
