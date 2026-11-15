<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * The two kinds of text the site translates, which are two trades (LUMN-33): the labels of its
 * interface, translated by `i18n:translate`, and the author's prose, by `cms:translate`. Each has
 * its own prompt (`resources/prompts/translation/{value}.md`) and its own model and effort
 * (`config/i18n.php`, `translation.{value}`).
 */
enum TranslationContext: string
{
    case Interface = 'interface';
    case Prose = 'prose';

    public function promptFile(): string
    {
        return $this->value.'.md';
    }
}
