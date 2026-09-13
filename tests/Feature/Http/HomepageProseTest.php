<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\HomepageContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The homepage hero has one job: identity, hook and call to action, above the
 * fold. These tests pin the ordering that makes that true, because it is the
 * ordering — not the copy — that a future edit is most likely to break.
 */
class HomepageProseTest extends TestCase
{
    use RefreshDatabase;

    private function withBio(string $bio): void
    {
        $content = new HomepageContent;
        $content->setTranslation('tagline', 'fr', 'Tagline');
        $content->setTranslation('subtitle', 'fr', 'Subtitle');
        $content->setTranslation('bio', 'fr', $bio);
        $content->setTranslation('meta_description', 'fr', 'Meta');
        $content->setTranslation('skills', 'fr', '[]');
        $content->save();
    }

    public function test_the_hero_shows_only_the_first_paragraph(): void
    {
        $this->withBio("ACCROCHE unique.\n\nSUITE de la biographie.");

        $html = $this->get('/fr/')->assertOk()->getContent();
        $this->assertIsString($html);

        $lead = strpos($html, 'ACCROCHE unique.');
        $cta = strpos($html, __('home.cta_projects'));
        $body = strpos($html, 'SUITE de la biographie.');

        $this->assertNotFalse($lead);
        $this->assertNotFalse($cta);
        $this->assertNotFalse($body);

        // The assertion that matters: the buttons come before the long prose.
        // Asserting only that both strings are present would stay green with
        // the whole bio back in the hero, which is the bug this prevents.
        $this->assertLessThan($cta, $lead, 'The hook must come before the buttons.');
        $this->assertLessThan($body, $cta, 'The buttons must come before the biography.');
    }

    public function test_each_remaining_paragraph_is_its_own_element(): void
    {
        // Newlines collapse in HTML. Before this, a five-paragraph bio rendered
        // as one unbroken block of text.
        $this->withBio("Accroche.\n\nUn.\n\nDeux.\n\nTrois.");

        $html = $this->get('/fr/')->assertOk()->getContent();
        $this->assertIsString($html);

        foreach (['Un.', 'Deux.', 'Trois.'] as $paragraph) {
            $this->assertMatchesRegularExpression(
                '/<p[^>]*>\s*'.preg_quote($paragraph, '/').'\s*<\/p>/',
                $html,
                "Paragraph '{$paragraph}' is not its own element.",
            );
        }
    }

    public function test_a_one_paragraph_bio_renders_no_about_section(): void
    {
        $this->withBio('Une seule phrase, rien à déplier.');

        $this->get('/fr/')
            ->assertOk()
            ->assertSee('Une seule phrase, rien à déplier.')
            ->assertDontSee('about-heading');
    }

    public function test_the_about_section_is_labelled_for_assistive_technology(): void
    {
        $this->withBio("Accroche.\n\nBiographie.");

        $html = $this->get('/fr/')->assertOk()->getContent();
        $this->assertIsString($html);

        $this->assertStringContainsString('aria-labelledby="about-heading"', $html);
        $this->assertStringContainsString('id="about-heading"', $html);
    }

    public function test_prose_from_the_admin_is_escaped(): void
    {
        $this->withBio("Accroche.\n\n<script>alert(1)</script>");

        $this->get('/fr/')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_an_empty_database_falls_back_without_an_about_section(): void
    {
        $this->assertSame(0, HomepageContent::count());

        $this->get('/fr/')
            ->assertOk()
            ->assertSee(__('home.fallback_bio'))
            ->assertDontSee('about-heading');
    }
}
