<?php

declare(strict_types=1);

namespace Tests\Feature\Translation;

use App\Models\HomepageContent;
use App\Services\Translation\CmsTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTemporaryDirectory;
use Tests\TestCase;

class CmsTranslationTest extends TestCase
{
    use RefreshDatabase;
    use UsesTemporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        config(['i18n.cache_path' => $this->temporaryDirectory()]);
    }

    public function test_the_plan_holds_a_task_only_where_a_locale_has_something_to_translate(): void
    {
        $content = HomepageContent::create([
            'tagline' => ['fr' => 'Accroche', 'de' => 'Slogan'],
            'subtitle' => ['fr' => 'Sous-titre', 'de' => 'Untertitel'],
            'bio' => ['fr' => 'Bio', 'de' => 'Biografie'],
        ]);

        $plan = $this->app->make(CmsTranslation::class)->plan([HomepageContent::class], ['de', 'en'], false);

        $this->assertCount(1, $plan->sources);
        $this->assertSame('cms_HomepageContent_'.$content->id, $plan->sources[0]->id);
        $this->assertCount(1, $plan->tasks);
        $this->assertSame(['en', ['tagline', 'subtitle', 'bio']], [$plan->tasks[0]->locale, $plan->tasks[0]->fields]);
    }

    public function test_a_model_without_records_is_named_and_planned_for_nothing(): void
    {
        $plan = $this->app->make(CmsTranslation::class)->plan([HomepageContent::class], ['de'], false);

        $this->assertSame(['HomepageContent'], $plan->emptyModels);
        $this->assertSame([], $plan->sources);
        $this->assertFalse($plan->needsTranslation());
    }
}
