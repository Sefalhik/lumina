<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\LoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(private readonly LoginService $loginService) {}

    /** Display the login form. */
    public function show(): View
    {
        return view('auth.login');
    }

    /**
     * Attempt login with the submitted credentials.
     *
     * Throws a ValidationException on failure so Laravel's error bag handles the
     * response — no explicit redirect needed. On success, the session is regenerated
     * to prevent session fixation before issuing the redirect.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        if (! Auth::attempt($request->credentials(), $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        Log::info('User authenticated successfully', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'login_success',
        ]);

        return redirect()->intended($this->loginService->resolvePostLoginRedirect(Auth::user()));
    }
}
