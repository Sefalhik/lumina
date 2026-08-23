<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a URL points at a PROFILE on the expected network.
 *
 * Protocol and well-formedness are Laravel's job (`url:https`). This rule only
 * judges what Laravel cannot know: which network the URL belongs to, and
 * whether it identifies a person rather than any page of that site.
 *
 * The shape check matters more than it looks. https://github.com/ satisfies
 * every hostname check ever written, and declaring the GitHub home page as
 * someone's identity in a sameAs statement is worse than declaring nothing —
 * it actively misinforms.
 */
class ProfileUrl implements ValidationRule
{
    private function __construct(
        private readonly ?string $expectedHost,
        private readonly string $pathPattern,
        private readonly string $example,
    ) {}

    /** github.com/username — 1 to 39 chars, alphanumeric and dashes. */
    public static function github(): self
    {
        return new self(
            'github.com',
            '#^/[A-Za-z\d][A-Za-z\d-]{0,38}/?$#',
            'https://github.com/utilisateur',
        );
    }

    /** linkedin.com/in/username — the /in/ segment is what marks a profile. */
    public static function linkedin(): self
    {
        return new self(
            'linkedin.com',
            '#^/in/[\w%-]+/?$#',
            'https://www.linkedin.com/in/utilisateur',
        );
    }

    /**
     * Mastodon is federated: mastodon.social, piaille.fr or a self-hosted
     * instance are all legitimate, so no host is pinned. What is constant
     * across every instance is the /@user profile path.
     */
    public static function mastodon(): self
    {
        return new self(
            null,
            '#^/@[\w.-]+/?$#',
            'https://instance/@utilisateur',
        );
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Blank is legitimate — every identity field is optional. `nullable`
        // owns that case; this rule only judges values that are present.
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $parts = parse_url(trim($value));

        if (! is_array($parts) || ! isset($parts['host'])) {
            $fail(__('admin.identity_url_invalid'));

            return;
        }

        if ($this->expectedHost !== null) {
            $host = strtolower($parts['host']);

            // Subdomains are accepted: www.linkedin.com is a normal profile host.
            if ($host !== $this->expectedHost && ! str_ends_with($host, '.'.$this->expectedHost)) {
                $fail(__('admin.identity_url_wrong_host', ['host' => $this->expectedHost]));

                return;
            }
        }

        if (preg_match($this->pathPattern, $parts['path'] ?? '') !== 1) {
            $fail(__('admin.identity_url_bad_shape', ['example' => $this->example]));
        }
    }
}
