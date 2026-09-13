<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExperienceRequest;
use App\Models\Experience;
use App\Services\ExperienceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ExperienceController extends Controller
{
    public function __construct(private readonly ExperienceService $experiences) {}

    public function index(): View
    {
        return view('admin.experiences.index', [
            'rows' => $this->experiences->adminRows(Experience::mostRecentFirst()->get()),
        ]);
    }

    public function create(): View
    {
        // An unsaved model, so the shared form binds to something either way.
        return view('admin.experiences.form', $this->formData(new Experience));
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
        return view('admin.experiences.form', $this->formData($experience));
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
     * The form edits one locale, and the view must not be the place that says
     * which: ExperienceService writes that locale back, so it is also the one
     * that names it. Hard-coding 'fr' in the template let the two drift apart
     * silently — the service would write the new source locale while the form
     * kept reading the old one.
     *
     * @return array{experience: Experience, sourceLocale: string}
     */
    private function formData(Experience $experience): array
    {
        return [
            'experience' => $experience,
            'sourceLocale' => ExperienceService::SOURCE_LOCALE,
        ];
    }

    /** Validate, hand the payload to the service, persist. */
    private function apply(Experience $experience, ExperienceRequest $request): void
    {
        $this->experiences->applySubmission($experience, $request->validated())->save();
    }

    private function backToIndex(string $messageKey): RedirectResponse
    {
        // No explicit 'lang': SetLocale sets it as a URL default for the whole
        // request, so passing it again only invites it to drift from the locale
        // actually in effect. Seven other call sites in app/ still repeat it —
        // out of this ticket's scope, worth a sweep of its own.
        return redirect()
            ->route('admin.experiences.index')
            ->with('success', __($messageKey));
    }
}
