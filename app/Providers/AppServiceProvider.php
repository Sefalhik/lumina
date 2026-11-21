<?php

namespace App\Providers;

use App\Models\SiteIdentity;
use App\Services\AnthropicTranslator;
use App\Services\Http\SecurityHeaders;
use App\Services\LocaleResolver;
use App\Services\Seo\LocalizedUrlService;
use App\Services\SiteIdentityService;
use App\Services\Smoke\Site\CertificateInspector;
use App\Services\Smoke\Site\StreamCertificateInspector;
use App\Services\Translation\CmsTranslation;
use App\Services\Translation\LangFiles;
use App\Services\Translation\LangFileTranslation;
use App\Services\Translation\TranslationModel;
use App\Services\Translation\TranslationPlanner;
use App\Services\Translation\TranslationPrompt;
use App\Services\Translation\TranslationSources;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;
use Laravel\Telescope\Telescope;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // laravel/telescope is a require-dev package, so a production install
        // (`composer install --no-dev`) ships neither the package nor the
        // parent class App\Providers\TelescopeServiceProvider extends. Listing
        // it in bootstrap/providers.php therefore made deployment fatal at
        // boot, before a single route was matched. Both conditions are needed:
        // class_exists() covers the missing package, the environment check
        // keeps debugging tooling off any machine but a developer's.
        if ($this->app->environment('local') && class_exists(Telescope::class)) {
            $this->app->register(TelescopeServiceProvider::class);
        }

        $this->app->bind(CertificateInspector::class, fn () => new StreamCertificateInspector(
            (float) config('smoke.timeout', 10),
        ));

        // Two registries of translation sources (LUMN-110): the one for lang/ and the JS islands is
        // committed beside the files it describes; the one for CMS content stays beside the local
        // database it describes.
        $this->app->bind(LangFileTranslation::class, fn ($app) => new LangFileTranslation(
            $app->make(LangFiles::class),
            $app->make(TranslationPlanner::class),
            new TranslationSources(lang_path('.translation-sources.json')),
            $app->make(AnthropicTranslator::class),
            lang_path(),
            (string) config('i18n.js_i18n_path', resource_path('js/i18n')),
        ));

        $this->app->bind(CmsTranslation::class, fn ($app) => new CmsTranslation(
            $app->make(AnthropicTranslator::class),
            $app->make(TranslationPlanner::class),
            new TranslationSources((string) config('i18n.cache_path', storage_path('app/i18n')).'/cms-sources.json'),
        ));

        // One model and one prompt per kind of text (LUMN-33): both decided in config/i18n.php and
        // resources/prompts/translation/, never in .env.
        $this->app->bind(AnthropicTranslator::class, fn () => new AnthropicTranslator(
            apiKey: (string) config('services.anthropic.api_key', ''),
            prompt: new TranslationPrompt(
                resource_path('prompts/translation'),
                config('i18n.translation_languages', []),
                config('i18n.glossary', []),
            ),
            models: array_map(TranslationModel::fromConfig(...), config('i18n.translation', [])),
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

        // Glue only: every decision — types, the development server — is made and unit-tested in
        // SecurityHeaders::fromConfiguration(). This reads the raw values and the hot file.
        $this->app->bind(SecurityHeaders::class, fn () => SecurityHeaders::fromConfiguration(
            indexable: config('seo.indexable'),
            configuration: config('security-headers'),
            developerMachine: $this->app->environment('local'),
            hotFile: Vite::isRunningHot() ? (string) file_get_contents(Vite::hotFile()) : null,
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The end-to-end servers run in `testing` (LUMN-106) and test the compiled build — what a
        // visitor receives — never the Vite dev server a developer may have running: its modules
        // load over the network, and a single failed import left every Vue island unmounted, at
        // random. A hot file that never exists makes Laravel read the build manifest whatever
        // public/hot says. PHPUnit, the other `testing` run, never reads Vite (Tests\TestCase).
        if ($this->app->environment('testing')) {
            Vite::useHotFile(storage_path('framework/testing-never-uses-the-vite-dev-server/hot'));
        }

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
