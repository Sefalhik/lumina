<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * What one run does to one target file: the keys it translates, and the keys it removes because
 * the French no longer has them.
 */
final readonly class TranslationTask
{
    /**
     * @param  array<array-key, string>  $target  the target as read before the run
     * @param  list<string>  $keys  keys to translate
     * @param  list<string>  $obsolete  keys to remove
     */
    public function __construct(
        public LangSource $source,
        public string $locale,
        public array $target,
        public array $keys,
        public array $obsolete,
    ) {}

    /**
     * @return array<array-key, string> the French of the keys to translate
     */
    public function strings(): array
    {
        return array_intersect_key($this->source->strings, array_flip($this->keys));
    }
}
