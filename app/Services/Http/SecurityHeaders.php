<?php

declare(strict_types=1);

namespace App\Services\Http;

use InvalidArgumentException;

/**
 * The security headers every response carries (LUMN-37). Configuration and the facts of one request
 * in, header names and values out: the middleware only sets them.
 *
 * Every value that reaches a header is checked when it enters, because a header is a list of tokens
 * separated by `;` — a source or a nonce carrying one would add a directive nobody wrote. A bad
 * configuration fails the first request, loudly, rather than producing a policy browsers ignore.
 */
final class SecurityHeaders
{
    /** Base64 and base64url, the alphabets a CSP nonce is written in. */
    private const NONCE_PATTERN = '/^[A-Za-z0-9+\/_-]+={0,2}$/D';

    private const DIRECTIVE_PATTERN = '/^[a-z]+(-[a-z]+)*$/D';

    /** Any printable ASCII except the characters that separate directives and policies, and space. */
    private const SOURCE_PATTERN = '/^[\x21-\x2b\x2d-\x3a\x3c-\x7e]+$/D';

    /** An origin only: a scheme, a host, an optional port — nothing after. */
    private const ORIGIN_PATTERN = '#^(https?)://([A-Za-z0-9.-]+|\[[0-9A-Fa-f:.]+\])(:\d{1,5})?$#D';

    /** @var array<string, list<string>> */
    private readonly array $policy;

    /**
     * Builds the service from the raw configuration. config() returns mixed: every value's type is
     * checked here, never cast — a missing HSTS duration cast to 0 would read max-age=0, which tells
     * browsers to forget HSTS, and a protection would be switched off by a typo.
     *
     * @param  mixed  $indexable  config('seo.indexable')
     * @param  mixed  $configuration  config('security-headers')
     * @param  bool  $developerMachine  whether the application runs on a developer's machine
     * @param  string|null  $hotFile  the contents of Vite's hot file; null while no development server runs
     */
    public static function fromConfiguration(
        mixed $indexable,
        mixed $configuration,
        bool $developerMachine,
        ?string $hotFile,
    ): self {
        if (! is_bool($indexable)) {
            throw new InvalidArgumentException('seo.indexable must be a boolean.');
        }
        if (! is_array($configuration)) {
            throw new InvalidArgumentException('security-headers must be an array.');
        }

        $hsts = $configuration['hsts'] ?? null;
        if (! is_array($hsts)) {
            throw new InvalidArgumentException('security-headers.hsts must be an array.');
        }
        $maxAge = $hsts['max_age'] ?? null;
        if (! is_int($maxAge)) {
            throw new InvalidArgumentException('security-headers.hsts.max_age must be an integer.');
        }
        $includeSubdomains = $hsts['include_subdomains'] ?? null;
        if (! is_bool($includeSubdomains)) {
            throw new InvalidArgumentException('security-headers.hsts.include_subdomains must be a boolean.');
        }

        $policy = $configuration['csp'] ?? null;
        if (! is_array($policy)) {
            throw new InvalidArgumentException('security-headers.csp must be an array.');
        }

        // The hot file exists whenever Vite's development server runs — but nothing stops one from
        // being left in a directory a server reads. The environment decides, not the file.
        $devServerUrl = $developerMachine && $hotFile !== null ? trim($hotFile) : null;

        return new self($indexable, $maxAge, $includeSubdomains, $policy, $devServerUrl);
    }

    /**
     * @param  array<mixed>  $policy  directive => sources, in the order they are sent; checked here
     * @param  string|null  $devServerUrl  Vite's development server, to allow while it serves the assets
     */
    public function __construct(
        private readonly bool $indexable,
        private readonly int $hstsMaxAge,
        private readonly bool $hstsIncludeSubdomains,
        array $policy,
        ?string $devServerUrl = null,
    ) {
        if ($hstsMaxAge < 0) {
            throw new InvalidArgumentException('The HSTS max-age cannot be negative.');
        }

        $this->policy = $this->withDevServer($this->validated($policy), $devServerUrl);
    }

    /**
     * @return array<string, string>
     */
    public function for(bool $secure, string $nonce): array
    {
        if (preg_match(self::NONCE_PATTERN, $nonce) !== 1) {
            throw new InvalidArgumentException('A CSP nonce must be a non-empty base64 token.');
        }

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=()',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy-Report-Only' => $this->serialise($nonce),
        ];

        // Over plain HTTP the header means nothing, and browsers ignore it: sending it would only
        // claim a protection that is not there.
        if ($secure) {
            $headers['Strict-Transport-Security'] = 'max-age='.$this->hstsMaxAge
                .($this->hstsIncludeSubdomains ? '; includeSubDomains' : '');
        }

        if (! $this->indexable) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }

        return $headers;
    }

    private function serialise(string $nonce): string
    {
        $policy = $this->policy;
        // Scripts carry the nonce, and so do styles: Vite injects <style> elements in development,
        // stamped with the nonce it reads from the page. Without its own directive, either falls
        // back to default-src; the nonce is never dropped, the directive is created from it.
        foreach (['script-src', 'style-src'] as $directive) {
            $policy[$directive] = [...($policy[$directive] ?? $policy['default-src'] ?? []), "'nonce-{$nonce}'"];
        }

        $directives = [];
        foreach ($policy as $directive => $sources) {
            $directives[] = trim($directive.' '.implode(' ', $sources));
        }

        return implode('; ', $directives);
    }

    /**
     * @param  array<mixed>  $policy
     * @return array<string, list<string>>
     */
    private function validated(array $policy): array
    {
        if ($policy === []) {
            throw new InvalidArgumentException('The content security policy has no directive.');
        }

        $valid = [];
        foreach ($policy as $directive => $sources) {
            if (! is_string($directive) || preg_match(self::DIRECTIVE_PATTERN, $directive) !== 1) {
                throw new InvalidArgumentException('A CSP directive name must be lowercase words joined by hyphens.');
            }
            if (! is_array($sources) || ! array_is_list($sources)) {
                throw new InvalidArgumentException('The sources of a CSP directive must be a list.');
            }
            foreach ($sources as $source) {
                if (! is_string($source) || preg_match(self::SOURCE_PATTERN, $source) !== 1) {
                    throw new InvalidArgumentException('A CSP source must be one token, without separators.');
                }
            }
            $valid[$directive] = $sources;
        }

        return $valid;
    }

    /**
     * @param  array<string, list<string>>  $policy
     * @return array<string, list<string>>
     */
    private function withDevServer(array $policy, ?string $url): array
    {
        if ($url === null) {
            return $policy;
        }

        if (preg_match(self::ORIGIN_PATTERN, $url, $origin) !== 1) {
            throw new InvalidArgumentException('The development server must be given as an http(s) origin.');
        }

        // Vite serves the scripts, the styles and the font files they import, and pushes reloads over
        // a WebSocket on the same origin.
        $socket = ($origin[1] === 'https' ? 'wss' : 'ws').substr($url, strlen($origin[1]));

        // A directive the policy leaves out falls back to default-src: it is created from it, or the
        // development server would become the only source allowed, and 'self' would be lost.
        $additions = [
            'script-src' => [$url],
            'style-src' => [$url],
            'font-src' => [$url],
            'connect-src' => [$url, $socket],
        ];
        foreach ($additions as $directive => $sources) {
            $policy[$directive] = [...($policy[$directive] ?? $policy['default-src'] ?? []), ...$sources];
        }

        return $policy;
    }
}
