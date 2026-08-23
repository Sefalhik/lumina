<?php

namespace Tests\Unit\Rules;

use App\Rules\ProfileUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ProfileUrlTest extends TestCase
{
    private function passes(mixed $value, ValidationRule $rule): bool
    {
        return Validator::make(['url' => $value], ['url' => [$rule]])->passes();
    }

    // ── GitHub ────────────────────────────────────────────────────────────────

    public function test_github_profile_passes(): void
    {
        $this->assertTrue($this->passes('https://github.com/Sefalhik', ProfileUrl::github()));
    }

    public function test_github_profile_with_trailing_slash_passes(): void
    {
        $this->assertTrue($this->passes('https://github.com/Sefalhik/', ProfileUrl::github()));
    }

    public function test_github_username_with_dashes_passes(): void
    {
        $this->assertTrue($this->passes(
            'https://github.com/Laurent-Bernard-Cardascia',
            ProfileUrl::github(),
        ));
    }

    public function test_github_home_page_is_not_a_profile(): void
    {
        // The case that motivated this rule: passes any hostname check, and
        // would declare GitHub's home page as someone's identity.
        $this->assertFalse($this->passes('https://github.com/', ProfileUrl::github()));
        $this->assertFalse($this->passes('https://github.com', ProfileUrl::github()));
    }

    public function test_github_deeper_path_is_not_a_profile(): void
    {
        $this->assertFalse($this->passes(
            'https://github.com/orgs/laravel/projects/1',
            ProfileUrl::github(),
        ));
        $this->assertFalse($this->passes('https://github.com/Sefalhik/lumina', ProfileUrl::github()));
    }

    public function test_github_rejects_another_network(): void
    {
        $this->assertFalse($this->passes(
            'https://www.linkedin.com/in/someone/',
            ProfileUrl::github(),
        ));
    }

    // ── LinkedIn ──────────────────────────────────────────────────────────────

    public function test_linkedin_profile_passes(): void
    {
        $this->assertTrue($this->passes(
            'https://www.linkedin.com/in/laurent-bernard-cardascia-290bb48b/',
            ProfileUrl::linkedin(),
        ));
    }

    public function test_linkedin_without_subdomain_passes(): void
    {
        $this->assertTrue($this->passes('https://linkedin.com/in/someone', ProfileUrl::linkedin()));
    }

    public function test_linkedin_feed_is_not_a_profile(): void
    {
        $this->assertFalse($this->passes('https://www.linkedin.com/feed/', ProfileUrl::linkedin()));
    }

    public function test_linkedin_company_page_is_not_a_profile(): void
    {
        $this->assertFalse($this->passes(
            'https://www.linkedin.com/company/example/',
            ProfileUrl::linkedin(),
        ));
    }

    // ── Mastodon ──────────────────────────────────────────────────────────────

    public function test_mastodon_profile_passes(): void
    {
        $this->assertTrue($this->passes(
            'https://mastodon.social/@Sefalhik',
            ProfileUrl::mastodon(),
        ));
    }

    public function test_any_mastodon_instance_passes(): void
    {
        // Federation: no host is pinned, only the profile shape.
        foreach (['piaille.fr', 'fosstodon.org', 'social.self-hosted.example'] as $instance) {
            $this->assertTrue(
                $this->passes("https://{$instance}/@someone", ProfileUrl::mastodon()),
                "expected {$instance} to be accepted",
            );
        }
    }

    public function test_mastodon_without_at_sign_fails(): void
    {
        $this->assertFalse($this->passes('https://mastodon.social/Sefalhik', ProfileUrl::mastodon()));
    }

    public function test_mastodon_instance_root_fails(): void
    {
        $this->assertFalse($this->passes('https://mastodon.social', ProfileUrl::mastodon()));
    }

    public function test_mastodon_status_url_is_not_a_profile(): void
    {
        $this->assertFalse($this->passes(
            'https://mastodon.social/@Sefalhik/123456789',
            ProfileUrl::mastodon(),
        ));
    }

    // ── Shared behaviour ──────────────────────────────────────────────────────

    public function test_host_check_is_case_insensitive(): void
    {
        $this->assertTrue($this->passes('https://GitHub.com/Sefalhik', ProfileUrl::github()));
    }

    public function test_lookalike_host_fails(): void
    {
        $this->assertFalse($this->passes(
            'https://github.com.evil.example/Sefalhik',
            ProfileUrl::github(),
        ));
    }

    public function test_host_as_path_segment_fails(): void
    {
        $this->assertFalse($this->passes(
            'https://example.com/github.com/Sefalhik',
            ProfileUrl::github(),
        ));
    }

    public function test_blank_value_passes(): void
    {
        // Emptiness belongs to `nullable`, not to this rule.
        $this->assertTrue($this->passes('', ProfileUrl::github()));
        $this->assertTrue($this->passes('   ', ProfileUrl::github()));
        $this->assertTrue($this->passes(null, ProfileUrl::github()));
    }

    public function test_value_without_host_fails(): void
    {
        $this->assertFalse($this->passes('not-a-url', ProfileUrl::github()));
    }
}
