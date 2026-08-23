<?php

namespace App\Providers;

use App\Models\SiteIdentity;
use App\Services\AnthropicTranslator;
use App\Services\LocaleResolver;
use App\Services\Seo\LocalizedUrlService;
use App\Services\SiteIdentityService;
use App\Services\TranslationCache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;

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

        $this->app->bind(AnthropicTranslator::class, fn () => new AnthropicTranslator(
            apiKey: (string) config('services.anthropic.api_key', ''),
            model: (string) config('services.anthropic.model', 'claude-haiku-4-5-20251001'),
            nativeNames: config('i18n.native_names', []),
        ));

        $this->app->bind(LocaleResolver::class, fn () => new LocaleResolver(
            supported: config('i18n.supported_locales', ['fr']),
            default: config('i18n.default_locale', 'fr'),
        ));

        $this->app->bind(LocalizedUrlService::class, fn () => new LocalizedUrlService(
            indexableLocales: config('i18n.indexable_locales', ['fr']),
            supportedLocales: config('i18n.supported_locales', ['fr']),
            publicRoutes: config('seo.public_routes', []),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Feed canonical/hreflang data to the main layout so no controller has
        // to wire it. Glue only — the URL logic lives in LocalizedUrlService.
        ViewFacade::composer('layouts.app', function (View $view): void {
            $route = Route::current();
            $routeName = $route?->getName();

            if ($route === null || $routeName === null) {
                $view->with(['seoCanonical' => null, 'seoAlternates' => []]);

                return;
            }

            $service = app(LocalizedUrlService::class);
            $parameters = $route->parameters();
            $locale = app()->getLocale();

            $view->with([
                'seoCanonical' => $service->canonical($routeName, $parameters, $locale),
                'seoAlternates' => $service->alternates($routeName, $parameters, $locale),
            ]);
        });

        // Feed the footer its identity data. One row, one query per render —
        // not worth a cache layer and its invalidation until it shows up in a
        // profile. Glue only; the filtering lives in SiteIdentityService.
        ViewFacade::composer('layouts.app', function (View $view): void {
            $identity = SiteIdentity::first();
            $service = app(SiteIdentityService::class);

            $view->with([
                'siteName' => $service->displayName($identity),
                'siteJobTitle' => $service->jobTitle($identity),
                'siteEmail' => $service->contactEmail($identity),
                'siteSocialLinks' => $service->socialLinks($identity),
            ]);
        });
    }
}
