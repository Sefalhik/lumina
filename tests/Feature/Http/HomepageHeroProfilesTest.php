<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\SiteIdentity;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The profile buttons the homepage hero shows while its calls to action are
 * withdrawn (LUMN-104).
 *
 * Which networks, in which order, with which blanks skipped: that is
 * SiteIdentityService::heroLinks(), unit-tested. This proves the wiring and the
 * markup — rel, target, accessible name — and that the zone disappears.
 *
 * Every assertion is scoped to #hero-profiles: the footer renders the same URLs,
 * so a page-wide assertSee would stay green with the hero empty.
 */
class HomepageHeroProfilesTest extends TestCase
{
    use RefreshDatabase;

    private const LINKEDIN = 'https://www.linkedin.com/in/someone/';

    private const MASTODON = 'https://mastodon.social/@Sefalhik';

    /** @param array<string, string|null> $attributes */
    private function identity(array $attributes): void
    {
        $identity = SiteIdentity::firstOrNew([]);
        $identity->fill($attributes);
        $identity->save();
    }

    /** @return list<Element> */
    private function heroLinks(string $path): array
    {
        $html = $this->get($path)->assertOk()->getContent();
        $this->assertIsString($html);

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        return iterator_to_array($document->querySelectorAll('#hero-profiles a'), false);
    }

    private function heroZoneExists(string $path): bool
    {
        $html = $this->get($path)->assertOk()->getContent();
        $this->assertIsString($html);

        return HTMLDocument::createFromString($html, LIBXML_NOERROR)
            ->querySelector('#hero-profiles') !== null;
    }

    public function test_the_hero_offers_linkedin_then_mastodon(): void
    {
        $this->identity([
            'github_url' => 'https://github.com/Sefalhik',
            'linkedin_url' => self::LINKEDIN,
            'mastodon_url' => self::MASTODON,
        ]);

        $links = $this->heroLinks('/fr/');

        $this->assertSame(
            [self::LINKEDIN, self::MASTODON],
            array_map(fn (Element $link): ?string => $link->getAttribute('href'), $links),
        );
        $this->assertSame(
            ['LinkedIn', 'Mastodon'],
            array_map(fn (Element $link): string => trim((string) $link->textContent), $links),
        );
    }

    public function test_both_profiles_declare_themselves_as_me_and_open_a_new_window(): void
    {
        $this->identity(['linkedin_url' => self::LINKEDIN, 'mastodon_url' => self::MASTODON]);

        foreach ($this->heroLinks('/fr/') as $link) {
            // rel="me" is what lets Mastodon verify the site (LUMN-13); the
            // footer carries the same, and so must LinkedIn (decision 2026-11-12).
            $this->assertSame('me noopener noreferrer', $link->getAttribute('rel'));
            $this->assertSame('_blank', $link->getAttribute('target'));
        }
    }

    public function test_the_accessible_name_is_translated_and_announces_the_new_window(): void
    {
        $this->identity(['linkedin_url' => self::LINKEDIN]);

        [$fr] = $this->heroLinks('/fr/');
        [$de] = $this->heroLinks('/de/');

        $this->assertSame('Profil LinkedIn (nouvelle fenêtre)', $fr->getAttribute('aria-label'));
        $this->assertSame(
            trans('footer.social_aria', ['network' => 'LinkedIn'], 'de'),
            $de->getAttribute('aria-label'),
        );
        $this->assertNotSame($fr->getAttribute('aria-label'), $de->getAttribute('aria-label'));
    }

    public function test_an_empty_profile_has_no_button(): void
    {
        $this->identity(['mastodon_url' => self::MASTODON]);

        $links = $this->heroLinks('/fr/');

        $this->assertCount(1, $links);
        $this->assertSame(self::MASTODON, $links[0]->getAttribute('href'));
    }

    public function test_the_zone_disappears_without_any_identity(): void
    {
        // The state of a fresh install, right after migrating.
        $this->assertSame(0, SiteIdentity::count());

        $this->assertFalse($this->heroZoneExists('/fr/'));
    }

    public function test_the_zone_disappears_when_only_github_is_filled(): void
    {
        // GitHub stays in the footer: an identity row with GitHub alone leaves
        // the hero nothing to show, and no empty container behind.
        $this->identity(['github_url' => 'https://github.com/Sefalhik']);

        $this->assertFalse($this->heroZoneExists('/fr/'));
    }

    public function test_the_first_profile_is_the_primary_button_and_the_others_are_secondary(): void
    {
        $this->identity(['linkedin_url' => self::LINKEDIN, 'mastodon_url' => self::MASTODON]);

        [$first, $second] = $this->heroLinks('/fr/');

        $this->assertTrue($first->classList->contains('btn-primary'));
        $this->assertFalse($first->classList->contains('btn-outline'));
        $this->assertTrue($second->classList->contains('btn-outline'));
        $this->assertFalse($second->classList->contains('btn-primary'));
    }

    public function test_the_primary_button_follows_the_first_profile_shown(): void
    {
        // LinkedIn empty: Mastodon is then the only, and so the first, button.
        $this->identity(['mastodon_url' => self::MASTODON]);

        [$only] = $this->heroLinks('/fr/');

        $this->assertTrue($only->classList->contains('btn-primary'));
    }

    public function test_a_stored_url_that_is_not_https_never_reaches_the_hero(): void
    {
        // The service refuses it (unit-tested case by case); this proves the
        // hero renders what the service returns, not the raw column.
        $this->identity(['linkedin_url' => 'javascript:alert(1)', 'mastodon_url' => self::MASTODON]);

        $links = $this->heroLinks('/fr/');

        $this->assertCount(1, $links);
        $this->assertSame(self::MASTODON, $links[0]->getAttribute('href'));
        $this->assertStringNotContainsString('javascript:', (string) $this->get('/fr/')->getContent());
    }

    public function test_a_stored_url_is_escaped_in_the_attribute(): void
    {
        // The admin form only accepts profile URLs, but the column accepts
        // anything: the view must not trust what the database holds.
        $this->identity(['mastodon_url' => 'https://mastodon.social/@x" onmouseover="alert(1)']);

        $links = $this->heroLinks('/fr/');

        $this->assertCount(1, $links);
        $this->assertFalse($links[0]->hasAttribute('onmouseover'));
    }
}
