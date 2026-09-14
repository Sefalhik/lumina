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
        // No proxy is trusted, and that is the measured configuration — not an
        // oversight. Verified against the preprod host on 2026-09-14:
        //
        //   HTTPS                  = on                    Apache sets it itself
        //   REMOTE_ADDR            = the visitor's real IP  mod_remoteip runs upstream
        //   X-Forwarded-Proto      = https                 overwritten by the proxy,
        //                                                   even when the client sends http
        //   X-Forwarded-For / -Host = whatever the client sent, passed through verbatim
        //
        // Laravel therefore needs nothing to know the request is secure. And there is
        // no proxy address left to trust: mod_remoteip has already rewritten
        // REMOTE_ADDR to the visitor, so `trustProxies(at: '*')` would designate the
        // visitor as a trusted proxy — handing them $request->ip() through
        // X-Forwarded-For, and $request->getHost() through X-Forwarded-Host. The
        // second is host header poisoning: every absolute URL the app builds —
        // canonical, hreflang, redirects, a future password reset link — would carry
        // an attacker-chosen host.
        //
        // trustProxies was added on 2026-09-14 under the assumption that TLS was
        // terminated upstream and PHP saw plain HTTP. The assumption was wrong for
        // this host. Re-measure before adding it back for another one.

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
