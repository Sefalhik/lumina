<?php

declare(strict_types=1);

namespace Tests\Unit\Documentation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Documentation\DocumentationChecks;

/**
 * The detections that keep the documentation true, each proven on fixed input — its failing branch
 * first: run on a clean repository alone, a detection never takes it, and deleting the line that
 * records a problem went unnoticed 35 times in one mechanical pass (LUMN-150).
 *
 * The paths and headings here are made up: tests/Feature/Documentation/DocumentationIndexTest.php
 * lists this file among those whose references it does not follow.
 */
class DocumentationChecksTest extends TestCase
{
    private DocumentationChecks $checks;

    protected function setUp(): void
    {
        $this->checks = new DocumentationChecks;
    }

    public function test_reference_documents_are_the_markdown_files_of_docs_outside_the_excluded_directories(): void
    {
        $files = [
            'docs/z.md', 'docs/sub/y.md', 'docs/image.png', 'README.md', 'app/docs/x.md',
            'docs/blog-prep/session.md', 'docs/plans/plan.md',
        ];

        $this->assertSame(
            ['docs/sub/y.md', 'docs/z.md'],
            $this->checks->referenceDocuments($files, ['docs/blog-prep/', 'docs/plans/']),
        );
    }

    public function test_clean_input_yields_no_problem_from_any_detection(): void
    {
        // The empty answer is an answer too: each detection must return an empty list, not nothing.
        $none = fn (): bool => false;
        $this->assertSame([], $this->checks->oversized(['a.md' => 'x'], 10));
        $this->assertSame([], $this->checks->deadCitations(['a.md' => 'No citation.'], $none));
        $this->assertSame([], $this->checks->absentNamedFiles(['a.md' => 'No path.'], 'app', $none, $none));
        $this->assertSame([], $this->checks->deadCodeReferences(['app/A.php' => '<?php'], '', $none));
        $this->assertSame([], $this->checks->brokenLinks(['a.md' => 'No link.'], fn (): ?string => null));
        $this->assertSame([], $this->checks->undocumentedClasses(['app/Rules' => ['A']], 'A'));
        $this->assertSame([], $this->checks->anchorsOf('No heading.'));
        $this->assertSame('', $this->checks->resolve('.', ''));
    }

    public function test_the_index_is_read_from_its_section_alone(): void
    {
        $claudeMd = "# CLAUDE.md\n\n| A | `docs/before.md` | x |\n\n## Where things are documented\n\n"
            ."| Domain | Reference | Read |\n|---|---|---|\n| A | `docs/a.md` | x |\n| B | `docs/sub/README.md` | y |\n"
            ."| C | docs/unquoted.md | z |\n\n## Commands\n\n| D | `docs/after.md` | w |\n";

        $this->assertSame(['docs/a.md', 'docs/sub/README.md'], $this->checks->indexRows($claudeMd));
    }

    public function test_the_index_may_be_the_last_section(): void
    {
        $this->assertSame(['docs/a.md'], $this->checks->indexRows("## Where things are documented\n\n| A | `docs/a.md` | x |\n"));
    }

