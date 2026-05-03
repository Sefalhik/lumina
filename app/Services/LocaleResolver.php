<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LocaleResolver
{
    /** @param list<string> $supported */
    public function __construct(
        private readonly array $supported,
        private readonly string $default,
    ) {}

    public function resolve(Request $request): string
    {
        // getLanguages() returns Accept-Language values sorted by q-factor.
        // We iterate and return the first base code present in $supported.
        $locale = $this->default;
        foreach ($request->getLanguages() as $lang) {
            $base = strtolower(substr($lang, 0, 2));
            if (in_array($base, $this->supported, true)) {
                $locale = $base;
                break;
            }
        }

        Log::debug('Locale resolved from Accept-Language', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'locale_resolved',
            'locale' => $locale,
            'header' => $request->header('Accept-Language'),
        ]);

        return $locale;
    }
}
