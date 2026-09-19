<?php

declare(strict_types=1);

namespace Tests\Feature\Documentation;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * CLAUDE.md is loaded whole at the start of every working session, so it holds only what must be
 * known at all times and points to docs/ for the rest (LUMN-34). That split works through one
 * table — "Where things are documented" — which says which reference to read before touching a
 * domain, and through references in both directions between the documentation and the code.
 *
 * Nothing else keeps those references true. A document added to docs/ without its row is never
 * read; a row or a link naming a renamed file sends the reader nowhere; a document describing a
 * file that no longer exists misleads whoever trusts it — which is how LUMN-30 was found, a
 * convention document describing a middleware that had never been written. And CLAUDE.md went from
 * 752 to 1010 lines in six days before it was split: without a ceiling, it grows back.
 */
class DocumentationIndexTest extends TestCase
{
    private const MAX_LINES = 400;

    /**
     * Directories of docs/ that are not references and deliberately stay out of the index.
     * Any other subdirectory is a reference: a new one must be indexed, or added here with a reason.
     */
    private const NOT_REFERENCES = [
        'docs/blog-prep/', // session brain dumps — raw material for the blog
        'docs/plans/',     // working plans
    ];

    /**
     * Roots a path must start with to be checked: the parts of the repository the documentation
     * describes. A path containing a wildcard or a placeholder (`*`, `{locale}`) is a pattern, not a
     * file, and is skipped.
     */
    private const CODE_ROOTS = 'app|bootstrap|config|database|lang|public|resources|routes|scripts|tests|\.github';

