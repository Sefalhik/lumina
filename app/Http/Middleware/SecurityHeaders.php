<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Http\SecurityHeaders as Headers;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the security headers on every response (LUMN-37) — error pages, /up and the API included,
 * which is why it is registered first among the global middleware rather than in the web group.
 * The headers themselves are decided by App\Services\Http\SecurityHeaders.
 *
 * The CSP nonce is drawn here, before the response is rendered, so that the views stamp it on
 * their inline scripts. It is drawn on every request: under Octane the Vite instance outlives the
 * request, and a nonce that repeats is no nonce.
 */
class SecurityHeaders
{
    public function __construct(private readonly Headers $headers) {}

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        foreach ($this->headers->for($request->isSecure(), $nonce) as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
