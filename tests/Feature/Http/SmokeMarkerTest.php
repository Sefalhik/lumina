<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\HomepageContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The smoke test proves the translations shipped by comparing the biography across locales, and it
 * finds the biography by its data-smoke marker. Removing the marker from the template would make that
 * probe fail on every deployment — this test fails first, here.
 */
class SmokeMarkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_homepage_biography_carries_its_smoke_marker(): void
    {
        $content = new HomepageContent;
        foreach (['tagline' => 'T', 'subtitle' => 'S', 'meta_description' => 'M', 'skills' => '[]'] as $field => $value) {
            $content->setTranslation($field, 'fr', $value);
        }
        $content->setTranslation('bio', 'fr', "ACCROCHE.\n\nSuite.");
        $content->save();

        $html = (string) $this->get('/fr')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<p\b[^>]*data-smoke="bio"[^>]*>\s*ACCROCHE\.\s*<\/p>/', $html);
    }
}
