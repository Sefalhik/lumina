<?php

namespace Database\Seeders;

use App\Models\Experience;
use Illuminate\Database\Seeder;

/**
 * Seeds one fictional position for the Playwright suite.
 *
 * The CV timeline markup — the period, the "still there" badge, the headings —
 * only exists when a record does. With an empty table the page renders its
 * empty state, the axe-core scan finds nothing to check, and the suite stays
 * green while the timeline's accessibility goes unverified. Exactly the trap
 * documented on E2eSiteIdentitySeeder.
 *
 * Deliberately open-ended (no end date) so the "current position" branch of the
 * view is the one under scan — it carries the extra badge markup.
 *
 * Values are fictional: no personal data belongs in a versioned seeder, and the
 * tests only care about shape. Specs must never delete this row.
 */
class E2eExperienceSeeder extends Seeder
{
    public function run(): void
    {
        $experience = new Experience([
            'employer' => 'E2E Test Employer',
            'job_title' => 'E2E Test Position',
            'location' => 'Testville',
            'started_at' => '2020-01-01',
            'ended_at' => null,
        ]);

        $experience->setTranslation('description', 'fr', 'Description de démonstration pour la suite E2E.');
        $experience->setTranslation('achievements', 'fr', 'Réalisation de démonstration pour la suite E2E.');
        $experience->save();
    }
}
