<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorCodeRequest;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactorService) {}

    /**
     * Show the TOTP setup page with the QR code and the manual key.
     *
     * The secret is generated once and kept in the session until the user confirms
     * it with a valid code. Returning to this page reuses the same secret so the
     * QR code remains stable across page refreshes.
     */
    public function showSetup(Request $request): View|RedirectResponse
    {
        if ($request->user()->two_factor_confirmed_at) {
            return redirect()->route('two-factor.challenge', ['lang' => app()->getLocale()]);
        }

        $secret = $request->session()->get('auth.two_factor_setup_secret');
        if (! $secret) {
            // Generate a fresh secret and stash it in the session until the user confirms it.
            $secret = $this->twoFactorService->generateSecret();
            $request->session()->put('auth.two_factor_setup_secret', $secret);

            Log::info('Two-factor setup initiated — secret generated', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'secret_generated',
            ]);
        }

        return view('auth.two-factor-setup', [
            'qrSvg' => $this->twoFactorService->generateQrSvg($request->user()->email, $secret),
            'secret' => $secret,
        ]);
    }

    /**
     * Confirm the TOTP setup by verifying the first code from the authenticator app.
     *
     * Only after a valid code is submitted is the secret written to the database and
     * the session marked as 2FA-verified. This guarantees the user's device is
     * properly synced before the setup is considered complete.
     */
    public function storeSetup(TwoFactorCodeRequest $request): RedirectResponse
    {
        $secret = $request->session()->get('auth.two_factor_setup_secret');
        if (! $secret) {
            Log::warning('Two-factor setup submission with no pending secret in session', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'setup_secret_missing',
            ]);

            return back()->withErrors(['code' => __('auth.two_factor_setup_expired')]);
        }

        if (! $this->twoFactorService->verify($secret, $request->input('code'))) {
            Log::warning('Two-factor setup failed — invalid TOTP code submitted', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'setup_code_invalid',
            ]);

            return back()->withErrors(['code' => __('auth.two_factor_invalid_code')]);
        }

        $this->twoFactorService->confirm($request->user(), $secret);

        // Discard the temporary setup secret and mark 2FA as verified for this session.
        $request->session()->forget('auth.two_factor_setup_secret');
        $request->session()->put('auth.two_factor_verified', true);

        Log::info('Two-factor setup completed successfully', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'setup_confirmed',
        ]);

        return redirect()->route('admin.dashboard', ['lang' => config('app.locale')]);
    }

    /** Display the TOTP challenge prompt for an already-enrolled user. */
    public function showChallenge(Request $request): View|RedirectResponse
    {
        if (! $request->user()->two_factor_confirmed_at) {
            return redirect()->route('two-factor.setup', ['lang' => app()->getLocale()]);
        }

        if ($request->session()->get('auth.two_factor_verified')) {
            return redirect()->route('admin.dashboard', ['lang' => config('app.locale')]);
        }

        return view('auth.two-factor-challenge');
    }

    /**
     * Verify the TOTP code submitted during the login challenge.
     *
     * On success the session flag is set so EnsureTwoFactorVerified lets the user
     * through for the remainder of the session. redirect()->intended() honours any
     * URL the middleware stashed before diverting to this challenge.
     */
    public function verifyChallenge(TwoFactorCodeRequest $request): RedirectResponse
    {
        if (! $this->twoFactorService->verify($request->user()->two_factor_secret, $request->input('code'))) {
            Log::warning('Two-factor challenge failed — invalid TOTP code submitted', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'challenge_code_invalid',
            ]);

            return back()->withErrors(['code' => __('auth.two_factor_invalid_code')]);
        }

        $request->session()->put('auth.two_factor_verified', true);

        Log::info('Two-factor challenge passed', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'challenge_verified',
        ]);

        return redirect()->intended(route('admin.dashboard', ['lang' => config('app.locale')]));
    }
}
