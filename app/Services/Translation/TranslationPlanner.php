<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * Decides, for one source and one locale, which keys need a translation — and only those (LUMN-110).
 *
 * A pure function of four maps: the French source, the fingerprints recorded when each key was
 * last translated, what the target holds today, and what the locale still owes. No file, no
 * clock, no API: every case is a unit test.
 */
final class TranslationPlanner
{
    /**
     * A key is translated when:
     *
     * - `$force` asks for everything;
     * - the target lacks it, or holds an empty string for a French value that is not empty;
     * - the locale still owes it, from a run that failed for it;
     * - its French changed since it was translated.
     *
     * A key the target holds, with no fingerprint recorded, is **adopted**: the target is the
     * reference, and the registry starts from what it finds. That is how the registry is created
     * without a single API call, and how CMS content seeded on another machine is not translated
     * again. A translation never ages: no key is translated because of when it was.
     *
     * @param  array<array-key, string>  $source  key → French value
     * @param  array<array-key, string>  $recorded  key → fingerprint of the French it was translated from
     * @param  array<array-key, string>  $target  key → current translation
     * @param  list<string>  $pending  keys the locale still owes
     * @return list<string> keys to translate, in source order
     */
    public function keysToTranslate(array $source, array $recorded, array $target, array $pending, bool $force): array
    {
        $owed = array_flip($pending);

        return array_values(array_filter(
            self::names($source),
            fn (string $key) => $force
                || ! isset($target[$key])
                || ($target[$key] === '' && $source[$key] !== '')
                || isset($owed[$key])
                || (isset($recorded[$key]) && $recorded[$key] !== TranslationSources::fingerprint($source[$key])),
        ));
    }

    /**
     * Keys the target holds that the French no longer has: removed from the target on the next
     * write, so a key deleted in French does not live on in 23 other languages.
     *
     * @param  array<array-key, string>  $source
     * @param  array<array-key, string>  $target
     * @return list<string>
     */
    public function obsoleteKeys(array $source, array $target): array
    {
        return self::names(array_diff_key($target, $source));
    }

    /**
     * @param  array<array-key, string>  $strings
     * @return list<string>
     */
    public static function names(array $strings): array
    {
        return array_map(strval(...), array_keys($strings));
    }
}
