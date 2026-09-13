<?php

use App\Models\Experience;
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
    /*
    | Models whose content `php artisan cms:translate` walks. A model listed
    | here must use HasTranslations and declare a non-empty $translatable.
    |
    | The converse is the one that gets forgotten: a model that declares a
    | non-empty $translatable and is *not* listed here is never translated,
    | and nothing says so — cms:translate walks this list and only this list.
    | Experience was in that state between LUMN-18 and 2026-09-13.
    |
    | SiteIdentity is deliberately absent: none of its columns is translated.
    | See docs/site-identity.md.
    */
    'cms_models' => [
        Experience::class,
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
