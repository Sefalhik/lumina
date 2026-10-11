<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Tells a front controller whether the release it belongs to is still the one in service.
 *
 * A release is put in service by moving the `current` link of the site (LUMN-51). Apache follows
 * the link at once; OPcache does not. It finds the front controller by the path it is handed —
 * `…/current/public/index.php`, which never changes — and checks the date of the file it compiled
 * the first time, in the release that was replaced. Measured on preprod on 2026-10-11: sixteen
 * minutes after a switch, every page was still answered by the previous release, and would have
 * been until PHP restarted.
 *
 * So the front controller asks, on every request, which release the link names. It costs one
 * `readlink`. When the answer is not its own release, it hands the request to the front
 * controller of the one that is.
 *
 * This file is loaded by public/index.php before the autoloader, by its path: it depends on
 * nothing, and must stay that way.
 */
final class ReleaseInService
{
    /** What scripts/release.sh names a release directory: the moment it was made, and its commit. */
    private const string RELEASE_NAME = '/^\d{8}-\d{6}-[0-9a-f]{40}$/D';

    /**
     * The front controller of the release in service, when it is not the one of this release.
     *
     * Null means "serve this request yourself": the release is the one in service, or nothing
     * says otherwise — a developer machine, a site with no link, a link that names something
     * this class does not recognise as a release. A request is only ever handed to a release of
     * the same site, named the way releases are named.
     *
     * @param  string  $releaseDirectory  the root of the release the caller belongs to, links resolved
     */
    public static function frontController(string $releaseDirectory): ?string
    {
        $releases = dirname($releaseDirectory);
        $link = dirname($releases).'/current';

        if (basename($releases) !== 'releases' || ! is_link($link)) {
            return null;
        }

        // The scripts write the link `releases/<name>`, relative to the site. One made by hand —
        // the day a site has to be repaired — may name the same directory by its full path, or
        // end with the slash a shell completes it with: neither may leave the previous release
        // answering in silence. What the link is allowed to name does not change.
        $target = rtrim((string) readlink($link), '/');
        $name = match (true) {
            str_starts_with($target, $releases.'/') => substr($target, strlen($releases) + 1),
            str_starts_with($target, 'releases/') => substr($target, strlen('releases/')),
            default => '',
        };

        if (preg_match(self::RELEASE_NAME, $name) !== 1) {
            return null;
        }

        if ($name === basename($releaseDirectory)) {
            return null;
        }

        $frontController = $releases.'/'.$name.'/public/index.php';

        return is_file($frontController) ? $frontController : null;
    }

    /**
     * Makes PHP forget the release that was replaced: the paths this process resolved through
     * the link, and every script the code cache compiled. The request that follows then starts
     * from the release in service, and nothing has to be handed over any more.
     *
     * Measured on preprod on 2026-10-11: a reset asked from a web request, and the next one was
     * answered by the new release. OPcache is part of PHP itself since 8.5: there is no build
     * of the PHP this project requires where the function is missing.
     */
    public static function forgetTheOneItReplaced(): void
    {
        clearstatcache(true);
        opcache_reset();
    }
}
