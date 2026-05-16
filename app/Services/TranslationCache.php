<?php

declare(strict_types=1);

namespace App\Services;

use DateInterval;
use DateTime;
use Illuminate\Support\Facades\Log;

class TranslationCache
{
    public function __construct(
        private readonly string $baseDir,
        private readonly int $ttlDays = 180,
    ) {}

    // ── Checksum / change detection ──────────────────────────────────────────

    public function isFileUnchanged(string $sourceFilePath): bool
    {
        $ctx = ['service' => self::class, 'method' => __FUNCTION__, 'file_path' => $sourceFilePath];
        $entry = $this->loadChecksumEntry($sourceFilePath);

        if ($entry !== null && $entry['hash'] === $this->hashFile($sourceFilePath)) {
            Log::debug('Translation source file checksum hit', $ctx + ['step' => 'checksum_hit']);

            return true;
        }

        Log::debug('Translation source file checksum miss', $ctx + ['step' => 'checksum_miss']);

        return false;
    }

    /**
     * Persist the hash and flat key snapshot of a source file after translation.
     *
     * @param  array<string, string>  $flatKeys
     */
    public function snapshotFile(string $sourceFilePath, array $flatKeys): void
    {
        $ctx = ['service' => self::class, 'method' => __FUNCTION__, 'file_path' => $sourceFilePath];
        $checksums = $this->readChecksums();

        $checksums[$sourceFilePath] = [
            'hash' => $this->hashFile($sourceFilePath),
            'keys' => $flatKeys,
        ];

        $this->writeChecksums($checksums);

        Log::info('Translation source file snapshot saved', $ctx + [
            'step' => 'snapshot_saved',
            'key_count' => count($flatKeys),
        ]);
    }

    /**
     * Returns keys that are new or whose source value has changed since the last snapshot.
     *
     * @param  array<string, string>  $currentFlatKeys
     * @return array<string, string>
     */
    public function getNewOrChangedKeys(string $sourceFilePath, array $currentFlatKeys): array
    {
        $ctx = ['service' => self::class, 'method' => __FUNCTION__, 'file_path' => $sourceFilePath];
        $previous = $this->loadChecksumEntry($sourceFilePath)['keys'] ?? [];

        $changed = array_filter(
            $currentFlatKeys,
            fn (string $value, string $key) => ! array_key_exists($key, $previous) || $previous[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        );

        Log::debug('Translation key diff computed', $ctx + [
            'step' => 'key_diff',
            'changed_count' => count($changed),
            'total_count' => count($currentFlatKeys),
        ]);

        return $changed;
    }

    /**
     * Returns keys that were in the previous snapshot but are absent from the current source.
     *
     * @param  array<string, string>  $currentFlatKeys
     * @return list<string>
     */
    public function getRemovedKeys(string $sourceFilePath, array $currentFlatKeys): array
    {
        $previous = array_keys($this->loadChecksumEntry($sourceFilePath)['keys'] ?? []);
        $removed = array_values(array_diff($previous, array_keys($currentFlatKeys)));

        if (! empty($removed)) {
            Log::debug('Removed translation keys detected', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'key_removed',
                'file_path' => $sourceFilePath,
                'removed_count' => count($removed),
            ]);
        }

        return $removed;
    }

    // ── Translation cache ────────────────────────────────────────────────────

