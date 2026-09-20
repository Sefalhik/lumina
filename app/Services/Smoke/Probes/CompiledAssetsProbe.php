<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\Site\Html;
use App\Services\Smoke\SmokeProbe;

/**
 * public/build is gitignored, so the assets exist nowhere until something builds them on the server.
 * A page whose CSS 404s still answers 200, and a leftover public/hot points every visitor at a Vite
 * development server that is not running — both look healthy from a status code alone.
 */
final class CompiledAssetsProbe implements SmokeProbe
{
    public function id(): string
    {
        return 'assets';
    }

    public function label(): string
    {
        return 'The compiled CSS and JavaScript load';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $html = $site->pages()['/fr']?->body() ?? '';

        if (str_contains($html, '@vite/client')) {
            return SmokeCheck::fail(
                $this->id(),
                $this->label(),
                'The page loads its assets from a Vite development server.',
                'Delete public/hot on the server, then run npm run build.',
            );
        }

        $assets = Html::compiledAssets($html);
        if ($assets === []) {
            return SmokeCheck::fail(
                $this->id(),
                $this->label(),
                'The page references no compiled asset.',
                'Run npm run build on the server: public/build is gitignored and exists nowhere else.',
            );
        }

        $broken = [];
        foreach ($assets as $asset) {
            $response = $site->get($asset);
            $expectedType = str_ends_with($asset, '.css') ? 'css' : 'javascript';
            if ($response?->status() !== 200 || ! str_contains($response->header('Content-Type'), $expectedType)) {
                $broken[] = basename($asset).' → '.($response?->status() ?? 'no answer');
            }
        }

        return $broken === []
            ? SmokeCheck::pass($this->id(), $this->label(), count($assets).' files')
            : SmokeCheck::fail(
                $this->id(),
                $this->label(),
                implode('; ', $broken),
                'Rebuild with npm run build: the page points at files that are missing or served with the wrong type.',
            );
    }
}
