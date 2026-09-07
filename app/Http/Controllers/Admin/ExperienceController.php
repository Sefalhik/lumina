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

        // array_key_exists rather than ?? '': the two cases differ. An emptied
        // textarea reaches validated() as a present null — clearing it is what
        // the user asked for. A payload that omits the field entirely is not a
        // request to erase anything, and treating it as one would wipe the
        // French prose while leaving the machine translations of the other
        // locales in place, which is a state no screen can produce or repair.
        foreach (['description', 'achievements'] as $field) {
            if (array_key_exists($field, $validated)) {
                $experience->setTranslation($field, 'fr', (string) $validated[$field]);
            }
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
