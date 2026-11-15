<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * One French translation file and where its translations go: `lang/fr/nav.php` → `lang/{locale}/nav.php`,
 * `resources/js/i18n/fr.json` → `resources/js/i18n/{locale}.json`.
 *
 * Its id names it in the registry without a path — `php:nav`, `js:ui` — so the registry reads the
 * same from a worktree, another clone or another machine.
 */
final readonly class LangSource
{
    /**
     * @param  array<array-key, string>  $strings  the French, flat
     * @param  string  $pathPattern  the file's path, with `%s` for the locale
     * @param  string  $labelPattern  the same, relative to the repository, for display
     */
    public function __construct(
        public string $id,
        public string $format,
        public array $strings,
        private string $pathPattern,
        private string $labelPattern,
    ) {}

    /**
     * The syntax of its texts: a PHP file holds Laravel strings, the JSON file vue-i18n messages.
     */
    public function textFormat(): TextFormat
    {
        return $this->format === LangFiles::PHP ? TextFormat::Laravel : TextFormat::VueI18n;
    }

    public function path(string $locale): string
    {
        return sprintf($this->pathPattern, $locale);
    }

    public function label(string $locale): string
    {
        return sprintf($this->labelPattern, $locale);
    }
}
