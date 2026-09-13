<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Experience;
use Illuminate\Support\Collection;

/**
 * Turns Experience records into rows the CV view can render.
 *
 * Takes a collection rather than querying: the controller owns the query, this
 * owns the shaping, and the whole thing stays unit-testable without a database
 * — the same split as SiteIdentityService.
 */
class CvService
{
    /**
     * Numeric on purpose: month names would have to be translated for
     * twenty-four locales to say something a reader already understands.
     */
    private const PERIOD_FORMAT = 'm/Y';

    /**
     * The professional timeline, most recent first.
     *
     * Sorting happens here as well as in Experience::scopeMostRecentFirst() so
     * that a caller handing over an unsorted collection still gets a correct
     * page — the view must never depend on how the records were fetched.
     *
     * @param  Collection<int, Experience>  $experiences
     * @return list<array{employer: string, job_title: string, location: string|null, period: string, is_current: bool, description: string|null, achievements: string|null}>
     */
    public function timeline(Collection $experiences): array
    {
        // Sorted on started_at only, and that is enough: PHP 8 sorts are stable,
        // so positions starting the same month keep the order the query gave
        // them — which Experience::scopeMostRecentFirst() settles with an id
        // tie-breaker. Two places share the ordering knowledge, deliberately:
        // this sort makes the page correct whatever the caller hands over, the
        // scope makes it deterministic. Replacing this with an unstable sort
        // would silently lose the tie-breaker.
        $rows = $experiences
            ->sortByDesc(fn (Experience $experience): string => $experience->started_at->format('Y-m-d'))
            ->map(fn (Experience $experience): array => [
                'employer' => $experience->employer,
                'job_title' => $experience->job_title,
                'location' => $this->cleaned($experience->location),
                'period' => $this->period($experience),
                'is_current' => $experience->isCurrent(),
                'description' => $this->cleaned($experience->getAttribute('description')),
                'achievements' => $this->cleaned($experience->getAttribute('achievements')),
            ])
            ->all();

        // array_values() rather than Collection::values(): the declared return
        // type is a list, and only re-indexing here proves it to the analyser.
        return array_values($rows);
    }

    /**
     * "04/2024 — 09/2026", or "04/2024 — aujourd'hui" while the position is held.
     */
    public function period(Experience $experience): string
    {
        $start = $experience->started_at->format(self::PERIOD_FORMAT);

        $end = $experience->isCurrent()
            ? __('cv.period_present')
            : $experience->ended_at?->format(self::PERIOD_FORMAT);

        return $start.' — '.(is_string($end) ? $end : '');
    }

    /**
     * Blank strings render as empty markup rather than as nothing, so they are
     * flattened to null here and the view skips them.
     */
    private function cleaned(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
