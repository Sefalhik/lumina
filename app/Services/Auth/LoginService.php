<?php

namespace App\Services\Auth;

use App\Models\User;

class LoginService
{
    /**
     * Return the post-login destination URL based on the user's role.
     *
     * Admin users are sent to the admin dashboard; everyone else lands on the
     * public home page. The {lang} parameter is always injected from the app locale
     * so the route helper doesn't throw on localised route definitions.
     */
    public function resolvePostLoginRedirect(User $user): string
    {
        if ($user->hasRole('admin')) {
            return route('admin.dashboard', ['lang' => config('app.locale')]);
        }

        return route('home', ['lang' => config('app.locale')]);
    }
}
