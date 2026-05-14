<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LogoutController extends Controller
{
    /**
     * Log the user out and fully reset the session state.
     *
     * Three distinct steps are required: logout clears the auth guard, invalidate
     * destroys session data, and regenerateToken issues a fresh CSRF token for the
     * next unauthenticated request. Skipping any one of them leaves a security gap.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        // invalidate() destroys all session data (prevents session fixation).
        // regenerateToken() issues a fresh CSRF token for the next unauthenticated request.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::info('User logged out', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'logout',
        ]);

        return redirect('/');
    }
}
