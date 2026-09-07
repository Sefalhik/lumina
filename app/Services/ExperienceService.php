<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Experience;

/**
 * Applies a validated admin submission onto an Experience.
 *
 * The write counterpart of CvService, and framework-agnostic for the same
 * reason: it mutates the model in memory and never saves, so the rules below
 * are unit-testable without a database or an HTTP request. Persisting is the
 * controller's business.
 */
class ExperienceService
{
    /**
     * The language the admin form edits.
     *
     * Machine translations of the other locales are produced by cms:translate
     * and must survive an edit, so a submission only ever touches this one.
     */
    public const SOURCE_LOCALE = 'fr';

    /**
     * Writes the submitted values onto the model, without saving.
     *
     * Two rules, and both matter:
     *
     * 1. A field absent from the submission is left alone. An emptied form
     *    control reaches validated() as a present null — that is a request to
     *    clear it. A field that was never sent is not. Conflating the two lets
     *    a partial payload wipe the French prose while the machine translations
     *    of the other locales survive, a state no screen can produce or repair.
     *
     * 2. Which fields are prose and which are plain columns is read from the
     *    model rather than repeated here. Employer, dates and job_title are
     *    data — job_title in particular is what a sameAs statement
     *    cross-references with the LinkedIn profile, which is why it is not
     *    translated. Duplicating that list would let the two drift.
     *
     * @param  array<string, mixed>  $validated
     */
    public function applySubmission(Experience $experience, array $validated): Experience
    {
        foreach ($experience->getFillable() as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            if (in_array($field, $experience->translatable, true)) {
                $experience->setTranslation($field, self::SOURCE_LOCALE, (string) $validated[$field]);

                continue;
            }

            $experience->setAttribute($field, $validated[$field]);
        }

        return $experience;
    }
}
