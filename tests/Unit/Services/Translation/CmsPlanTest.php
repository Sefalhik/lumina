<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Translation;

use App\Models\HomepageContent;
use App\Services\Translation\CmsPlan;
use App\Services\Translation\CmsSource;
use App\Services\Translation\CmsTask;
use PHPUnit\Framework\TestCase;

class CmsPlanTest extends TestCase
{
    private function source(int $id): CmsSource
    {
        $record = new HomepageContent;
        $record->id = $id;

        return new CmsSource($record, 'cms_HomepageContent_'.$id, ['tagline' => 'Accroche', 'bio' => 'Bio']);
    }

    public function test_a_plan_counts_the_fields_of_every_task(): void
    {
        $one = $this->source(1);
        $two = $this->source(2);
        $plan = new CmsPlan([$one, $two], ['de', 'en'], [
            new CmsTask($one, 'de', ['tagline', 'bio']),
            new CmsTask($two, 'en', ['bio']),
        ], [], []);

        $this->assertSame(3, $plan->fieldCount());
        $this->assertTrue($plan->needsTranslation());
    }

    public function test_each_record_and_locale_gets_its_own_count_and_none_without_a_task(): void
    {
        $one = $this->source(1);
        $two = $this->source(2);
        $plan = new CmsPlan([$one, $two], ['de', 'en'], [
            new CmsTask($one, 'de', ['tagline', 'bio']),
            new CmsTask($two, 'en', ['bio']),
        ], [], []);

        $this->assertSame(2, $plan->fieldsFor($one, 'de'));
        $this->assertSame(0, $plan->fieldsFor($one, 'en'));
        $this->assertSame(0, $plan->fieldsFor($two, 'de'));
        $this->assertSame(1, $plan->fieldsFor($two, 'en'));
    }

    public function test_a_plan_with_nothing_to_translate_sends_nothing(): void
    {
        $plan = new CmsPlan([$this->source(1)], ['de'], [], [], []);

        $this->assertSame(0, $plan->fieldCount());
        $this->assertFalse($plan->needsTranslation());
        $this->assertFalse($plan->hasErrors());
    }

    public function test_a_refused_model_is_an_error_of_the_plan(): void
    {
        $this->assertTrue((new CmsPlan([], ['de'], [], [], ['X is not a model implementing TranslatableContent.']))->hasErrors());
    }

    public function test_a_task_sends_the_french_of_its_fields_only(): void
    {
        $this->assertSame(['bio' => 'Bio'], (new CmsTask($this->source(1), 'de', ['bio']))->strings());
    }

    public function test_a_record_is_labelled_by_its_model_and_its_key(): void
    {
        $this->assertSame('HomepageContent #7', $this->source(7)->label());
    }
}
