<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Experience;
use App\Services\CvService;
use App\Services\ExperienceService;
use Tests\TestCase;

class ExperienceServiceTest extends TestCase
{
    private ExperienceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ExperienceService(new CvService);
    }

    /** @param array<string, mixed> $attributes */
    private function experience(array $attributes = []): Experience
    {
        return new Experience(array_merge([
            'employer' => 'Groupe Vantarel',
            'job_title' => 'Principal Engineer',
            'started_at' => '2024-04-01',
        ], $attributes));
    }

    // ── Plain columns ─────────────────────────────────────────────────────────

    public function test_plain_columns_are_written(): void
    {
        $experience = $this->service->applySubmission(new Experience, [
            'employer' => 'Groupe Vantarel',
            'job_title' => 'Principal Engineer',
            'location' => 'Lille',
            'started_at' => '2024-04-01',
        ]);

        $this->assertSame('Groupe Vantarel', $experience->employer);
        $this->assertSame('Principal Engineer', $experience->job_title);
        $this->assertSame('Lille', $experience->location);
        $this->assertSame('2024-04-01', $experience->started_at->format('Y-m-d'));
    }

    public function test_a_submitted_null_clears_a_plain_column(): void
    {
        // An emptied form control reaches validated() as a present null, which
        // is a request to clear the field.
        $experience = $this->service->applySubmission(
            $this->experience(['location' => 'Lille']),
            ['location' => null],
        );

        $this->assertNull($experience->location);
    }

    public function test_an_absent_plain_column_is_left_alone(): void
    {
        $experience = $this->service->applySubmission(
            $this->experience(['location' => 'Lille']),
            ['employer' => 'Autre Employeur'],
        );

        $this->assertSame('Autre Employeur', $experience->employer);
        $this->assertSame('Lille', $experience->location);
    }

    public function test_unknown_keys_are_ignored(): void
    {
        // Only what the model declares fillable is ever written, so a crafted
        // payload cannot reach a column the form does not expose.
        $experience = $this->service->applySubmission(new Experience, [
            'employer' => 'Groupe Vantarel',
            'id' => 999,
            'created_at' => '1990-01-01',
        ]);

        $this->assertNull($experience->getAttribute('id'));
        $this->assertNull($experience->getAttribute('created_at'));
    }

    // ── Translated prose ──────────────────────────────────────────────────────

    public function test_prose_is_written_to_the_source_locale(): void
    {
        $experience = $this->service->applySubmission($this->experience(), [
            'description' => 'Direction technique.',
        ]);

        $this->assertSame(
            'Direction technique.',
            $experience->getTranslation('description', ExperienceService::SOURCE_LOCALE, false),
        );
    }

    public function test_writing_prose_leaves_the_other_locales_alone(): void
    {
        $experience = $this->experience();
        $experience->setTranslation('description', 'de', 'Deutscher Text.');

        $this->service->applySubmission($experience, ['description' => 'Texte révisé.']);

        $this->assertSame('Texte révisé.', $experience->getTranslation('description', 'fr', false));
        $this->assertSame('Deutscher Text.', $experience->getTranslation('description', 'de', false));
    }

    public function test_a_submitted_empty_string_clears_the_prose(): void
    {
        $experience = $this->experience();
        $experience->setTranslation('description', 'fr', 'Texte à effacer.');

        $this->service->applySubmission($experience, ['description' => '']);

        $this->assertSame('', $experience->getTranslation('description', 'fr', false));
    }

    public function test_absent_prose_is_left_untouched(): void
    {
        // The rule this service exists for. An absent field is not a request to
        // erase: conflating the two wipes the French prose while the machine
        // translations of every other locale survive, a state no screen can
        // produce and none can repair.
        $experience = $this->experience();
        $experience->setTranslation('description', 'fr', 'Texte à conserver.');
        $experience->setTranslation('description', 'de', 'Deutscher Text.');

        $this->service->applySubmission($experience, ['employer' => 'Autre Employeur']);

        $this->assertSame('Texte à conserver.', $experience->getTranslation('description', 'fr', false));
        $this->assertSame('Deutscher Text.', $experience->getTranslation('description', 'de', false));
    }

    // ── The prose list is not duplicated ──────────────────────────────────────

    public function test_job_title_is_written_as_a_plain_column(): void
    {
        // If job_title ever became translatable on the model, this service would
        // follow without being touched — and this test would fail, which is the
        // point: the decision belongs to the model, not to a list repeated here.
        $experience = $this->service->applySubmission(new Experience, [
            'job_title' => 'Tech Lead',
        ]);

        $this->assertSame('Tech Lead', $experience->getAttributes()['job_title'] ?? null);
    }

    public function test_the_translated_fields_come_from_the_model(): void
    {
        $this->assertSame(
            ['description', 'achievements'],
            (new Experience)->translatable,
        );
    }

    // ── Chaining ──────────────────────────────────────────────────────────────

    public function test_the_model_is_returned_for_chaining(): void
    {
        $experience = new Experience;

        $this->assertSame($experience, $this->service->applySubmission($experience, []));
    }
}
