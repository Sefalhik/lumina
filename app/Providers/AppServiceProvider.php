<?php

namespace App\Providers;

use App\Services\LocaleResolver;
use App\Services\TranslationCache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TranslationCache::class, fn () => new TranslationCache(
            (string) config('i18n.cache_path', storage_path('app/i18n')),
        ));

        $this->app->bind(LocaleResolver::class, fn () => new LocaleResolver(
            supported: config('i18n.supported_locales', ['fr']),
            default: config('i18n.default_locale', 'fr'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
