<?php

declare(strict_types=1);

namespace App\Models\Contracts;

/**
 * A model whose prose `cms:translate` translates (LUMN-110).
 *
 * `spatie/laravel-translatable` provides the behaviour as a trait, and a trait is not a type: the
 * command could only call its methods on a bare `Model`, unchecked. A model listed in
 * `config('i18n.cms_models')` implements this interface; `HasTranslations` already fulfils it.
 */
interface TranslatableContent
{
    public function getTranslation(string $key, string $locale, bool $useFallbackLocale = true): mixed;

    public function setTranslation(string $key, string $locale, mixed $value): self;

    /**
     * @return array<int, string>
     */
    public function getTranslatableAttributes(): array;
}
