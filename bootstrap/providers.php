<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // TelescopeServiceProvider is deliberately absent: laravel/telescope lives
    // in require-dev, so on a `composer install --no-dev` deployment the parent
    // class it extends does not exist and booting it is fatal. AppServiceProvider
    // registers it, guarded, when the package is actually installed.
];
