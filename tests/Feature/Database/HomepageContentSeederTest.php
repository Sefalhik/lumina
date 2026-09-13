<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\HomepageContent;
use Database\Seeders\HomepageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Guards the promise the seeder exists to keep: a deployment that runs it once,
 * offline, leaves production with its real content in all twenty-four locales.
 *
 * These tests assert on the shipped data file, not on a fixture. That is the
 * point — the file is what a deployment reads, so it is the file that has to be
 * right. A fixture would test the loader and prove nothing about production.
 */
class HomepageContentSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function supported(): array
    {
        /** @var list<string> $locales */
        $locales = config('i18n.supported_locales');

        return $locales;
    }

    private function runSeeder(): void
    {
        $this->seed(HomepageContentSeeder::class);
    }

    public function test_every_translatable_field_carries_every_supported_locale(): void
    {
        $this->runSeeder();

        $content = HomepageContent::first();
        $this->assertNotNull($content);

        foreach ($content->translatable as $field) {
            $locales = array_keys($content->getTranslations($field));
            sort($locales);

            $expected = $this->supported();
            sort($expected);

            $this->assertSame(
                $expected,
                $locales,
                "Field '{$field}' does not cover every supported locale.",
            );
        }
    }

    public function test_no_locale_silently_repeats_the_french_prose(): void
    {
        // spatie's fallback would serve French for a missing locale without
        // failing anything, which is exactly the drift this seeder exists to
        // prevent. A prose field whose value equals the French one is either
        // untranslated or a translation that did not happen.
        //
        // tagline and subtitle are deliberately out of scope: they are coined
        // phrases — "Neuromatrix online — biocortex actif" is identical in
        // seven locales because leaving it alone is the correct translation,
        // not a missing one. Asserting on them would turn a good decision into
        // a failing test.
        $this->runSeeder();

        $content = HomepageContent::first();
        $this->assertNotNull($content);

        foreach (['bio', 'meta_description'] as $field) {
            $french = $content->getTranslation($field, 'fr', false);

            foreach ($content->getTranslations($field) as $locale => $value) {
                if ($locale === 'fr') {
                    continue;
                }

                $this->assertNotSame(
                    $french,
                    $value,
                    "Field '{$field}' in '{$locale}' is identical to the French value.",
                );
            }
        }
    }

    public function test_seeding_makes_no_http_request(): void
    {
        // The whole architecture of this ticket rests on this assertion: the
        // translation API is called when the content is written, never when it
        // is deployed. Http::fake() with no stub would let a real call through
        // as a faked 200 — assertNothingSent is what actually proves it.
        Http::fake();

        $this->runSeeder();

        Http::assertNothingSent();
    }

    public function test_a_second_run_leaves_edited_content_alone(): void
    {
        $this->runSeeder();

        $content = HomepageContent::first();
        $this->assertNotNull($content);
        $content->setTranslation('bio', 'fr', 'Édité depuis l’administration.');
        $content->save();

        // Answering "no" is what a deployment produces: with no terminal
        // attached, confirm() returns its default, which this seeder sets to
        // false. The console output is mocked under test, so the answer has to
        // be declared rather than left to that default — same approach as
        // I18nTranslateTest.
        $this->artisan('db:seed', ['--class' => HomepageContentSeeder::class])
            ->expectsConfirmation(
                'HomepageContent already has data. Overwrite it from '.HomepageContentSeeder::DATA_FILE.'?',
                'no',
            )
            ->assertSuccessful();

        $this->assertSame(
            'Édité depuis l’administration.',
            HomepageContent::first()?->getTranslation('bio', 'fr', false),
        );
        $this->assertSame(1, HomepageContent::count());
    }

    public function test_the_skills_payload_is_valid_json_in_every_locale(): void
    {
        $this->runSeeder();

        $content = HomepageContent::first();
        $this->assertNotNull($content);

        foreach ($content->getTranslations('skills') as $locale => $raw) {
            $decoded = json_decode($raw, true);

            $this->assertIsArray($decoded, "Skills JSON is invalid in '{$locale}'.");
            $this->assertNotEmpty($decoded, "Skills JSON is empty in '{$locale}'.");
        }
    }

    public function test_seeding_is_logged_with_the_mandatory_context(): void
    {
        // A deployment's console output is usually discarded. This line is what
        // answers "did production actually get its content?" afterwards, so the
        // three keys logging-conventions.md makes mandatory are asserted, not
        // merely the fact that something was logged.
        $log = Log::spy();

        $this->runSeeder();

        $log->shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Homepage content seeded'
                    && $context['service'] === HomepageContentSeeder::class
                    && $context['method'] === 'run'
                    && $context['step'] === 'seeded'
                    && $context['overwritten'] === false
                    && $context['locales'] === 24;
            })
            ->once();
    }

    public function test_a_skipped_run_is_logged_too(): void
    {
        // The silent branch is the one worth a trace: nothing changed, and
        // without this line nothing says why.
        $this->runSeeder();

        $log = Log::spy();

        $this->artisan('db:seed', ['--class' => HomepageContentSeeder::class])
            ->expectsConfirmation(
                'HomepageContent already has data. Overwrite it from '.HomepageContentSeeder::DATA_FILE.'?',
                'no',
            )
            ->assertSuccessful();

        $log->shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Homepage content seeding skipped'
                    && $context['step'] === 'skipped_existing';
            })
            ->once();
    }

    public function test_the_french_bio_fits_the_limit_the_admin_form_enforces(): void
    {
        // HomepageContentRequest validates `bio.fr` at max:1000, and only that
        // key — the form edits the source locale alone. Seeded French longer
        // than the limit would be impossible to re-save from the very screen it
        // is meant to be edited on.
        //
        // Translations are not checked, and that is deliberate rather than an
        // oversight: they routinely run longer. Measured on this content,
        // French is 960 characters and German 1062. Should per-locale editing
        // ever ship, this assertion has to grow with it.
        $this->assertLessThanOrEqual(
            1000,
            mb_strlen(HomepageContentSeeder::data()['bio']['fr']),
        );
    }
}
