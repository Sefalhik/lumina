<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SiteIdentity;
use Illuminate\Support\Facades\Log;

/**
 * Turns a SiteIdentity record into the list of links worth rendering.
 *
 * Kept out of the view and the controller because two consumers are planned:
 * the footer, and the schema.org sameAs declaration.
 */
class SiteIdentityService
{
    /**
     * Networks in display order — most professionally relevant first.
     *
     * @var array<string, array{field: string, label: string}>
     */
    private const NETWORKS = [
        'github' => ['field' => 'github_url', 'label' => 'GitHub'],
        'linkedin' => ['field' => 'linkedin_url', 'label' => 'LinkedIn'],
        'mastodon' => ['field' => 'mastodon_url', 'label' => 'Mastodon'],
    ];

    /**
     * The networks the homepage hero offers, in place of the calls to action
     * whose pages are not ready yet (LUMN-104). GitHub stays in the footer.
     *
     * @var list<string>
     */
    private const HERO_NETWORKS = ['linkedin', 'mastodon'];

    /**
     * Social links that are actually filled in.
     *
     * Accepts null: the table is empty until someone fills the admin form, so
     * "no identity yet" is the initial state rather than an error.
     *
     * Labels are proper nouns and are never translated.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    public function socialLinks(?SiteIdentity $identity): array
    {
        if ($identity === null) {
            return [];
        }

        $links = [];

        foreach (self::NETWORKS as $key => $network) {
            $url = $identity->getAttribute($network['field']);

            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            if (! $this->isHttpsUrl(trim($url))) {
                // The admin form only saves https URLs, but the column accepts anything, and
                // this value lands in an href on every page: javascript: or data: would run.
                // Logged, not rendered — the network only, never the stored value.
                Log::warning('Site identity profile URL is not https — not rendered', [
                    'service' => self::class,
                    'method' => __FUNCTION__,
                    'step' => 'reject_url',
                    'network' => $key,
                ]);

                continue;
            }

            $links[] = [
                'key' => $key,
                'label' => $network['label'],
                'url' => trim($url),
            ];
        }

        return $links;
    }

    /**
     * An absolute https URL with a host. Anything else — another scheme, a
     * relative or protocol-relative path, a bare host — is refused.
     */
    private function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== '';
    }

    /**
     * The filled-in profiles the homepage hero shows: LinkedIn, then Mastodon.
     *
     * Built on socialLinks(), so blank values, trimming, labels and order follow
     * the footer's rules — the two can never disagree on a URL.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    public function heroLinks(?SiteIdentity $identity): array
    {
        return array_values(array_filter(
            $this->socialLinks($identity),
            static fn (array $link): bool => in_array($link['key'], self::HERO_NETWORKS, true),
        ));
    }

    /**
     * Contact address, or null when unset or blank.
     */
    public function contactEmail(?SiteIdentity $identity): ?string
    {
        $email = $identity?->getAttribute('contact_email');

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return trim($email);
    }

    /**
     * Display name, or null when unset or blank.
     */
    public function displayName(?SiteIdentity $identity): ?string
    {
        $name = $identity?->getAttribute('full_name');

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return trim($name);
    }

    /**
     * Job title, or null when unset or blank.
     *
     * The same string in every locale on purpose: it is what ties this site to
     * the profiles a sameAs statement points at. See docs/site-identity.md.
     */
    public function jobTitle(?SiteIdentity $identity): ?string
    {
        $title = $identity?->getAttribute('job_title');

        if (! is_string($title) || trim($title) === '') {
            return null;
        }

        return trim($title);
    }
}
