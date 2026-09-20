<?php

declare(strict_types=1);

namespace App\Services\Smoke\Site;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;

/**
 * The environment under test, as the probes see it (LUMN-49).
 *
 * It owns the HTTP client, the credentials, and the responses several probes share — the health
 * check and the public pages are fetched once, whatever the number of probes reading them. Probes
 * receive it, ask it, and never modify it.
 */
final class DeployedSite
{
    private ?Response $health = null;

    private bool $healthFetched = false;

    /** @var array<string, Response|null>|null responses of the public pages, keyed by path */
    private ?array $pages = null;

    public function __construct(
        public readonly SmokeTarget $target,
        public readonly CertificateInspector $certificates,
    ) {}

    /**
     * The answer to /up, fetched once.
     *
     * Retried until the site answers or the warm-up runs out: a freshly switched release may be
     * cold. This is the only retry in the whole suite — anywhere else it would hide a flaky
     * deployment behind a green report.
     */
    public function health(): ?Response
    {
        if ($this->healthFetched) {
            return $this->health;
        }

        $this->healthFetched = true;
        $attempts = max(1, (int) config('smoke.warmup_attempts'));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->health = $this->get('/up');
            if ($this->health !== null && $this->health->status() < 500) {
                return $this->health;
            }
            if ($attempt < $attempts) {
                Sleep::for((int) config('smoke.warmup_sleep_ms'))->milliseconds();
            }
        }

        return $this->health;
    }

    /**
     * @return array<string, Response|null> the public pages, fetched once, keyed by path
     */
    public function pages(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }

        $this->pages = [];

        foreach (self::publicPaths() as $path) {
            $this->pages[$path] = $this->get($path);
        }

        return $this->pages;
    }

    /**
     * The public pages this suite reads: the homepage in every indexable locale — the translation
     * probe compares them — then every other page of the SEO allowlist, in French.
     *
     * The list is **derived, never written down**. `config/seo.php` already answers "which routes
     * are public", and it has to be updated for canonical and hreflang anyway; reading it here means
     * a new public page is probed the day it is added, instead of the day someone remembers. A route
     * needing more than {lang} is skipped — the probe has no legitimate slug to invent.
     *
     * @return list<string>
     */
    public static function publicPaths(): array
    {
        /** @var list<string> $locales */
        $locales = config('i18n.indexable_locales', []);
        /** @var list<string> $names */
        $names = config('seo.public_routes', []);

        $paths = array_map(fn (string $locale): string => "/{$locale}", $locales);

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);

            if ($name === 'home' || $route === null || array_diff($route->parameterNames(), ['lang']) !== []) {
                continue;
            }

            $paths[] = '/'.ltrim(str_replace('{lang}', 'fr', $route->uri()), '/');
        }

        return $paths;
    }

    /**
     * @return array<string, string> decoded homepage HTML, keyed by locale, for the pages that answered
     */
    public function homepages(): array
    {
        $homepages = [];
        foreach ($this->pages() as $path => $response) {
            if (preg_match('#^/([a-z]{2})$#', $path, $match) === 1 && $response?->status() === 200) {
                $homepages[$match[1]] = html_entity_decode($response->body(), ENT_QUOTES | ENT_HTML5);
            }
        }

        return $homepages;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function get(string $pathOrUrl, bool $authenticate = true, array $headers = []): ?Response
    {
        return $this->request('get', $pathOrUrl, $authenticate, $headers);
    }

    /**
     * @param  'get'|'patch'  $method
     * @param  array<string, string>  $headers
     */
    public function request(string $method, string $pathOrUrl, bool $authenticate = true, array $headers = []): ?Response
    {
        $url = preg_match('#^https?://#', $pathOrUrl) === 1 ? $pathOrUrl : $this->target->url($pathOrUrl);

        try {
            return $this->client($authenticate)->withHeaders($headers)->send(strtoupper($method), $url);
        } catch (ConnectionException $e) {
            Log::warning('Smoke request could not connect', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'request',
                'url' => $url,
                'exception' => $e,
            ]);

            return null;
        }
    }

    private function client(bool $authenticate): PendingRequest
    {
        // Redirects are never followed: several probes assert on the redirect itself.
        $request = Http::timeout((int) config('smoke.timeout'))->withoutRedirecting();

        if ($authenticate && $this->target->hasCredentials()) {
            $request = $request->withBasicAuth((string) $this->target->basicUser, (string) $this->target->basicPassword);
        }

        return $request;
    }
}
