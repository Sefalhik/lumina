<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells the smoke test which release answered (LUMN-49): X-Release carries the commit SHA the
 * deployment wrote into RELEASE. No release known — a developer machine — means no header, never an
 * empty one. The SHA is not a secret here: the repository is public.
 */
class AddReleaseHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $release = config('app.release');

        if (is_string($release) && $release !== '') {
            $response->headers->set('X-Release', $release);
        }

        return $response;
    }
}
