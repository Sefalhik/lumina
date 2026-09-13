<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * One position in the professional timeline shown on the CV page.
 *
 * Partially translated on purpose: only the prose is. Employer, location and
 * dates are data, and job_title is the value a sameAs statement cross-references
 * with the LinkedIn profile — translating it would make the cross-reference hold
 * in `fr` alone, which is the mistake LUMN-15 corrected on SiteIdentity.
 *
 * @property string $employer
 * @property string $job_title
 * @property string|null $location
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 */
class Experience extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['description', 'achievements'];

    /** @var list<string> */
    protected $fillable = [
        'employer',
        'job_title',
        'location',
        'started_at',
        'ended_at',
        'description',
        'achievements',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'date',
            'ended_at' => 'date',
        ];
    }

    /**
     * Whether this position is still held.
     *
     * A null ended_at is a business state — "still there" — not missing data.
     * Naming it here is the whole point: the meaning lives in the code rather
     * than in whoever wrote the migration.
     */
    public function isCurrent(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Positions still held. The query-side counterpart of isCurrent().
     *
     * No application code calls this yet — only the test that pins its meaning.
     * Kept deliberately: a predicate and its query form belong together, and
     * whoever needs "the current position" should find it here rather than
     * writing whereNull('ended_at') again somewhere else. If it is still
     * unused when a second reader wonders about it, delete it.
     *
     * @param  Builder<Experience>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    /**
     * Most recent first — a career reads backwards.
     *
     * Computed rather than stored: an explicit position column would be one more
     * thing to keep consistent for an order that dates already define.
     *
     * The id tie-breaker is load-bearing even though CvService sorts again.
     * PHP 8 sorts are stable, so the service's sortByDesc on started_at alone
     * preserves the order this query returned — meaning two positions starting
     * the same month are separated here and nowhere else. Removing
     * orderByDesc('id') fails a test, which is the only thing that states this
     * coupling out loud.
     *
     * @param  Builder<Experience>  $query
     */
    public function scopeMostRecentFirst(Builder $query): void
    {
        $query->orderByDesc('started_at')->orderByDesc('id');
    }
}
