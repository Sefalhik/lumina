<?php

/**
 * E2E test helpers — loaded only in non-production environments.
 *
 * These routes bypass normal authentication so Playwright tests can obtain
 * an admin session without simulating TOTP. Never deploy to production.
 */

use App\Models\HomepageContent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

Route::get('/e2e/admin-auth', function () {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $user = User::firstOrCreate(
        ['email' => 'e2e-admin@test.local'],
        [
            'name' => 'E2E Admin',
            'password' => Hash::make('e2e-password'),
        ],
    );

    // Assigned directly to bypass the $fillable guard.
    $user->two_factor_confirmed_at = now();
    $user->save();

    if (! $user->hasRole('admin')) {
        $user->assignRole('admin');
    }

    Auth::login($user);
    request()->session()->put('auth.two_factor_verified', true);
    request()->session()->regenerate();
    // Explicit save required: SESSION_DRIVER=redis writes in StartSession::terminate(),
    // which runs after the response is sent. The browser can follow the redirect before
    // Redis is written, resulting in an empty session on the next request.
    request()->session()->save();

    return redirect()->route('admin.dashboard', ['lang' => 'fr']);
})->middleware('web');

// Returns the current homepage FR content as JSON so tests can snapshot and restore it.
Route::get('/e2e/homepage-content', function () {
    $content = HomepageContent::first();

    return response()->json([
        'tagline' => $content?->getTranslation('tagline', 'fr', false) ?? '',
        'subtitle' => $content?->getTranslation('subtitle', 'fr', false) ?? '',
        'bio' => $content?->getTranslation('bio', 'fr', false) ?? '',
        'meta_description' => $content?->getTranslation('meta_description', 'fr', false) ?? '',
        'skills' => $content?->getTranslation('skills', 'fr', false) ?? '[]',
    ]);
})->middleware('web');

// Restores homepage FR content from a JSON body { tagline, subtitle, bio, meta_description }.
Route::post('/e2e/homepage-content', function () {
    $data = request()->validate([
        'tagline' => ['required', 'string'],
        'subtitle' => ['required', 'string'],
        'bio' => ['required', 'string'],
        'meta_description' => ['required', 'string'],
        'skills' => ['nullable', 'string'],
    ]);
    $content = HomepageContent::firstOrNew([]);
    $content->setTranslation('tagline', 'fr', $data['tagline']);
    $content->setTranslation('subtitle', 'fr', $data['subtitle']);
    $content->setTranslation('bio', 'fr', $data['bio']);
    $content->setTranslation('meta_description', 'fr', $data['meta_description']);
    $content->setTranslation('skills', 'fr', $data['skills'] ?? '[]');
    $content->save();

    return response()->json(['ok' => true]);
})->middleware('web');
