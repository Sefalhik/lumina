<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SiteIdentityRequest;
use App\Models\SiteIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class SiteIdentityController extends Controller
{
    /**
     * Show the site identity editor.
     *
     * firstOrNew() returns the existing row or an unsaved empty model, so the
     * form binds to something even before anything has been entered.
     */
    public function edit(): View
    {
        return view('admin.identity.edit', [
            'identity' => SiteIdentity::firstOrNew([]),
        ]);
    }

    /** Persist the identity submitted from the edit form. */
    public function update(SiteIdentityRequest $request): RedirectResponse
    {
        $identity = SiteIdentity::firstOrNew([]);
        $validated = $request->validated();

        $identity->fill([
            'full_name' => $validated['full_name'] ?? null,
            'contact_email' => $validated['contact_email'] ?? null,
            'github_url' => $validated['github_url'] ?? null,
            'linkedin_url' => $validated['linkedin_url'] ?? null,
            'mastodon_url' => $validated['mastodon_url'] ?? null,
        ]);

        // setTranslation() touches only the French value, leaving AI-generated
        // translations of the other locales intact.
        $identity->setTranslation('job_title', 'fr', (string) ($validated['job_title']['fr'] ?? ''));
        $identity->save();

        Log::info('Site identity updated', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'saved',
        ]);

        return redirect()
            ->route('admin.identity.edit', ['lang' => app()->getLocale()])
            ->with('success', __('admin.identity_saved'));
    }
}
