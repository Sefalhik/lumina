<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExperienceRequest;
use App\Models\Experience;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ExperienceController extends Controller
{
    public function index(): View
    {
        return view('admin.experiences.index', [
            'experiences' => Experience::mostRecentFirst()->get(),
        ]);
    }

    public function create(): View
    {
        // An unsaved model, so the shared form binds to something either way.
        return view('admin.experiences.form', [
            'experience' => new Experience,
        ]);
    }

    public function store(ExperienceRequest $request): RedirectResponse
    {
        $experience = new Experience;
        $this->apply($experience, $request);

        Log::info('Experience created', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'saved',
        ]);

        return $this->backToIndex('admin.experience_created');
    }

    public function edit(Experience $experience): View
    {
        return view('admin.experiences.form', [
            'experience' => $experience,
        ]);
    }

    public function update(ExperienceRequest $request, Experience $experience): RedirectResponse
    {
        $this->apply($experience, $request);

        Log::info('Experience updated', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'saved',
        ]);

        return $this->backToIndex('admin.experience_updated');
    }

    public function destroy(Experience $experience): RedirectResponse
    {
        $experience->delete();

        Log::info('Experience deleted', [
            'service' => self::class,
            'method' => __FUNCTION__,
            'step' => 'deleted',
        ]);

        return $this->backToIndex('admin.experience_deleted');
    }

    /**
     * Writes the submitted values onto the model.
     *
     * The two prose fields go through setTranslation() on the French value only:
     * the form edits the source language, and machine translations of the other
     * locales must survive an edit. Everything else is a plain column — see the
     * model for why job_title is one of them.
     */
    private function apply(Experience $experience, ExperienceRequest $request): void
    {
        $validated = $request->validated();

        $experience->fill([
            'employer' => $validated['employer'],
            'job_title' => $validated['job_title'],
            'location' => $validated['location'] ?? null,
            'started_at' => $validated['started_at'],
            'ended_at' => $validated['ended_at'] ?? null,
        ]);

        foreach (['description', 'achievements'] as $field) {
            $experience->setTranslation($field, 'fr', (string) ($validated[$field] ?? ''));
        }

        $experience->save();
    }

    private function backToIndex(string $messageKey): RedirectResponse
    {
        return redirect()
            ->route('admin.experiences.index', ['lang' => app()->getLocale()])
            ->with('success', __($messageKey));
    }
}