    /**
     * Returns the subset of keys that have no valid (non-expired) cached translation.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function getMissingKeys(string $locale, string $fileKey, array $keys): array
    {
        $ctx = ['service' => self::class, 'method' => __FUNCTION__, 'locale' => $locale, 'file_key' => $fileKey];
        $cache = $this->loadTranslationCache($locale, $fileKey);
        $today = $this->today();

        $missing = array_values(array_filter(
            $keys,
            fn (string $key) => ! isset($cache[$key]) || $cache[$key]['expires_at'] < $today,
        ));

        if (empty($missing)) {
            Log::debug('All translation keys found in cache', $ctx + [
                'step' => 'cache_hit',
                'key_count' => count($keys),
            ]);
        } else {
            Log::debug('Translation cache miss for keys', $ctx + [
                'step' => 'cache_miss',
                'missing_count' => count($missing),
                'total_count' => count($keys),
            ]);
        }

        return $missing;
    }

    /**
     * Persist translations with a TTL-based expiry date.
     *
     * @param  array<string, string>  $translations  key → translated value
     */
    public function putTranslations(string $locale, string $fileKey, array $translations): void
    {
        $cache = $this->loadTranslationCache($locale, $fileKey);
        $expiresAt = (new DateTime)->add(new DateInterval("P{$this->ttlDays}D"))->format('Y-m-d');

        foreach ($translations as $key => $value) {
            $cache[$key] = ['value' => $value, 'expires_at' => $expiresAt];
        }

        $this->writeTranslationCache($locale, $fileKey, $cache);

        Log::info('Translations stored in cache', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'cache_store',
            'locale' => $locale,
            'file_key' => $fileKey,
            'key_count' => count($translations),
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Returns all valid (non-expired) cached translations for the given keys.
     *
     * @param  list<string>  $keys
     * @return array<string, string> key → translated value
     */
    public function getCachedTranslations(string $locale, string $fileKey, array $keys): array
    {
        $cache = $this->loadTranslationCache($locale, $fileKey);
        $today = $this->today();
        $result = [];

        foreach ($keys as $key) {
            if (isset($cache[$key]) && $cache[$key]['expires_at'] >= $today) {
                $result[$key] = $cache[$key]['value'];
            }
        }

        Log::debug('Cached translations retrieved', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'cache_read',
            'locale' => $locale,
            'file_key' => $fileKey,
            'hit_count' => count($result),
            'requested_count' => count($keys),
        ]);

        return $result;
    }

    // ── CMS change detection ─────────────────────────────────────────────────

    /**
     * Returns fields whose French value has changed since the last CMS record snapshot.
     *
     * @param  array<string, string>  $frValues  field → French value
     * @return array<string, string>
     */
    public function getChangedCmsFields(string $cacheKey, array $frValues): array
    {
        $previous = $this->loadChecksumEntry($cacheKey)['keys'] ?? [];

        $changed = array_filter(
            $frValues,
            fn (string $value, string $key) => ! array_key_exists($key, $previous) || $previous[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        );

        Log::debug('CMS field diff computed', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'cms_field_diff',
            'cache_key' => $cacheKey,
            'changed_count' => count($changed),
            'total_count' => count($frValues),
        ]);

        return $changed;
    }

    /**
     * Persist the French field values snapshot for a CMS record after translation.
     *
     * @param  array<string, string>  $frValues  field → French value
     */
    public function snapshotCmsRecord(string $cacheKey, array $frValues): void
    {
        $checksums = $this->readChecksums();
        $checksums[$cacheKey] = [
            'hash' => hash('sha256', json_encode($frValues, JSON_UNESCAPED_UNICODE) ?: ''),
            'keys' => $frValues,
        ];
        $this->writeChecksums($checksums);

        Log::info('CMS record snapshot saved', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'cms_snapshot_saved',
            'cache_key' => $cacheKey,
            'field_count' => count($frValues),
        ]);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function hashFile(string $path): string
    {
        return hash('sha256', (string) file_get_contents($path));
    }

    private function today(): string
    {
        return (new DateTime)->format('Y-m-d');
    }

    /**
     * @return array{hash: string, keys: array<string, string>}|null
     */
    private function loadChecksumEntry(string $sourceFilePath): ?array
    {
        $entry = $this->readChecksums()[$sourceFilePath] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * @return array<string, array{hash: string, keys: array<string, string>}>
     */
    private function readChecksums(): array
    {
        $path = $this->baseDir.'/checksums.json';
        if (! file_exists($path)) {
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($path), true);

            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            Log::error('Failed to read translation checksums file', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'file_read',
                'path' => $path,
                'exception' => $e,
            ]);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeChecksums(array $data): void
    {
        $path = $this->baseDir.'/checksums.json';
        $this->ensureDir($this->baseDir);

        try {
            file_put_contents(
                $path,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n",
            );
        } catch (\Throwable $e) {
            Log::error('Failed to write translation checksums file', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'file_write',
                'path' => $path,
                'exception' => $e,
            ]);
        }
    }

    /**
     * @return array<string, array{value: string, expires_at: string}>
     */
    private function loadTranslationCache(string $locale, string $fileKey): array
    {
        $path = $this->translationCachePath($locale, $fileKey);
        if (! file_exists($path)) {
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($path), true);

            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            Log::error('Failed to read translation cache file', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'file_read',
                'locale' => $locale,
                'file_key' => $fileKey,
                'path' => $path,
                'exception' => $e,
            ]);

            return [];
        }
    }

    /**
     * @param  array<string, array{value: string, expires_at: string}>  $data
     */
    private function writeTranslationCache(string $locale, string $fileKey, array $data): void
    {
        $dir = $this->baseDir.'/translations/'.$locale;
        $path = $this->translationCachePath($locale, $fileKey);
        $this->ensureDir($dir);

        try {
            file_put_contents(
                $path,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n",
            );
        } catch (\Throwable $e) {
            Log::error('Failed to write translation cache file', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'file_write',
                'locale' => $locale,
                'file_key' => $fileKey,
                'path' => $path,
                'exception' => $e,
            ]);
        }
    }

    private function translationCachePath(string $locale, string $fileKey): string
    {
        return $this->baseDir.'/translations/'.$locale.'/'.$fileKey.'.json';
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
