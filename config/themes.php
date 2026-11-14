<?php

/*
|--------------------------------------------------------------------------
| Themes
|--------------------------------------------------------------------------
|
| The site's themes, read from the file the Vue islands import —
| resources/js/themes.json — so that one list serves the layouts, the script
| restoring a theme before the first paint (layouts/partials/restore-preferences)
| and the JavaScript switching it (utils/theme.js). Written in several places,
| it would drift: a theme added on one side only would be refused, restored
| without its styles, or shown with another's favicon (LUMN-11, LUMN-124).
|
| The first theme of the list is the default: the one a page is served in, and
| the one a visitor goes back to when the stored choice is gone.
|
| `config:cache` reads it once, at deployment: the file only changes with a
| release.
|
*/

/** @var list<array{id: string, favicon: string}> $themes */
$themes = json_decode((string) file_get_contents(resource_path('js/themes.json')), true, flags: JSON_THROW_ON_ERROR);

return [

    'default' => $themes[0]['id'],

    'default_favicon' => $themes[0]['favicon'],

    'favicons' => array_column($themes, 'favicon', 'id'),

];
