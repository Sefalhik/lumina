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
    | here must implement TranslatableContent — HasTranslations fulfils it —
    | and declare a non-empty $translatable. cms:translate refuses any other.
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

    /*
    | The model and the effort for each kind of text (LUMN-33): interface labels
    | for `i18n:translate`, the author's prose for `cms:translate`. Chosen on a
    | bench run on 2026-11-15 — protocol, results and reasons in docs/i18n/README.md. A
    | model chosen by a measure is a decision of the project, not a setting of a
    | machine: it lives here, not in .env.
    |
    | - max_tokens holds the model's thinking as well as its answer.
    | - price: USD per million tokens, input then output, for the estimate shown
    |   before a run.
    | - output_ratio: tokens the model returns per token of French sent, thinking
    |   included — measured on the bench, for the same estimate.
    |
    | The prompts are files: resources/prompts/translation/.
    */
    'translation' => [
        'interface' => [
            'model' => 'claude-haiku-5-5',
            'effort' => 'low',
            'max_tokens' => 16000,
            'price' => [0.10, 0.50],
            'output_ratio' => 1.6,
        ],
        'prose' => [
            'model' => 'claude-opus-5-5',
            'effort' => 'medium',
            'max_tokens' => 16000,
            'price' => [4.00, 20.00],
            'output_ratio' => 4.0,
        ],
    ],

    /*
    | Terms the translation leaves exactly as written, in every language — sent
    | in every request (resources/prompts/translation/rules.md). A closed list:
    | a word that is not here is translated. "Keep technical terms untranslated",
    | left to the model's judgement, is what kept "biocortex actif" in French.
    | The names of people, software and standards need no entry: the prompt
    | keeps them as a class.
    */
    'glossary' => [
        'Tech Lead',
        'Neuromatrix',
        'biocortex',
        'online',
        'Sprawl',
        'Steam',
        'Neon Noir',
    ],

    /*
    | The language each locale is translated into, as the prompt names it. The
    | name fixes the variant where one exists: a European site writes British
    | English ("CV", not "Resume"), European Portuguese, the Spanish of Spain.
    */
    'translation_languages' => [
        'bg' => 'Bulgarian',
        'cs' => 'Czech',
        'da' => 'Danish',
        'de' => 'German',
        'el' => 'Greek',
        'en' => 'British English',
        'es' => 'Spanish as written in Spain',
        'et' => 'Estonian',
        'fi' => 'Finnish',
        'ga' => 'Irish',
        'hr' => 'Croatian',
        'hu' => 'Hungarian',
        'it' => 'Italian',
        'lt' => 'Lithuanian',
        'lv' => 'Latvian',
        'mt' => 'Maltese',
        'nl' => 'Dutch',
        'pl' => 'Polish',
        'pt' => 'European Portuguese',
        'ro' => 'Romanian',
        'sk' => 'Slovak',
        'sl' => 'Slovenian',
        'sv' => 'Swedish',
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
