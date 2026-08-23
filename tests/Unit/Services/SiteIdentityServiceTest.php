<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SiteIdentity;
use App\Services\SiteIdentityService;
use Tests\TestCase;

class SiteIdentityServiceTest extends TestCase
{
    private SiteIdentityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SiteIdentityService;
    }

    private function identity(array $attributes = []): SiteIdentity
    {
        return new SiteIdentity($attributes);
    }

    // ─── No identity at all ──────────────────────────────────────────────────

    public function test_null_identity_yields_nothing(): void
    {
        // The table is empty until the admin form is filled, so this is the
        // initial state of a fresh install — not an edge case.
        $this->assertSame([], $this->service->socialLinks(null));
        $this->assertNull($this->service->contactEmail(null));
        $this->assertNull($this->service->displayName(null));
        $this->assertNull($this->service->jobTitle(null));
    }

    public function test_empty_identity_yields_nothing(): void
    {
        $identity = $this->identity();

        $this->assertSame([], $this->service->socialLinks($identity));
        $this->assertNull($this->service->contactEmail($identity));
        $this->assertNull($this->service->displayName($identity));
    }

    // ─── Social links ────────────────────────────────────────────────────────

    public function test_only_filled_links_are_returned(): void
    {
        $links = $this->service->socialLinks($this->identity([
            'github_url' => 'https://github.com/Sefalhik',
            'linkedin_url' => null,
            'mastodon_url' => 'https://mastodon.social/@Sefalhik',
        ]));

        $this->assertCount(2, $links);
        $this->assertSame(['github', 'mastodon'], array_column($links, 'key'));
    }

    public function test_links_keep_a_stable_order(): void
    {
        $links = $this->service->socialLinks($this->identity([
            'mastodon_url' => 'https://mastodon.social/@Sefalhik',
            'linkedin_url' => 'https://www.linkedin.com/in/someone/',
            'github_url' => 'https://github.com/Sefalhik',
        ]));

        // Declaration order wins over attribute order: most professionally
        // relevant first, regardless of how the record was built.
        $this->assertSame(['github', 'linkedin', 'mastodon'], array_column($links, 'key'));
    }

    public function test_labels_are_proper_nouns(): void
    {
        $links = $this->service->socialLinks($this->identity([
            'github_url' => 'https://github.com/Sefalhik',
            'linkedin_url' => 'https://www.linkedin.com/in/someone/',
            'mastodon_url' => 'https://mastodon.social/@Sefalhik',
        ]));

        $this->assertSame(['GitHub', 'LinkedIn', 'Mastodon'], array_column($links, 'label'));
    }

    public function test_whitespace_only_link_is_treated_as_empty(): void
    {
        $links = $this->service->socialLinks($this->identity(['github_url' => '   ']));

        $this->assertSame([], $links);
    }

    public function test_urls_are_trimmed(): void
    {
        $links = $this->service->socialLinks($this->identity([
            'github_url' => '  https://github.com/Sefalhik  ',
        ]));

        $this->assertSame('https://github.com/Sefalhik', $links[0]['url']);
    }

    // ─── Scalar fields ───────────────────────────────────────────────────────

    public function test_contact_email_is_returned_and_trimmed(): void
    {
        $identity = $this->identity(['contact_email' => ' contact@cardascia-it.org ']);

        $this->assertSame('contact@cardascia-it.org', $this->service->contactEmail($identity));
    }

    public function test_blank_contact_email_is_null(): void
    {
        $this->assertNull($this->service->contactEmail($this->identity(['contact_email' => '  '])));
    }

    public function test_display_name_is_returned_and_trimmed(): void
    {
        $identity = $this->identity(['full_name' => ' Laurent Bernard-Cardascia ']);

        $this->assertSame('Laurent Bernard-Cardascia', $this->service->displayName($identity));
    }

    public function test_blank_display_name_is_null(): void
    {
        $this->assertNull($this->service->displayName($this->identity(['full_name' => ''])));
    }

    public function test_job_title_resolves_the_active_locale(): void
    {
        $identity = $this->identity();
        $identity->setTranslation('job_title', 'fr', 'Lead Developer');

        $this->assertSame('Lead Developer', $this->service->jobTitle($identity));
    }

    public function test_blank_job_title_is_null(): void
    {
        $identity = $this->identity();
        $identity->setTranslation('job_title', 'fr', '');

        $this->assertNull($this->service->jobTitle($identity));
    }
}
