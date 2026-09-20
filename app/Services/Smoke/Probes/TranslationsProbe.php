<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\Site\Html;
use App\Services\Smoke\SmokeProbe;

/**
 * Comparing whole pages would prove nothing: the interface strings differ between locales even when
 * the biography stayed in French. The hook paragraph carries data-smoke="bio" for this probe alone,
 * and SmokeMarkerTest fails if the template ever loses it.
 */
final class TranslationsProbe implements SmokeProbe
{
    public function id(): string
    {
        return 'translations';
    }

    public function label(): string
    {
        return 'Each locale shows its own translation';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $bios = [];
        $missing = [];

        foreach ($site->homepages() as $locale => $html) {
            $bio = Html::markerText($html, 'bio');
            $bio === null ? $missing[] = "/{$locale}" : $bios[$locale] = $bio;
        }

        if ($missing !== []) {
            return SmokeCheck::fail(
                $this->id(),
                $this->label(),
                'No data-smoke="bio" element on '.implode(', ', $missing).'.',
                'The homepage has no biography, or the template lost its data-smoke marker (resources/views/home.blade.php).',
            );
        }

        $french = $bios['fr'] ?? null;
        $untranslated = [];
        foreach ($bios as $locale => $bio) {
            if ($locale !== 'fr' && $bio === $french) {
                $untranslated[] = "/{$locale}";
            }
        }

        return $untranslated === []
            ? SmokeCheck::pass($this->id(), $this->label())
            : SmokeCheck::fail(
                $this->id(),
                $this->label(),
                implode(', ', $untranslated).' show the French biography.',
                'The translations were not shipped: regenerate database/data/homepage-content.php (cms:translate, then cms:export-seed) and redeploy. The seeder never overwrites a published row — edit it from the admin form if it predates them.',
            );
    }
}
