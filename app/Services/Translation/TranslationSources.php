<?php

declare(strict_types=1);

namespace App\Services\Translation;

use Illuminate\Support\Facades\Log;

/**
 * The registry of where every translation comes from: for each source and each key, the
 * fingerprint of the French value its translations were made from, and the keys a locale still
 * owes after a run that failed for it (LUMN-110).
 *
 * It answers one question — has the French changed since this key was translated? — and nothing
 * else. The translations themselves live in the files or the database they are served from, which
 * are the reference: nothing here can override them, and nothing here expires.
 *
 * Two instances exist. The one for `lang/` and `resources/js/i18n/` is committed
 * (`lang/.translation-sources.json`), so a worktree or another machine reads the same history the
 * files were written from. The one for CMS content sits in `storage/`, beside the local database
 * it describes.
 *
 * File layout, sorted so that two writes of the same state are byte-identical:
 *
 *     { "php:nav": { "sources": { "home": "3f2a…" }, "pending": { "de": ["home"] } } }
 */
final class TranslationSources
{
    public function __construct(private readonly string $path) {}

    /**
     * The fingerprint the registry stores for a French value. Also used to compare against it:
     * one function on both sides of the seam.
     */
    public static function fingerprint(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }

    /**
     * @return array<array-key, string> key → fingerprint of the French value last translated
     */
    public function fingerprints(string $source): array
    {
        return $this->load()[$source]['sources'] ?? [];
    }

    /**
     * @return list<string> keys this locale still owes, from a run that failed for it
     */
    public function pending(string $source, string $locale): array
    {
        return $this->load()[$source]['pending'][$locale] ?? [];
    }

    /**
     * Records the outcome of a run for one source.
     *
     * A locale the run processed owes exactly what it failed to receive. A locale it did not
     * process — another `--locale`, or a target it could not read — keeps what it owed, plus every
     * key whose French changed now: the fingerprints move forward for all locales at once, and
     * without that it would take the new fingerprint for a translation it never received.
     *
     * @param  array<array-key, string>  $strings  the current French, flat
     * @param  array<string, list<string>>  $owedByProcessedLocale  locale → keys still owed after the run
     * @param  list<string>  $allLocales  every target locale
     */
    public function record(string $source, array $strings, array $owedByProcessedLocale, array $allLocales): void
    {
        $previous = $this->fingerprints($source);
        $current = array_map(self::fingerprint(...), $strings);
        $changed = TranslationPlanner::names(array_filter(
            $current,
            fn (string $fingerprint, int|string $key) => isset($previous[$key]) && $previous[$key] !== $fingerprint,
            ARRAY_FILTER_USE_BOTH,
        ));

        $pending = [];
        foreach ($allLocales as $locale) {
            $owed = $owedByProcessedLocale[$locale] ?? [...$this->pending($source, $locale), ...$changed];
            $owed = array_values(array_unique(array_intersect(array_map(strval(...), $owed), TranslationPlanner::names($strings))));
            if ($owed !== []) {
                sort($owed);
                $pending[$locale] = $owed;
            }
        }

        ksort($current);
        ksort($pending);

        $registry = $this->load();
        $registry[$source] = ['sources' => $current, 'pending' => $pending];
        ksort($registry);
        $this->save($registry);

        Log::info('Translation sources recorded', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'recorded',
            'source' => $source,
            'key_count' => count($current),
            'pending_locale_count' => count($pending),
        ]);
    }

    /**
     * @return array<string, array{sources: array<array-key, string>, pending: array<string, list<string>>}>
     *
     * @throws InvalidTranslationFile when the registry exists but is not one
     */
    private function load(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->path), true);

        // Read as empty, an unreadable registry would make every translation look like it was
        // never recorded — adopted as is, French changes included. A merge conflict resolved by
        // hand can also leave valid JSON of the wrong shape. Both are refused.
        if (! is_array($data) || array_filter($data, fn (mixed $entry) => ! $this->isEntry($entry)) !== []) {
            throw InvalidTranslationFile::at($this->path, 'is not a registry of translation sources');
        }

        /** @var array<string, array{sources: array<array-key, string>, pending: array<string, list<string>>}> $data */
        return $data;
    }

    private function isEntry(mixed $entry): bool
    {
        return is_array($entry['sources'] ?? null)
            && is_array($entry['pending'] ?? null)
            && array_filter($entry['sources'], fn (mixed $fingerprint) => ! is_string($fingerprint)) === []
            && array_filter($entry['pending'], fn (mixed $owed) => ! is_array($owed) || ! array_is_list($owed)) === [];
    }

    /**
     * @param  array<string, array{sources: array<array-key, string>, pending: array<string, list<string>>}>  $registry
     */
    private function save(array $registry): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // An empty map must stay an object in JSON, or the next read gets a list.
        $encodable = array_map(fn (array $entry) => [
            'sources' => (object) $entry['sources'],
            'pending' => (object) $entry['pending'],
        ], $registry);

        file_put_contents($this->path, json_encode((object) $encodable, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
    }
}
