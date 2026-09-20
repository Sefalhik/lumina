<?php

declare(strict_types=1);

namespace App\Services\Smoke\Site;

use InvalidArgumentException;

/**
 * The environment under test.
 *
 * Basic credentials travel here and nowhere else: never in the URL, never in a report, never in a
 * log. A URL carrying `user:password@` is refused outright rather than stripped — silently
 * accepting it would teach the habit that puts a password in shell history and CI logs.
 */
final readonly class SmokeTarget
{
    public string $baseUrl;

    public function __construct(
        string $baseUrl,
        public ?string $expectedRelease = null,
        public ?string $basicUser = null,
        public ?string $basicPassword = null,
    ) {
        $parts = parse_url($baseUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new InvalidArgumentException('The URL must be absolute, http:// or https://.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(
                'Credentials do not belong in the URL — set SMOKE_BASIC_USER and SMOKE_BASIC_PASSWORD instead.',
            );
        }

        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function url(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }

    public function host(): string
    {
        return (string) parse_url($this->baseUrl, PHP_URL_HOST);
    }

    public function port(): int
    {
        return (int) (parse_url($this->baseUrl, PHP_URL_PORT) ?? ($this->isHttps() ? 443 : 80));
    }

    public function isHttps(): bool
    {
        return str_starts_with($this->baseUrl, 'https://');
    }

    public function hasCredentials(): bool
    {
        return $this->basicUser !== null && $this->basicUser !== '' && $this->basicPassword !== null;
    }

    /**
     * Scrub the credentials out of text this suite did not compose.
     *
     * Every line the suite writes itself is credential-free by construction. Text that comes from
     * elsewhere — the message of an exception a probe threw — is vetted by nobody, and it reaches
     * the report and the log like any other detail.
     */
    public function redact(string $text): string
    {
        foreach ([$this->basicPassword, $this->basicUser] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $text = str_replace($secret, '***', $text);
            }
        }

        return $text;
    }
}
