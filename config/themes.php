<?php

/*
|--------------------------------------------------------------------------
| Themes
|--------------------------------------------------------------------------
|
| The site's themes, read from the file the Vue islands import —
| resources/js/themes.json — so that one list serves the script restoring a
| theme before the first paint (layouts/partials/restore-preferences) and
| the JavaScript switching it (utils/theme.js). Written in two places, the
| list would drift: a theme added on one side only would be refused, or
| restored without its styles (LUMN-11).
|
| `config:cache` reads it once, at deployment: the file only changes with a
| release.
|
*/

return [

    'ids' => array_column(
        json_decode((string) file_get_contents(resource_path('js/themes.json')), true, flags: JSON_THROW_ON_ERROR),
        'id',
    ),

];
