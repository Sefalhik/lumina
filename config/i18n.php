<?php

use App\Models\HomepageContent;

return [
    'default_locale' => 'fr',
    'supported_locales' => [
        'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et',
        'fi', 'fr', 'ga', 'hr', 'hu', 'it', 'lt', 'lv',
        'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv',
    ],

    /*
    | Locales advertised to search engines via hreflang.
    |
    | This is NOT the list of locales the site serves — that is
    | 'supported_locales' above, and it stays at 24. Every locale remains
    | routable, listed in the language switcher, and fed by i18n:translate.
    |
    | Only these locales are declared to crawlers. Machine-translated pages
    | that carry no market-specific intent are rarely served by search
    | engines, so advertising all 24 adds crawl surface without adding reach.
    |
    | Must be a subset of 'supported_locales' — entries outside it are
    | ignored at runtime and logged. See docs/seo-conventions.md.
    */
    'indexable_locales' => ['fr', 'en', 'de', 'it', 'nl'],
    'cms_models' => [
        HomepageContent::class,
    ],

    'native_names' => [
        'bg' => 'Български',
        'cs' => 'Čeština',
        'da' => 'Dansk',
        'de' => 'Deutsch',
        'el' => 'Ελληνικά',
        'en' => 'English',
        'es' => 'Español',
        'et' => 'Eesti',
        'fi' => 'Suomi',
        'fr' => 'Français',
        'ga' => 'Gaeilge',
        'hr' => 'Hrvatski',
        'hu' => 'Magyar',
        'it' => 'Italiano',
        'lt' => 'Lietuvių',
        'lv' => 'Latviešu',
        'mt' => 'Malti',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'pt' => 'Português',
        'ro' => 'Română',
        'sk' => 'Slovenčina',
        'sl' => 'Slovenščina',
        'sv' => 'Svenska',
    ],
];
