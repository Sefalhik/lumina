<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
            // E2E test helper routes. GET /e2e/admin-auth creates an admin,
            // sets the 2FA session flag and logs the caller in — no password,
            // no TOTP. It is a backdoor, and it must only ever exist on a
            // machine nobody else can reach.
            //
            // This is an ALLOWLIST on purpose. The previous denylist form,
            // ! environment('production'), published the backdoor on every
            // environment whose name had not been anticipated — starting with
            // the preprod site, which is on the public internet. Same reasoning
            // as config/seo.php → public_routes: a forgotten denylist entry
            // exposes something, a forgotten allowlist entry hides something.
            if (app()->environment(['local', 'testing'])) {
                Route::middleware('web')
                    ->group(base_path('routes/e2e.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TLS is terminated by the hosting provider's front proxy, so PHP only
        // ever sees a plain HTTP request. Without this, the app builds http://
        // URLs on an https:// page and — worse — SESSION_SECURE_COOKIE=true
        // produces a cookie the browser refuses to send back, which reads as an
        // endless login loop rather than as a configuration problem.
        //
        // at: '*' trusts any proxy, which is the workable choice when the front
        // end's address is not contractually stable. The cost is that
        // X-Forwarded-For becomes caller-controlled, so $request->ip() is not
        // evidence: today its only reader is GeoController, which resolves a
        // location for the caller's own boot sequence. Anything that decides
        // access or counts attempts per IP must not rely on it as it stands.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

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
