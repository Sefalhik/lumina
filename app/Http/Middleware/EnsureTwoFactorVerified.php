<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorVerified
{
    /**
     * Gate admin routes behind a mandatory two-factor check.
     *
     * Three states are handled in order:
     *   1. No confirmed secret → user has never enrolled → redirect to setup.
     *   2. Secret confirmed but not verified this session → redirect to challenge.
     *   3. Session flag present → pass through.
     *
     * The intended URL is stashed in state 2 so verifyChallenge can redirect back
     * to the originally requested admin URL after a successful challenge.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->two_factor_confirmed_at) {
            Log::info('Two-factor setup required — redirecting unenrolled user', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'redirect_to_setup',
            ]);

            return redirect()->route('two-factor.setup', ['lang' => app()->getLocale()]);
        }

        if (! $request->session()->get('auth.two_factor_verified')) {
            // Store the intended URL so the user lands on their original destination after challenge.
            $request->session()->put('url.intended', $request->fullUrl());

            Log::info('Two-factor challenge required — redirecting enrolled user', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'redirect_to_challenge',
            ]);

            return redirect()->route('two-factor.challenge', ['lang' => app()->getLocale()]);
        }

        return $next($request);
    }
}
