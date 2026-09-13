<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\HomepageContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public homepage's contract: every CMS field reaches the page, in the
 * requested locale, escaped, and with a usable page when the table is empty.
 *
 * HomepageProseTest covers how the bio is split. This covers everything else
 * the controller hands the view.
 */
class HomepagePublicTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, array<string, string>> $fields */
    private function content(array $fields): HomepageContent
    {
        $content = new HomepageContent;

        foreach ($content->translatable as $field) {
            $content->setTranslations($field, $fields[$field] ?? ['fr' => "Valeur de {$field}"]);
        }

        $content->save();

        return $content;
    }

    public function test_every_translated_field_reaches_the_page(): void
    {
        $this->content([
            'tagline' => ['fr' => 'ACCROCHE-CMS'],
            'subtitle' => ['fr' => 'SOUS-TITRE-CMS'],
            'bio' => ['fr' => 'BIO-CMS'],
            'meta_description' => ['fr' => 'META-CMS'],
            'skills' => ['fr' => '[{"icon":"⬡","name":"CATEGORIE-CMS","techs":["TECHNO-CMS"]}]'],
        ]);

        $response = $this->get('/fr/')->assertOk();

        foreach (['ACCROCHE-CMS', 'SOUS-TITRE-CMS', 'BIO-CMS', 'CATEGORIE-CMS', 'TECHNO-CMS'] as $needle) {
            $response->assertSee($needle);
        }

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('<meta name="description" content="META-CMS">', $html);

        // The other half of the fallback rule: with a row present, the neutral
        // placeholders must be nowhere on the page. Asserting only that the CMS
        // values appear would stay green if both rendered side by side.
        $response->assertDontSee(__('home.fallback_tagline'));
        $response->assertDontSee(__('home.fallback_subtitle'));
        $response->assertDontSee(__('home.fallback_bio'));
    }

    public function test_the_page_serves_the_requested_locale_not_the_default(): void
    {
        // The point of shipping 24 locales is that a visitor gets theirs. The
        // seeder test proves the data carries them; this proves the page does.
        $this->content([
            'tagline' => ['fr' => 'ACCROCHE-FR', 'de' => 'ACCROCHE-DE'],
            'subtitle' => ['fr' => 'SOUS-TITRE-FR', 'de' => 'SOUS-TITRE-DE'],
            'bio' => ['fr' => 'BIO-FR', 'de' => 'BIO-DE'],
            'meta_description' => ['fr' => 'META-FR', 'de' => 'META-DE'],
        ]);

        $this->get('/de/')
            ->assertOk()
            ->assertSee('ACCROCHE-DE')
            ->assertSee('SOUS-TITRE-DE')
            ->assertSee('BIO-DE')
            ->assertDontSee('ACCROCHE-FR')
            ->assertDontSee('BIO-FR');
    }

    public function test_a_locale_without_a_translation_falls_back_to_french(): void
    {
        // Documents spatie's behaviour rather than wishing it away: a missing
        // locale serves French, never a blank page. That is why the seed file
        // has to carry all 24 — nothing here would go red if it did not.
        $this->content(['tagline' => ['fr' => 'ACCROCHE-FR-SEULE']]);

        $this->get('/sv/')->assertOk()->assertSee('ACCROCHE-FR-SEULE');
    }

    public function test_admin_supplied_tagline_and_subtitle_are_escaped(): void
    {
        $this->content([
            'tagline' => ['fr' => '<script>alert(1)</script>'],
            'subtitle' => ['fr' => '<script>alert(2)</script>'],
        ]);

        $this->get('/fr/')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<script>alert(2)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_admin_supplied_skills_are_escaped(): void
    {
        // Skills arrive as JSON decoded into an array, so each leaf is a
        // separate output site — the category name and every tech label.
        $this->content([
            'skills' => ['fr' => json_encode([[
                'icon' => '<script>alert(3)</script>',
                'name' => '<script>alert(4)</script>',
                'techs' => ['<script>alert(5)</script>'],
            ]], JSON_UNESCAPED_UNICODE) ?: '[]'],
        ]);

        $response = $this->get('/fr/')->assertOk();

        foreach ([3, 4, 5] as $n) {
            $response->assertDontSee("<script>alert({$n})</script>", false);
        }

        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_a_quote_in_the_meta_description_cannot_break_the_attribute(): void
    {
        // @yield does not escape the way {{ }} does; the protection comes from
        // Blade applying e() to an inline section value. Pinned because the
        // difference is invisible in the template.
        $this->content(['meta_description' => ['fr' => 'Fin"><script>alert(6)</script>']]);

        $html = $this->get('/fr/')->assertOk()->getContent();
        $this->assertIsString($html);

        $head = substr($html, 0, (int) strpos($html, '</head>'));

        $this->assertStringNotContainsString('<script>alert(6)</script>', $head);
        $this->assertStringContainsString('&quot;', $head);
    }

    public function test_an_empty_table_renders_the_neutral_fallbacks(): void
    {
        $this->assertSame(0, HomepageContent::count());

        $this->get('/fr/')
            ->assertOk()
            ->assertSee(__('home.fallback_tagline'))
            ->assertSee(__('home.fallback_subtitle'))
            ->assertSee(__('home.fallback_bio'));
    }

    public function test_an_empty_table_still_renders_a_skills_grid(): void
    {
        // The fallback grid is the only thing standing between an empty table
        // and a page with a heading over nothing.
        /** @var array<int, array<string, string>> $fallback */
        $fallback = (array) __('home.fallback_skills');
        $this->assertNotEmpty($fallback);

        $this->get('/fr/')
            ->assertOk()
            ->assertSee($fallback[0]['tech'])
            ->assertSee($fallback[0]['category']);
    }
}
