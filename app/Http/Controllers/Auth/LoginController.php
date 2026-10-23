<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\AuthThrottleService;
use App\Services\Auth\LoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(
        private readonly LoginService $loginService,
        private readonly AuthThrottleService $throttle,
    ) {}

    /** Display the login form. */
    public function show(): View
    {
        return view('auth.login');
    }

    /**
     * Attempt login with the submitted credentials.
     *
     * Throws a ValidationException on failure so Laravel's error bag handles the
     * response — no explicit redirect needed. Session fixation is handled by
     * Auth::attempt() itself: SessionGuard::updateSession() regenerates both the
     * session id and the CSRF token, so no regenerate() call is repeated here
     * (LoginTest asserts the behaviour, whoever provides it).
     *
     * A locked-out caller is refused before the password is checked (LUMN-36).
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $email = $request->string('email')->toString();
        $ip = (string) $request->ip();

        $wait = $this->throttle->loginLockout($email, $ip);
        if ($wait !== null) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => $wait]),
            ]);
        }

        if (! Auth::attempt($request->credentials(), $request->boolean('remember'))) {
            $this->throttle->recordLoginFailure($email, $ip);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $this->throttle->clearLogin($email, $ip);

        Log::info('User authenticated successfully', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'login_success',
        ]);

        $user = Auth::user();
        assert($user instanceof User); // guaranteed: Auth::attempt() succeeded above

        return redirect()->intended($this->loginService->resolvePostLoginRedirect($user));
    }
}
