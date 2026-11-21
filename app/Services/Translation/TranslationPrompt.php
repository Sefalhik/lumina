<?php

declare(strict_types=1);

namespace App\Services\Translation;

use InvalidArgumentException;
use RuntimeException;

/**
 * Builds the system prompt of a translation request from the files of
 * `resources/prompts/translation/` (LUMN-33): the trade ({@see TranslationContext}), the syntax of
 * the format when it has one ({@see TextFormat}), then the rules every text follows — with the
 * target language, named with its variant ("British English"), and the glossary filled in.
 *
 * The prompts are files so that they read as text and change in a diff of their own; the bench of
 * docs/i18n/README.md reads the same files, and measures what ships.
 */
final readonly class TranslationPrompt
{
    /**
     * @param  array<string, string>  $languages  locale => language as the prompt names it
     * @param  list<string>  $glossary  terms written exactly as they are, in every language
     */
    public function __construct(
        private string $path,
        private array $languages,
        private array $glossary,
    ) {}

    public function system(TranslationContext $context, TextFormat $format, string $locale): string
    {
        $language = $this->languages[$locale] ?? throw new InvalidArgumentException(
            "No language is named for locale '{$locale}' in config/i18n.php (translation_languages)."
        );

        $files = [$context->promptFile(), $format->promptFile(), 'rules.md'];
        $parts = array_map($this->read(...), array_filter($files));

        return strtr(implode("\n\n", $parts), [
            '{{language}}' => $language,
            '{{glossary}}' => implode(', ', $this->glossary),
        ]);
    }

    private function read(string $file): string
    {
        $text = is_file($this->path.'/'.$file) ? file_get_contents($this->path.'/'.$file) : false;
        if ($text === false) {
            throw new RuntimeException("The translation prompt {$file} cannot be read from {$this->path}.");
        }

        return trim($text);
    }
}
