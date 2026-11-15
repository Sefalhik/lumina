<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * The syntax a translated text lives in, and what a translation must not break in it (LUMN-33).
 *
 * The prompt states the syntax (`resources/prompts/translation/formats/`), but a model that follows
 * its instructions scrupulously still slips now and then: {@see keeps()} checks every answer, and a
 * translation that lost, added or translated a placeholder — or wrote a character the format reads
 * as syntax — is refused instead of breaking a page.
 */
enum TextFormat: string
{
    /** `lang/*.php`: `:attribute` placeholders, `|` between plural forms. */
    case Laravel = 'laravel';

    /** `resources/js/i18n/*.json`: `{theme}` placeholders; `{ } @ $ |` are message syntax. */
    case VueI18n = 'vue-i18n';

    /** Prose stored in the database: no placeholder, no syntax. */
    case Plain = 'plain';

    public function promptFile(): ?string
    {
        return $this === self::Plain ? null : 'formats/'.$this->value.'.md';
    }

    /**
     * Whether a translation carries exactly the placeholders of its source, and no more of the
     * characters the format reads as syntax than the source had.
     */
    public function keeps(string $source, string $translation): bool
    {
        $expected = $this->placeholders($source);
        if ($this->placeholders($translation, $expected) !== $expected) {
            return false;
        }

        // The placeholders being the same on both sides, the braces inside them balance out: what
        // the translation has more of, it wrote outside them.
        foreach ($this->reservedCharacters() as $character) {
            if (substr_count($translation, $character) > substr_count($source, $character)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>|null  $known  null to read a source; its placeholders, to read a translation
     * @return list<string> sorted, so that two texts compare whatever the order grammar put them in
     */
    private function placeholders(string $text, ?array $known = null): array
    {
        $placeholders = match ($this) {
            self::Laravel => $this->laravelPlaceholders($text, $known),
            self::VueI18n => preg_match_all('/\{[A-Za-z_]+\}/', $text, $matches) > 0 ? $matches[0] : [],
            self::Plain => [],
        };
        sort($placeholders);

        return $placeholders;
    }

    /**
     * Laravel's own reading (`Translator::makeReplacements()`): `strtr()` replaces `:name`,
     * `:Name` and `:NAME` wherever they appear, the longest first. So a placeholder followed by
     * letters is still that placeholder — Finnish glues its case endings on (`:attributen`) — and
     * a colon inside a word is none: `n:o`, the Finnish "no.", `mailto:`, `10:30`.
     *
     * @param  list<string>|null  $known
     * @return list<string> lower-case names; a translation's unknown placeholder as written, with a `?`
     */
    private function laravelPlaceholders(string $text, ?array $known): array
    {
        preg_match_all('/(?<![\p{L}\p{N}]):([A-Za-z_]+)/u', $text, $matches);
        if ($known === null) {
            return array_map(strtolower(...), $matches[1]);
        }

        usort($known, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return array_map(fn (string $token) => $this->knownName($token, $known), $matches[1]);
    }

    /**
     * @param  list<string>  $known  longest first
     */
    private function knownName(string $token, array $known): string
    {
        foreach ($known as $name) {
            foreach ([$name, ucfirst($name), strtoupper($name)] as $form) {
                if (str_starts_with($token, $form)) {
                    return $name;
                }
            }
        }

        // Laravel would leave it as written: never one of the source's.
        return '?'.$token;
    }

    /**
     * @return list<string>
     */
    private function reservedCharacters(): array
    {
        return match ($this) {
            self::Laravel => ['|'],
            self::VueI18n => ['{', '}', '@', '$', '|'],
            self::Plain => [],
        };
    }
}
