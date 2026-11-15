<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * Reads and writes the two formats translations live in — `lang/{locale}/*.php` for Blade, and
 * `resources/js/i18n/{locale}.json` for the Vue islands — as flat dot-notation maps.
 *
 * Flat in, flat out: the translation logic never sees nesting. `validation.php` nests
 * `attributes.email`; the API, the planner and the registry all see one key.
 */
final class LangFiles
{
    public const PHP = 'php';

    public const JSON = 'json';

    /**
     * The flat strings of a translation file, or an empty map when the file does not exist yet —
     * a locale that has never been translated.
     *
     * @return array<array-key, string>
     *
     * @throws InvalidTranslationFile when the file exists but cannot be read as translations
     */
    public function read(string $path, string $format): array
    {
        if (! is_file($path)) {
            return [];
        }

        $data = $format === self::PHP ? include $path : json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            throw InvalidTranslationFile::at($path, $format === self::PHP ? 'does not return an array' : 'is not a JSON object');
        }

        return $this->flatten($data);
    }

    /**
     * @param  array<array-key, string>  $flat
     */
    public function write(string $path, string $format, array $flat): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $nested = $this->unflatten($flat);
        $content = $format === self::PHP
            ? "<?php\n\nreturn ".$this->exportPhpArray($nested, 0).";\n"
            : json_encode($nested, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

        file_put_contents($path, $content);
    }

    /**
     * @param  array<array-key, mixed>  $array
     * @return array<array-key, string>
     */
    public function flatten(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix.'.'.$key : (string) $key;
            if (is_array($value)) {
                $result += $this->flatten($value, $fullKey);
            } else {
                $result[$fullKey] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $result;
    }

    /**
     * Keys come back as PHP stores them: `'404'` is the integer 404, so every key is read as a
     * string before it is split.
     *
     * @param  array<array-key, string>  $flat
     * @return array<array-key, mixed>
     */
    public function unflatten(array $flat): array
    {
        $result = [];
        foreach ($flat as $key => $value) {
            $result = $this->mergeDeep($result, $this->expandKey(explode('.', (string) $key), $value));
        }

        return $result;
    }

    /**
     * @param  non-empty-list<string>  $keys
     * @return array<string, mixed>
     */
    private function expandKey(array $keys, string $value): array
    {
        $head = array_shift($keys);

        return $keys === [] ? [$head => $value] : [$head => $this->expandKey($keys, $value)];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function mergeDeep(array $base, array $overlay): array
    {
        foreach ($overlay as $k => $v) {
            $base[$k] = is_array($v) && isset($base[$k]) && is_array($base[$k])
                ? $this->mergeDeep($base[$k], $v)
                : $v;
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $array
     */
    private function exportPhpArray(array $array, int $depth): string
    {
        $indent = str_repeat('    ', $depth + 1);
        $lines = ['['];

        foreach ($array as $key => $value) {
            $exported = is_array($value) ? $this->exportPhpArray($value, $depth + 1) : $this->quote((string) $value);
            $lines[] = $indent.$this->quote((string) $key).' => '.$exported.',';
        }

        $lines[] = str_repeat('    ', $depth).']';

        return implode("\n", $lines);
    }

    private function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
