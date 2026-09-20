<?php

declare(strict_types=1);

namespace App\Services\Smoke\Probes;

use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Site\DeployedSite;
use App\Services\Smoke\SmokeProbe;

/**
 * The hosting panel offers the project root as a document root, and its default is a directory that
 * merely 404s. Pointed one level too high, Apache serves .env — APP_KEY, database credentials, the
 * admin password — to anyone who asks. A curl would be enough.
 */
final class ExposureProbe implements SmokeProbe
{
    /** Files a misconfigured document root would serve: the first two leak every secret. */
    private const EXPOSED_PATHS = ['/.env', '/.git/HEAD', '/composer.json', '/vendor/autoload.php'];

    public function id(): string
    {
        return 'exposure';
    }

    public function label(): string
    {
        return 'Secrets and source files are not served';
    }

    public function check(DeployedSite $site): SmokeCheck
    {
        $exposed = [];
        foreach (self::EXPOSED_PATHS as $path) {
            $status = $site->get($path)?->status();
            if (! in_array($status, [403, 404], true)) {
                $exposed[] = $path.' → '.($status ?? 'no answer');
            }
        }

        return $exposed === []
            ? SmokeCheck::pass($this->id(), $this->label())
            : SmokeCheck::fail(
                $this->id(),
                $this->label(),
                implode('; ', $exposed),
                'The site root must be the public/ directory of the release, never the project root: check the site configuration in the hosting panel.',
            );
    }
}
