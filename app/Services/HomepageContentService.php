<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Splits the homepage bio into the hook the hero shows and the prose that
 * follows it.
 *
 * Exists because the two have different jobs. The hero has to fit identity,
 * hook and call to action above the fold; the biography has to be allowed to
 * be long. Before this split the whole bio sat in the hero, which pushed the
 * buttons roughly 350px below the fold once the copy grew past a sentence.
 *
 * Takes a string rather than a model, for the same reason CvService takes a
 * collection: no database, no request, no locale lookup — the caller owns all
 * three, this owns the shaping.
 */
class HomepageContentService
{
    /**
     * Paragraphs are separated by a blank line, the way the admin textarea
     * produces them. The separator is not stored as markup, so nothing here
     * has to be escaped and nothing the author types can inject.
     *
     * @return array{lead: string, body: list<string>}
     */
    public function prose(?string $bio): array
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", (string) $bio);

        // Any run of blank lines is ONE separator: a double newline is the
        // convention, a stray third is a typo, not a new paragraph.
        //
        // The rest of this method leans on that greediness. Because `\s*\n+`
        // swallows a whole run, preg_split can never return an empty segment
        // in the middle, and trim() above rules out one at either end — so
        // there is nothing to filter out afterwards, and the array is never
        // empty. Narrow this pattern (to `/\n\n/`, say) and empty paragraphs
        // become possible again: the About section would render blank <p>
        // elements, and the filtering removed here would have to come back.
        //
        // That coupling is guarded, not merely written down:
        // HomepageContentServiceTest::test_no_output_paragraph_is_ever_empty()
        // fails on exactly that change.
        $paragraphs = array_map(trim(...), preg_split('/\n\s*\n+/', trim($normalised)) ?: ['']);

        // array_shift() reindexes, so what is left is already a list — no
        // array_values() needed, and PHPStan says so.
        return [
            'lead' => array_shift($paragraphs),
            'body' => $paragraphs,
        ];
    }
}
