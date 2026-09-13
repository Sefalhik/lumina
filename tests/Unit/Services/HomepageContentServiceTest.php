<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\HomepageContentService;
use Tests\TestCase;

class HomepageContentServiceTest extends TestCase
{
    private HomepageContentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new HomepageContentService;
    }

    public function test_the_first_paragraph_becomes_the_lead(): void
    {
        $result = $this->service->prose("Premier.\n\nDeuxième.\n\nTroisième.");

        $this->assertSame('Premier.', $result['lead']);
        $this->assertSame(['Deuxième.', 'Troisième.'], $result['body']);
    }

    public function test_a_single_paragraph_leaves_no_body(): void
    {
        // The neutral fallback copy is one sentence: the About section must not
        // render an empty heading over nothing.
        $result = $this->service->prose('Une seule phrase.');

        $this->assertSame('Une seule phrase.', $result['lead']);
        $this->assertSame([], $result['body']);
    }

    public function test_null_and_blank_input_produce_nothing(): void
    {
        foreach ([null, '', '   ', "\n\n\n"] as $input) {
            $result = $this->service->prose($input);

            $this->assertSame('', $result['lead']);
            $this->assertSame([], $result['body']);
        }
    }

    public function test_windows_line_endings_split_the_same_way(): void
    {
        $result = $this->service->prose("Premier.\r\n\r\nDeuxième.");

        $this->assertSame('Premier.', $result['lead']);
        $this->assertSame(['Deuxième.'], $result['body']);
    }

    public function test_a_carriage_return_inside_a_paragraph_is_normalised_away(): void
    {
        // A browser textarea submits CRLF. Without normalisation the \r stays
        // inside the paragraph — invisible in HTML, but carried into every
        // translation and every diff of the seed file.
        $result = $this->service->prose("Ligne un.\r\nLigne deux.\r\n\r\nParagraphe deux.");

        $this->assertSame("Ligne un.\nLigne deux.", $result['lead']);
        $this->assertStringNotContainsString("\r", $result['lead']);
        $this->assertSame(['Paragraphe deux.'], $result['body']);
    }

    public function test_lone_carriage_returns_still_separate_paragraphs(): void
    {
        // Classic-Mac line endings. Rare to the point of extinction, but this
        // is the only input for which the normalisation changes the split
        // rather than merely tidying it — without it, this is one paragraph.
        $result = $this->service->prose("Premier.\r\rDeuxième.");

        $this->assertSame('Premier.', $result['lead']);
        $this->assertSame(['Deuxième.'], $result['body']);
    }

    public function test_extra_blank_lines_do_not_create_empty_paragraphs(): void
    {
        // A stray third newline is a typo, not a new paragraph. Without this,
        // the About section would render an empty <p>.
        $result = $this->service->prose("Premier.\n\n\n\nDeuxième.\n \nTroisième.");

        $this->assertSame('Premier.', $result['lead']);
        $this->assertSame(['Deuxième.', 'Troisième.'], $result['body']);
    }

    /**
     * The invariant the method's simplification rests on.
     *
     * Because the separator swallows a whole run of blank lines, no segment can
     * ever come back empty — so prose() filters nothing and guards nothing.
     * Narrow the pattern and this fails, which is the point: the comment in the
     * service promises this test fails on exactly that change.
     *
     * Stated as an invariant over many shapes rather than one example, so it
     * keeps holding for blank-line arrangements nobody thought to enumerate.
     */
    public function test_no_output_paragraph_is_ever_empty(): void
    {
        $shapes = [
            "A\n\nB",
            "A\n\n\nB",
            "A\n\n\n\n\n\nB",
            "A\n\n   \n\nB",
            "A\n\n \t \n\nB",
            "A\r\n\r\nB",
            "A\r\rB",
            "  \n\n  A  \n\n\n  B  \n\n  ",
            "A\n\nB\n\n\n\nC\n\nD",
        ];

        foreach ($shapes as $shape) {
            $result = $this->service->prose($shape);

            $readable = str_replace(["\n", "\r", "\t"], ['\n', '\r', '\t'], $shape);

            $this->assertNotSame('', $result['lead'], "Empty lead for: {$readable}");

            foreach ($result['body'] as $index => $paragraph) {
                $this->assertNotSame('', $paragraph, "Empty body paragraph {$index} for: {$readable}");
            }
        }
    }

    public function test_single_newlines_stay_inside_a_paragraph(): void
    {
        $result = $this->service->prose("Une ligne.\nLa suite.\n\nAutre paragraphe.");

        $this->assertSame("Une ligne.\nLa suite.", $result['lead']);
        $this->assertSame(['Autre paragraphe.'], $result['body']);
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $result = $this->service->prose("  \n  Premier.  \n\n  Deuxième.  \n  ");

        $this->assertSame('Premier.', $result['lead']);
        $this->assertSame(['Deuxième.'], $result['body']);
    }
}
