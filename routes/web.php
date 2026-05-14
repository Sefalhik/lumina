<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Dashboard\AdminController;
use App\Services\LocaleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Root redirect — detect preferred language from Accept-Language header
Route::get('/', function (Request $request, LocaleResolver $resolver) {
    return redirect()->to('/'.$resolver->resolve($request));
});

// Localised routes — /{lang}/...
Route::prefix('{lang}')
    ->where(['lang' => implode('|', config('i18n.supported_locales', ['fr', 'en']))])
    ->middleware('locale')
    ->group(function () {
        // Auth
        Route::middleware('guest')->group(function () {
            Route::get('/login', [LoginController::class, 'show'])->name('login');
            Route::post('/login', [LoginController::class, 'store'])->name('login.store');
        });

        Route::post('/logout', [LogoutController::class, 'destroy'])->name('logout')->middleware('auth');

        Route::middleware('auth')->group(function () {
            Route::get('/two-factor/setup', [TwoFactorController::class, 'showSetup'])->name('two-factor.setup');
            Route::post('/two-factor/setup', [TwoFactorController::class, 'storeSetup'])->name('two-factor.setup.store');
            Route::get('/two-factor/challenge', [TwoFactorController::class, 'showChallenge'])->name('two-factor.challenge');
            Route::post('/two-factor/challenge', [TwoFactorController::class, 'verifyChallenge'])->name('two-factor.challenge.store');
        });

        // Public
        Route::get('/', fn () => view('home'))->name('home');
        Route::get('/cv', fn () => view('cv'))->name('cv');
        Route::get('/projects', fn () => view('projects'))->name('projects');
        Route::get('/blog', fn () => view('blog.index'))->name('blog');
        Route::get('/blog/{slug}', fn (string $slug) => view('blog.show', ['slug' => $slug]))->name('blog.show');

        // Admin — auth + role:admin + 2FA verified
        Route::prefix('admin')
            ->middleware(['auth', 'role:admin', 'two_factor_verified'])
            ->name('admin.')
            ->group(function () {
                Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
            });
    });
