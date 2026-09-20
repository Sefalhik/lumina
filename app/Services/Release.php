<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Identifies the release a deployment is serving.
 *
 * The deployment script writes the commit SHA into a RELEASE file at the root of each release
 * (LUMN-50/51). config/app.php reads it through this class, so `config:cache` freezes the value:
 * serving the header costs nothing per request. No file — a developer machine, or a deployment
 * that predates the script — means no release, never an empty string.
 */
final class Release
{
    public static function fromFile(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $release = trim((string) file_get_contents($path));

        return $release === '' ? null : $release;
    }
}
