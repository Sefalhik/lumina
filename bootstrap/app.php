<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // E2E test helper routes — never loaded in production.
            if (! app()->environment('production')) {
                Route::middleware('web')
                    ->group(base_path('routes/e2e.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // RedirectIfAuthenticated (guest middleware) — send authenticated users to home
        $middleware->redirectUsersTo(fn () => route('home', ['lang' => app()->getLocale()]));
        // Authenticate (auth middleware) — send unauthenticated users to the localised login page
        $middleware->redirectGuestsTo(fn () => route('login', ['lang' => app()->getLocale()]));

        $middleware->alias([
            'locale' => SetLocale::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'two_factor_verified' => EnsureTwoFactorVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
