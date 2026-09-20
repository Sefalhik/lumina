<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * The homepage falls back to neutral placeholders when its content row is empty — a deployment whose
 * seeder never ran serves a page that looks fine and says nothing.
 */
final class RealContentProbe implements SmokeProbe
{
    /** Localised strings the homepage shows only when its content row is empty. */
    private const FALLBACK_KEYS = ['home.fallback_tagline', 'home.fallback_subtitle', 'home.fallback_bio'];

    public function id(): string
    {
        return 'content';
    }

    public function label(): string
    {
        return 'The homepage shows its real content';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $found = [];
        foreach ($site->homepages() as $locale => $html) {
            foreach (self::FALLBACK_KEYS as $key) {
                $fallback = __($key, [], $locale);
                if (is_string($fallback) && $fallback !== $key && str_contains($html, $fallback)) {
                    $found[] = "/{$locale}";
                    break;
                }
            }
        }

        return $found === []
            ? SmokeCheck::pass($this->id(), $this->label())
            : SmokeCheck::fail(
                $this->id(),
                $this->label(),
                'Placeholder text on '.implode(', ', $found).': the homepage content row is empty.',
                'Run php artisan db:seed --class=HomepageContentSeeder --force on the server.',
            );
    }
}
