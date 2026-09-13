<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Experience;
use App\Services\CvService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CvServiceTest extends TestCase
{
    private CvService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CvService;
    }

    /** @param array<string, mixed> $attributes */
    private function experience(array $attributes = []): Experience
    {
        return new Experience(array_merge([
            'employer' => 'Elosi',
            'job_title' => 'Tech Lead',
            'started_at' => '2024-04-01',
        ], $attributes));
    }

    /** @param list<Experience> $experiences */
    private function collect(array $experiences): Collection
    {
        /** @var Collection<int, Experience> */
        return new Collection($experiences);
    }

    public function test_empty_collection_yields_an_empty_timeline(): void
    {
        $this->assertSame([], $this->service->timeline($this->collect([])));
    }

    public function test_timeline_is_sorted_most_recent_first(): void
    {
        // Handed over unsorted on purpose: the page must not depend on how the
        // records happened to be fetched.
        $timeline = $this->service->timeline($this->collect([
            $this->experience(['employer' => 'Ancien', 'started_at' => '2015-01-01', 'ended_at' => '2019-01-01']),
            $this->experience(['employer' => 'Récent', 'started_at' => '2024-04-01']),
            $this->experience(['employer' => 'Milieu', 'started_at' => '2019-02-01', 'ended_at' => '2024-03-01']),
        ]));

        $this->assertSame(['Récent', 'Milieu', 'Ancien'], array_column($timeline, 'employer'));
    }

    public function test_a_position_without_an_end_date_is_current(): void
    {
        $timeline = $this->service->timeline($this->collect([
            $this->experience(['ended_at' => null]),
        ]));

        $this->assertTrue($timeline[0]['is_current']);
    }

    public function test_a_position_with_an_end_date_is_not_current(): void
    {
        $timeline = $this->service->timeline($this->collect([
            $this->experience(['ended_at' => '2026-01-31']),
        ]));

        $this->assertFalse($timeline[0]['is_current']);
    }

    public function test_current_period_ends_with_the_present_label(): void
    {
        $period = $this->service->period($this->experience(['started_at' => '2024-04-01']));

        $this->assertSame('04/2024 — '.__('cv.period_present'), $period);
    }

    public function test_past_period_shows_both_bounds(): void
    {
        $period = $this->service->period($this->experience([
            'started_at' => '2019-02-01',
            'ended_at' => '2024-03-31',
        ]));

        $this->assertSame('02/2019 — 03/2024', $period);
    }

    public function test_blank_prose_is_flattened_to_null(): void
    {
        // A blank string would render as empty markup; the view skips null.
        $experience = $this->experience(['location' => '   ']);
        $experience->setTranslation('description', 'fr', '');

        $timeline = $this->service->timeline($this->collect([$experience]));

        $this->assertNull($timeline[0]['location']);
        $this->assertNull($timeline[0]['description']);
    }

    public function test_prose_is_trimmed(): void
    {
        $experience = $this->experience();
        $experience->setTranslation('description', 'fr', '  Direction technique.  ');

        $timeline = $this->service->timeline($this->collect([$experience]));

        $this->assertSame('Direction technique.', $timeline[0]['description']);
    }
}
