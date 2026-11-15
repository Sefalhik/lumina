<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * What one run does to one record in one locale: the fields it translates.
 */
final readonly class CmsTask
{
    /**
     * @param  list<string>  $fields
     */
    public function __construct(
        public CmsSource $source,
        public string $locale,
        public array $fields,
    ) {}

    /**
     * @return array<string, string> the French of the fields to translate
     */
    public function strings(): array
    {
        return array_intersect_key($this->source->strings, array_flip($this->fields));
    }
}
