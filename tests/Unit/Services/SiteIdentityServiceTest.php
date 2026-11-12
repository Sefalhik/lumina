<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SiteIdentity;
use App\Services\SiteIdentityService;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ─── Only https reaches an href ──────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function refusedUrls(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'javascript scheme, mixed case and padded' => ['  JavaScript:alert(1)  '],
            'data scheme' => ['data:text/html,<script>alert(1)</script>'],
            'plain http' => ['http://github.com/Sefalhik'],
            'another scheme' => ['ftp://github.com/Sefalhik'],
            'no scheme' => ['github.com/Sefalhik'],
            'protocol-relative' => ['//github.com/Sefalhik'],
            'relative path' => ['/fr/cv'],
            // parse_url() returns false for the next three: refused before any host check.
            'https without a host' => ['https://'],
            'https with an empty authority' => ['https:///Sefalhik'],
            'unparseable' => ['https://:80'],
            // Parsed, scheme https, but no host at all: only the host check refuses it.
            'https scheme without slashes' => ['https:github.com/Sefalhik'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function test_a_url_that_is_not_absolute_https_is_never_returned(string $url): void
    {
        Log::spy();

        $links = $this->service->socialLinks($this->identity([
            'github_url' => $url,
            'mastodon_url' => 'https://mastodon.social/@Sefalhik',
        ]));

        // The refused link alone disappears: its neighbours still render.
        $this->assertSame(['mastodon'], array_column($links, 'key'));
        $this->assertSame([], $this->service->heroLinks($this->identity(['linkedin_url' => $url])));
    }

    public function test_a_refused_url_is_logged_without_its_value(): void
    {
        $log = Log::spy();

        $this->service->socialLinks($this->identity(['linkedin_url' => 'javascript:alert(1)']));

        $log->shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Site identity profile URL is not https — not rendered'
                && $context === [
                    'service' => SiteIdentityService::class,
                    'method' => 'socialLinks',
                    'step' => 'reject_url',
                    'network' => 'linkedin',
                ],
        );
    }

    public function test_an_uppercase_https_scheme_is_still_https(): void
    {
        $links = $this->service->socialLinks($this->identity(['github_url' => 'HTTPS://github.com/Sefalhik']));

        $this->assertSame('HTTPS://github.com/Sefalhik', $links[0]['url']);
    }

    public function test_a_padded_https_url_is_accepted_once_trimmed(): void
    {
        // The scheme check runs on the trimmed value: padding is not a reason
        // to refuse a URL the footer already accepted.
        $links = $this->service->socialLinks($this->identity(['github_url' => "\t https://github.com/Sefalhik \n"]));

        $this->assertSame('https://github.com/Sefalhik', $links[0]['url']);
    }

    // ─── Hero links (LUMN-104) ───────────────────────────────────────────────

    public function test_hero_offers_linkedin_then_mastodon(): void
    {
        $links = $this->service->heroLinks($this->identity([
            'mastodon_url' => 'https://mastodon.social/@Sefalhik',
            'linkedin_url' => 'https://www.linkedin.com/in/someone/',
            'github_url' => 'https://github.com/Sefalhik',
        ]));

        // The whole value, not array_column(): array_column() reindexes, and
        // would hide the gap GitHub leaves at index 0 once filtered out.
        $this->assertSame([
            ['key' => 'linkedin', 'label' => 'LinkedIn', 'url' => 'https://www.linkedin.com/in/someone/'],
            ['key' => 'mastodon', 'label' => 'Mastodon', 'url' => 'https://mastodon.social/@Sefalhik'],
        ], $links);
    }

    public function test_hero_never_offers_github(): void
    {
        $links = $this->service->heroLinks($this->identity([
            'github_url' => 'https://github.com/Sefalhik',
        ]));

        $this->assertSame([], $links);
    }

    public function test_hero_skips_an_empty_linkedin(): void
    {
        $links = $this->service->heroLinks($this->identity([
            'linkedin_url' => '  ',
            'mastodon_url' => 'https://mastodon.social/@Sefalhik',
        ]));

        $this->assertSame(['mastodon'], array_column($links, 'key'));
    }

    public function test_hero_skips_an_empty_mastodon(): void
    {
        $links = $this->service->heroLinks($this->identity([
            'linkedin_url' => 'https://www.linkedin.com/in/someone/',
            'mastodon_url' => null,
        ]));

        $this->assertSame(['linkedin'], array_column($links, 'key'));
    }

    public function test_hero_is_empty_without_any_identity(): void
    {
        $this->assertSame([], $this->service->heroLinks(null));
        $this->assertSame([], $this->service->heroLinks($this->identity()));
    }

    public function test_hero_urls_are_trimmed_like_the_footer(): void
    {
        $links = $this->service->heroLinks($this->identity([
            'linkedin_url' => '  https://www.linkedin.com/in/someone/  ',
        ]));

        $this->assertSame('https://www.linkedin.com/in/someone/', $links[0]['url']);
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

    public function test_job_title_is_returned_as_is(): void
    {
        $this->assertSame(
            'Lead Developer',
            $this->service->jobTitle($this->identity(['job_title' => 'Lead Developer'])),
        );
    }

    public function test_blank_job_title_is_null(): void
    {
        $this->assertNull($this->service->jobTitle($this->identity(['job_title' => ''])));
    }
}
