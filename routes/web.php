<?php

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
        Route::get('/', fn () => view('home'))->name('home');
        Route::get('/cv', fn () => view('cv'))->name('cv');
        Route::get('/projects', fn () => view('projects'))->name('projects');
        Route::get('/blog', fn () => view('blog.index'))->name('blog');
        Route::get('/blog/{slug}', fn (string $slug) => view('blog.show', ['slug' => $slug]))->name('blog.show');
    });