    public function test_a_claude_md_without_the_index_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->checks->indexRows("# CLAUDE.md\n\n## Commands\n");
    }

    public function test_a_readme_in_the_index_reaches_the_documents_of_its_own_directory_it_links_to(): void
    {
        $readme = "# Sub\n\n| [one.md](one.md) | |\n| [two.md](./two.md#a-heading) | |\n| [one.md](one.md) | again |\n"
            ."See [elsewhere](../other.md) and [deeper](deeper/three.md).\n\n```markdown\n[hidden](hidden.md)\n```\n";

        $this->assertSame(
            ['docs/a.md', 'docs/sub/README.md', 'docs/sub/one.md', 'docs/sub/two.md'],
            $this->checks->reachableDocuments(['docs/a.md', 'docs/sub/README.md'], ['docs/sub/README.md' => $readme]),
        );
    }

    public function test_only_an_indexed_readme_indexes_anything(): void
    {
        // A README the index does not name, or a row that is not a README: their links index nothing.
        $this->assertSame(
            ['docs/a.md'],
            $this->checks->reachableDocuments(['docs/a.md'], [
                'docs/sub/README.md' => '[one.md](one.md)',
                'docs/a.md' => '[b.md](b.md)',
            ]),
        );
        $this->assertSame(['docs/sub/README.md'], $this->checks->reachableDocuments(['docs/sub/README.md'], []));
    }

    /**
     * @param  array<string, string>  $documents
     * @param  list<string>  $expected
     */
    #[DataProvider('sizes')]
    public function test_a_document_over_the_size_is_named_with_its_size(array $documents, array $expected): void
    {
        $this->assertSame($expected, $this->checks->oversized($documents, 10));
    }

    /** @return array<string, array{array<string, string>, list<string>}> */
    public static function sizes(): array
    {
        return [
            'at the size' => [['a.md' => str_repeat('x', 10)], []],
            'one byte over' => [['a.md' => str_repeat('x', 11)], ['a.md: 11 bytes']],
            'bytes, not characters' => [['a.md' => str_repeat('é', 6)], ['a.md: 12 bytes']],
            'only those over' => [['a.md' => 'x', 'b.md' => str_repeat('x', 20)], ['b.md: 20 bytes']],
        ];
    }

    #[DataProvider('texts')]
    public function test_lines_are_counted_the_last_one_included(string $content, int $expected): void
    {
        $this->assertSame($expected, $this->checks->lineCount($content));
    }

    /** @return array<string, array{string, int}> */
    public static function texts(): array
    {
        return [
            'empty' => ['', 0],
            'one line, no final newline' => ['a', 1],
            'one line' => ["a\n", 1],
            'two lines, no final newline' => ["a\nb", 2],
            'an empty last line' => ["a\n\n", 2],
        ];
    }

    public function test_a_cited_document_that_does_not_exist_is_named_once(): void
    {
        $documents = ['docs/a.md' => 'See `docs/gone.md`, `docs/here.md`, then `docs/gone.md` again, and docs/bare.md.'];

        $this->assertSame(
            ['docs/a.md → docs/gone.md'],
            $this->checks->deadCitations($documents, fn (string $path): bool => $path === 'docs/here.md'),
        );
    }

    public function test_a_named_file_that_exists_nowhere_is_named_unless_ignored_or_a_pattern(): void
    {
        $documents = ['docs/a.md' => implode("\n", [
            '`app/Gone.php` `app/Here.php` `public/build/` `resources/js/*.js` `lang/{locale}/x.php`',
            '`scripts/<file>` `vendor/x.php` `app/Gone.php`',
            '```bash',
            'cat `app/InAFence.php`',
            '```',
        ])];

        $this->assertSame(
            ['docs/a.md → app/Gone.php'],
            $this->checks->absentNamedFiles(
                $documents,
                'app|public|resources|lang|scripts',
                fn (string $path): bool => $path === 'app/Here.php',
                fn (string $path): bool => $path === 'public/build/',
            ),
        );
    }

    public function test_a_directory_is_checked_without_its_trailing_slash(): void
    {
        $this->assertSame(
            [],
            $this->checks->absentNamedFiles(['a.md' => '`app/Services/`'], 'app', fn (string $path): bool => $path === 'app/Services', fn (): bool => false),
        );
    }

    public function test_the_code_pointing_at_missing_documentation_is_named(): void
    {
        $claudeMd = "# CLAUDE.md\n\n## Commands\n\n### Deep heading\n";
        $files = [
            'app/A.php' => '@see docs/gone.md and docs/here.md, @see CLAUDE.md — "Commands"',
            'app/B.php' => '@see CLAUDE.md — "Gone" and CLAUDE.md — "Deep heading" and CLAUDE.md — "CLAUDE.md"',
        ];

        $this->assertSame(
            ['app/A.php → docs/gone.md', 'app/B.php → CLAUDE.md "Gone"', 'app/B.php → CLAUDE.md "CLAUDE.md"'],
            $this->checks->deadCodeReferences($files, $claudeMd, fn (string $path): bool => $path === 'docs/here.md'),
        );
    }

    public function test_a_link_to_no_file_or_to_no_heading_is_named(): void
    {
        $documents = ['docs/sub/a.md' => implode("\n", [
            '# A', '## Own heading',
            '[ok](b.md) [ok](b.md#b-heading) [ok](#own-heading) [ok](../../CLAUDE.md#commands)',
            '[gone](gone.md) [no heading](b.md#nope) [not mine](#nope) [out](../../../outside.md) [gone too](gone.md#x)',
            '[web](https://example.test/x.md) [mail](mailto:a@example.test)',
            '```', '[fenced](fenced.md)', '```',
        ])];
        $files = ['docs/sub/b.md' => "# B\n\n## B heading\n", 'CLAUDE.md' => "## Commands\n"];

        $this->assertSame(
            [
                'docs/sub/a.md → gone.md (no such file)',
                'docs/sub/a.md → b.md#nope (no such heading in docs/sub/b.md)',
                'docs/sub/a.md → #nope (no such heading in docs/sub/a.md)',
                'docs/sub/a.md → ../../../outside.md (no such file)',
                'docs/sub/a.md → gone.md#x (no such file)',
            ],
            $this->checks->brokenLinks($documents, fn (string $path): ?string => $files[$path] ?? null),
        );
    }

    /** @param list<string> $expected */
    #[DataProvider('headings')]
    public function test_anchors_are_github_s(string $markdown, array $expected): void
    {
        $this->assertSame($expected, $this->checks->anchorsOf($markdown));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function headings(): array
    {
        return [
            'a dash goes, both spaces stay' => ['## Playwright — dedicated E2E database', ['playwright--dedicated-e2e-database']],
            'repeats get a suffix' => ["## Why\n## Why\n### Why", ['why', 'why-1', 'why-2']],
            'a link keeps its text' => ['## See [the doctrine](x.md)', ['see-the-doctrine']],
            'accents, digits and underscores stay' => ['## Étape 2: `snake_case`', ['étape-2-snake_case']],
            'every level counts' => ["# One\n###### Six", ['one', 'six']],
            'a heading in a fence is no heading' => ["```\n## Not one\n```\n## Real", ['real']],
            'a closing sequence is not part of it' => ['## Title ##', ['title']],
        ];
    }

    #[DataProvider('paths')]
    public function test_a_link_resolves_as_github_resolves_it(string $directory, string $path, ?string $expected): void
    {
        $this->assertSame($expected, $this->checks->resolve($directory, $path));
    }

    /** @return array<string, array{string, string, ?string}> */
    public static function paths(): array
    {
        return [
            'from the root' => ['.', 'docs/a.md', 'docs/a.md'],
            'beside' => ['docs/sub', 'b.md', 'docs/sub/b.md'],
            'up' => ['docs/sub', '../a.md', 'docs/a.md'],
            'dots and doubled slashes' => ['docs', './sub//./b.md', 'docs/sub/b.md'],
            'out of the repository' => ['docs', '../../a.md', null],
        ];
    }

    public function test_a_class_the_documentation_never_names_is_named(): void
    {
        $this->assertSame(
            ['app/Rules/Gone'],
            $this->checks->undocumentedClasses(['app/Rules' => ['Named', 'Gone'], 'app/Enums' => []], 'Named is documented.'),
        );
    }
}
