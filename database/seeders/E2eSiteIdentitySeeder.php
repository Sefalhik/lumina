<?php

namespace Database\Seeders;

use App\Models\SiteIdentity;
use Illuminate\Database\Seeder;

/**
 * Seeds a fictional site identity for the Playwright suite.
 *
 * Without it the E2E database is empty, the footer renders no links, and the
 * axe-core scan silently never sees them — the accessibility of those links
 * would go unverified while the suite stayed green.
 *
 * Values are deliberately fictional: no personal data belongs in a versioned
 * seeder, and the tests only care about shape.
 */
class E2eSiteIdentitySeeder extends Seeder
{
    public function run(): void
    {
        $identity = SiteIdentity::firstOrNew([]);

        $identity->fill([
            'full_name' => 'E2E Test Identity',
            'job_title' => 'Lead Developer',
            'contact_email' => 'contact@example.test',
            'github_url' => 'https://github.com/example',
            'linkedin_url' => 'https://www.linkedin.com/in/example/',
            'mastodon_url' => 'https://mastodon.social/@example',
        ]);

        $identity->save();
    }
}
