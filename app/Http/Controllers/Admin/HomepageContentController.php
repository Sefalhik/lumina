<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HomepageContentRequest;
use App\Models\HomepageContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class HomepageContentController extends Controller
{
    /**
     * Show the homepage content editor.
     *
     * firstOrNew() returns the existing record or an unsaved empty model so the
     * form always has something to bind to, even before the seeder has run.
     */
    public function edit(): View
    {
        return view('admin.homepage.edit', [
            'content' => HomepageContent::firstOrNew([]),
        ]);
    }

    /** Persist the translated homepage content submitted from the edit form. */
    public function update(HomepageContentRequest $request): RedirectResponse
    {
        $content = HomepageContent::firstOrNew([]);
        $validated = $request->validated();

        // setTranslation() updates a single locale without wiping AI-generated translations.
        $content->setTranslation('tagline', 'fr', $validated['tagline']['fr']);
        $content->setTranslation('subtitle', 'fr', $validated['subtitle']['fr']);
        $content->setTranslation('bio', 'fr', $validated['bio']['fr']);
        $content->setTranslation('meta_description', 'fr', $validated['meta_description']['fr']);
        $content->setTranslation('skills', 'fr', $validated['skills']['fr']);
        $content->save();

        Log::info('Homepage content updated', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'saved',
        ]);

        return redirect()
            ->route('admin.homepage.edit', ['lang' => app()->getLocale()])
            ->with('success', __('admin.homepage_saved'));
    }
}
