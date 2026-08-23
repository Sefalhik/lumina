<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SiteIdentity;

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

            $links[] = [
                'key' => $key,
                'label' => $network['label'],
                'url' => trim($url),
            ];
        }

        return $links;
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
     * Job title in the active locale, or null when unset or blank.
     *
     * HasTranslations resolves the translation, so this returns a plain string
     * rather than the stored JSON.
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
