<?php

use App\Services\ReleaseInService;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

// A release is put in service by moving a link, and OPcache does not follow it: it goes on running
// the front controller of the release it compiled first. This one asks which release is in
// service, and hands the request over when it is not its own (LUMN-51) — see
// App\Services\ReleaseInService. Loaded by its path, before any autoloader — and once: when a
// request is handed over, the front controller that receives it finds the class already there.
if (! class_exists(ReleaseInService::class, false)) {
    require __DIR__.'/../app/Services/ReleaseInService.php';
}

if (($inService = ReleaseInService::frontController(dirname(__DIR__))) !== null) {
    ReleaseInService::forgetTheOneItReplaced();

    return require $inService;
}

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
