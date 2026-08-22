<?php

declare(strict_types=1);

namespace App\Services\Seo;

use Illuminate\Support\Facades\Log;

/**
 * Builds canonical and hreflang alternate URLs for public pages.
 *
 * Framework-agnostic beyond the route helper: callers pass the route name and
 * its parameters, so the service never touches the current Request.
 */
class LocalizedUrlService
{
    /** @var list<string> */
    private readonly array $indexableLocales;

    /**
     * @param  list<string>  $indexableLocales  locales advertised to crawlers
     * @param  list<string>  $supportedLocales  locales the site actually serves
     * @param  list<string>  $publicRoutes      route names allowed to carry SEO tags
     */
    public function __construct(
        array $indexableLocales,
        array $supportedLocales,
        private readonly array $publicRoutes,
    ) {
        $effective = array_values(array_intersect($indexableLocales, $supportedLocales));

        if (count($effective) !== count($indexableLocales)) {
            Log::warning('Indexable locales contain unsupported entries', [
                'service' => self::class,
                'method' => '__construct',
                'step' => 'locales_filtered',
                'ignored' => array_values(array_diff($indexableLocales, $supportedLocales)),
            ]);
        }

        $this->indexableLocales = $effective;
    }

    /**
     * Whether a route is allowed to carry canonical and hreflang tags.
     */
    public function isPublic(string $routeName): bool
    {
        return in_array($routeName, $this->publicRoutes, true);
    }

    /**
     * Self-referencing canonical URL, or null when the route is not public.
     *
     * Returned for every served locale, indexed or not: a page in a
     * non-indexed locale is distinct content, not a duplicate of the French
     * one, so it must never point its canonical elsewhere.
     *
     * @param  array<string, mixed>  $parameters  current route parameters
     */
    public function canonical(string $routeName, array $parameters, string $locale): ?string
    {
        if (! $this->isPublic($routeName)) {
            return null;
        }

        return $this->urlFor($routeName, $parameters, $locale);
    }

    /**
     * Alternate URLs keyed by hreflang value, including an 'x-default' entry.
     *
     * Empty when the route is not public, and empty when the current locale is
     * not itself indexed: hreflang requires reciprocity, so a page the indexed
     * set never references must not reference that set either.
     *
     * @param  array<string, mixed>  $parameters  current route parameters
     * @return array<string, string>
     */
    public function alternates(string $routeName, array $parameters, string $locale): array
    {
        if (! $this->isPublic($routeName) || ! in_array($locale, $this->indexableLocales, true)) {
            return [];
        }

        $alternates = [];

        foreach ($this->indexableLocales as $indexable) {
            $alternates[$indexable] = $this->urlFor($routeName, $parameters, $indexable);
        }

        // The root route resolves Accept-Language via LocaleResolver, which is
        // exactly x-default semantics: what to serve when no declared locale fits.
        $alternates['x-default'] = url('/');

        return $alternates;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function urlFor(string $routeName, array $parameters, string $locale): string
    {
        // Route parameters are preserved (blog.show carries a {slug}); only the
        // locale segment is swapped. An explicit 'lang' overrides the value set
        // by URL::defaults() in the SetLocale middleware.
        return route($routeName, [...$parameters, 'lang' => $locale], absolute: true);
    }
}
