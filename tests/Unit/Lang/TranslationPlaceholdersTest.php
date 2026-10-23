<?php

namespace Tests\Unit\Lang;

use Tests\TestCase;

/**
 * The seam between the code and the translation files: a parameter the code passes — `:seconds`
 * in `auth.throttle` — only reaches the reader if every locale kept it. Each side is fine on its
 * own; nothing else checks that they agree.
 *
 * Found on LUMN-36: `lang/fi/auth.php` wrote `{seconds}`, the vue-i18n syntax, so Finnish readers
 * saw "{seconds}" instead of the wait they were refused for.
 */
class TranslationPlaceholdersTest extends TestCase
{
    public function test_every_locale_keeps_every_parameter_of_the_french_source(): void
    {
        $missing = [];

        foreach (glob(lang_path('fr/*.php')) ?: [] as $source) {
            $file = basename($source);

            foreach ($this->flatten(require $source) as $key => $french) {
                preg_match_all('/:[a-z_]+/i', $french, $parameters);

                foreach (glob(lang_path("*/{$file}")) ?: [] as $translation) {
                    $locale = basename(dirname($translation));
                    $translated = $this->flatten(require $translation)[$key] ?? '';

                    foreach (array_unique($parameters[0]) as $parameter) {
                        if (! str_contains($translated, $parameter)) {
                            $missing[] = "{$locale}/{$file} {$key} {$parameter}";
                        }
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'A translation lost a parameter the code passes to it.');
    }

    /**
     * @param  array<string, mixed>  $lines
     * @return array<string, string>
     */
    private function flatten(array $lines, string $prefix = ''): array
    {
        $flat = [];
        foreach ($lines as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, "{$prefix}{$key}.");
            } elseif (is_string($value)) {
                $flat[$prefix.$key] = $value;
            }
        }

        return $flat;
    }
}