    /** Where the code may point back at the documentation. */
    private const CODE_DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'resources/js', 'routes', 'scripts', 'tests'];

    /**
     * Files whose `docs/…` mentions are not ours, each with its reason. Keep this list short: every
     * entry is a file whose links nobody checks.
     */
    private const FOREIGN_REFERENCES = [
        // Published by spatie/laravel-permission: `docs/prerequisites.md` is the package's own documentation.
        'database/migrations/2026_04_11_093456_create_permission_tables.php',
        // This test: its comments show what a reference looks like, with made-up names.
        'tests/Feature/Documentation/DocumentationIndexTest.php',
    ];

    private function read(string $relativePath): string
    {
        return (string) file_get_contents(base_path($relativePath));
    }

    /**
     * @return list<string> CLAUDE.md and every reference document, as repository-relative paths
     */
    private function documentation(): array
    {
        return ['CLAUDE.md', ...$this->referenceDocuments()];
    }

    /**
     * @return list<string> every Markdown file under docs/, outside the non-reference directories
     */
    private function referenceDocuments(): array
    {
        $documents = [];

        foreach ($this->filesUnder('docs') as $path) {
            if (! str_ends_with($path, '.md')) {
                continue;
            }
            foreach (self::NOT_REFERENCES as $excluded) {
                if (str_starts_with($path, $excluded)) {
                    continue 2;
                }
            }
            $documents[] = $path;
        }

        sort($documents);

        return $documents;
    }

    /**
     * @return list<string> repository-relative paths of every file under a directory
     */
    private function filesUnder(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = ltrim(substr($file->getPathname(), strlen(base_path())), '/');
            }
        }

        return $files;
    }

    /**
     * @return list<string> the docs/ paths listed in the index table of CLAUDE.md
     */
    private function indexedDocuments(): array
    {
        $matched = preg_match('/^## Where things are documented\n(.*?)(?=^## )/ms', $this->read('CLAUDE.md'), $section);
        $this->assertSame(1, $matched, 'CLAUDE.md has no "## Where things are documented" section.');

        preg_match_all('/^\|[^|\n]+\|\s*`(docs\/[\w.\/-]+\.md)`\s*\|/m', $section[1], $rows);

        return $rows[1];
    }

    public function test_every_reference_document_is_listed_in_the_index(): void
    {
        $missing = array_diff($this->referenceDocuments(), $this->indexedDocuments());

        $this->assertSame(
            [],
            array_values($missing),
            'Documents in docs/ that CLAUDE.md never points to — add a row to "Where things are documented", '
            .'or, for a directory that holds no reference, list it in NOT_REFERENCES with its reason.',
        );
    }

    public function test_every_indexed_document_exists(): void
    {
        $dead = array_diff($this->indexedDocuments(), $this->referenceDocuments());

        $this->assertSame([], array_values($dead), 'The index lists documents that do not exist.');
    }

    public function test_every_document_cited_in_the_documentation_exists(): void
    {
        // Links live outside the index too: the pointer under each CLAUDE.md heading, and the
        // documents that refer to one another.
        $dead = [];

        foreach ($this->documentation() as $document) {
            preg_match_all('/`(docs\/[\w.\/-]+\.md)`/', $this->read($document), $cited);
            foreach (array_unique($cited[1]) as $path) {
                if (! is_file(base_path($path))) {
                    $dead[] = "{$document} → {$path}";
                }
            }
        }

        $this->assertSame([], $dead, 'The documentation cites documents that do not exist.');
    }

    public function test_every_file_the_documentation_names_exists(): void
    {
        // Inline code only. A path inside a fenced block may be an example, or the very file a
        // ticket is about to create — docs/logging-conventions.md shows one for LUMN-30.
        $absent = [];

        foreach ($this->documentation() as $document) {
            $prose = (string) preg_replace('/^```.*?^```/ms', '', $this->read($document));
            preg_match_all('/`((?:'.self::CODE_ROOTS.')\/[^`\s]+)`/', $prose, $named);

            foreach (array_unique($named[1]) as $path) {
                if (preg_match('/[*{<]/', $path) === 1) {
                    continue;
                }
                if (! file_exists(base_path(rtrim($path, '/')))) {
                    $absent[] = "{$document} → {$path}";
                }
            }
        }

        $this->assertSame(
            [],
            $absent,
            'The documentation names files that do not exist — renamed or deleted without updating it.',
        );
    }

    public function test_every_reference_from_the_code_to_the_documentation_resolves(): void
    {
        // A docblock that says `@see docs/x.md` or `@see CLAUDE.md — "Section"` is a link too, and
        // the one nobody checks when a document is renamed or a section moves out.
        $claudeMd = $this->read('CLAUDE.md');
        $dead = [];

        foreach (self::CODE_DIRECTORIES as $directory) {
            foreach ($this->filesUnder($directory) as $file) {
                if (in_array($file, self::FOREIGN_REFERENCES, true)) {
                    continue;
                }
                $content = $this->read($file);

                preg_match_all('/\bdocs\/[\w.\/-]+\.md\b/', $content, $documents);
                foreach (array_unique($documents[0]) as $path) {
                    if (! is_file(base_path($path))) {
                        $dead[] = "{$file} → {$path}";
                    }
                }

                preg_match_all('/CLAUDE\.md\s+—\s+"([^"]+)"/u', $content, $sections);
                foreach (array_unique($sections[1]) as $title) {
                    if (preg_match('/^#{2,} '.preg_quote($title, '/').'$/m', $claudeMd) !== 1) {
                        $dead[] = "{$file} → CLAUDE.md \"{$title}\"";
                    }
                }
            }
        }

        $this->assertSame([], $dead, 'The code points at documentation that does not exist.');
    }

    public function test_every_link_in_the_documentation_leads_somewhere(): void
    {
        // A reference to another section is a Markdown link to its anchor — never an italic title,
        // never "above" or "below". Moving a section breaks a positional reference silently; it
        // breaks a link loudly, here. Renaming a heading changes its anchor: this test then names
        // every link still pointing at the old one.
        $broken = [];

        foreach ($this->documentation() as $document) {
            $prose = $this->withoutCodeBlocks($this->read($document));
            preg_match_all('/\[[^\]]*\]\(([^)\s]+)\)/', $prose, $links);

            foreach (array_unique($links[1]) as $target) {
                if (preg_match('/^(https?:|mailto:)/', $target) === 1) {
                    continue;
                }

                [$path, $fragment] = array_pad(explode('#', $target, 2), 2, '');
                $file = $path === '' ? $document : $this->resolve(dirname($document), $path);

                if ($file === null || ! is_file(base_path($file))) {
                    $broken[] = "{$document} → {$target} (no such file)";

                    continue;
                }
                if ($fragment !== '' && ! in_array($fragment, $this->anchorsOf($file), true)) {
                    $broken[] = "{$document} → {$target} (no such heading in {$file})";
                }
            }
        }

        $this->assertSame([], $broken, 'Links in the documentation that lead nowhere.');
    }

    private function withoutCodeBlocks(string $markdown): string
    {
        return (string) preg_replace('/^```.*?^```/ms', '', $markdown);
    }

    /**
     * Resolves a relative link against the directory of the document holding it, as GitHub does.
     * Returns null for a path that climbs out of the repository.
     */
    private function resolve(string $directory, string $path): ?string
    {
        $parts = [];
        foreach (explode('/', ($directory === '.' ? '' : $directory.'/').$path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);

                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * The anchors GitHub generates for a document's headings: lower-cased, stripped of everything
     * but letters, digits, spaces, hyphens and underscores, spaces turned into hyphens, and a -1,
     * -2… suffix on repeats. "Playwright — dedicated E2E database" gives
     * "playwright--dedicated-e2e-database": the dash goes, both spaces stay.
     *
     * @return list<string>
     */
    private function anchorsOf(string $file): array
    {
        preg_match_all('/^#{1,6}\s+(.+?)\s*#*\s*$/m', $this->withoutCodeBlocks($this->read($file)), $headings);

        $anchors = [];
        $seen = [];
        foreach ($headings[1] as $heading) {
            $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading);
            $slug = str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc} -]/u', '', mb_strtolower($text)));

            if (isset($seen[$slug])) {
                $seen[$slug]++;
                $anchors[] = $slug.'-'.$seen[$slug];

                continue;
            }
            $seen[$slug] = 0;
            $anchors[] = $slug;
        }

        return $anchors;
    }

    public function test_claude_md_stays_under_its_ceiling(): void
    {
        $lines = substr_count($this->read('CLAUDE.md'), "\n");

        $this->assertLessThan(
            self::MAX_LINES,
            $lines,
            "CLAUDE.md has {$lines} lines. What is consulted rather than known belongs in docs/, with a pointer here.",
        );
    }
}
