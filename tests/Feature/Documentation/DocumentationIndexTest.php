<?php

declare(strict_types=1);

namespace Tests\Feature\Documentation;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\Documentation\DocumentationChecks;
use Tests\TestCase;

/**
 * CLAUDE.md is loaded whole at the start of every working session, so it holds only what must be
 * known at all times and points to docs/ for the rest (LUMN-34). Since LUMN-150 the documentation has
 * three tiers: CLAUDE.md, the rules of docs/rules/ that Claude Code loads by itself, and the
 * references. That works through one table — "Where things are documented" — through the README.md
 * of each subdirectory it names, and through references in both directions between the documentation
 * and the code.
 *
 * Nothing else keeps those references true. A document added to docs/ without its row is never
 * read; a row or a link naming a renamed file sends the reader nowhere; a document describing a
 * file that no longer exists misleads whoever trusts it — which is how LUMN-30 was found, a
 * convention document describing a middleware that had never been written. And CLAUDE.md went from
 * 752 to 1010 lines in six days before it was split: without a ceiling, it grows back.
 *
 * Each detection lives in Tests\Support\Documentation\DocumentationChecks, proven on fixed input by
 * Tests\Unit\Documentation\DocumentationChecksTest; this test applies them to the repository.
 */
class DocumentationIndexTest extends TestCase
{
    /** The ceiling Claude Code's own documentation recommends for a file loaded in every session. */
    private const MAX_LINES = 200;

    /**
     * The size of any document, CLAUDE.md included (LUMN-150). In bytes, not lines: a table row can
     * run to 670 bytes, and bytes are what reading a document costs — a rule of docs/rules/ is loaded
     * whole each time a file of its domain is opened. Over it, a document is split, by nature:
     * procedure, reference, history.
     */
    private const MAX_BYTES = 15 * 1024;

    /**
     * Directories of docs/ that are not references and deliberately stay out of the index.
     * Any other subdirectory is a reference: a new one must be indexed, or added here with a reason.
     */
    private const NOT_REFERENCES = [
        'docs/blog-prep/', // session brain dumps — raw material for the blog
        'docs/plans/',     // working plans
    ];

    /**
     * Families whose classes the documentation names one by one: a contract each, and few enough
     * that naming them is a line, not a chore.
     */
    private const DOCUMENTED_FAMILIES = ['app/Enums', 'app/Http/Middleware', 'app/Listeners', 'app/Rules'];

    /**
     * Roots a path must start with to be checked: the parts of the repository the documentation
     * describes.
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
        // The detections' own tests: their fixtures show what a reference looks like, with made-up names.
        'tests/Unit/Documentation/DocumentationChecksTest.php',
        // The rules checker's tests: they build temporary trees, whose docs/ paths are made up.
        'scripts/__tests__/claude-rules.test.js',
    ];

    private DocumentationChecks $checks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checks = new DocumentationChecks;
    }

    public function test_every_reference_document_is_listed_in_the_index(): void
    {
        $missing = array_diff($this->referenceDocuments(), $this->reachableDocuments());

        $this->assertSame(
            [],
            array_values($missing),
            'Documents in docs/ that no index points to — add a row to "Where things are documented", '
            .'or, in a subdirectory, a link from its README.md, itself a row of the index. For a '
            .'directory that holds no reference, list it in NOT_REFERENCES with its reason.',
        );
    }

    public function test_every_indexed_document_exists(): void
    {
        $dead = array_diff($this->reachableDocuments(), $this->referenceDocuments());

        $this->assertSame([], array_values($dead), 'The index lists documents that do not exist.');
    }

    public function test_no_document_outgrows_its_size(): void
    {
        $this->assertSame(
            [],
            $this->checks->oversized($this->contents($this->documentation()), self::MAX_BYTES),
            sprintf('Documents over %d bytes — split them by nature: procedure, reference, history.', self::MAX_BYTES),
        );
    }

    public function test_every_document_cited_in_the_documentation_exists(): void
    {
        // Links live outside the index too: the pointer under each CLAUDE.md heading, and the
        // documents that refer to one another.
        $this->assertSame(
            [],
            $this->checks->deadCitations($this->contents($this->documentation()), $this->isFile(...)),
            'The documentation cites documents that do not exist.',
        );
    }

    public function test_every_file_the_documentation_names_exists(): void
    {
        // A gitignored path is skipped: the documentation may name what the project generates —
        // public/build, public/hot — and those exist on a developer machine but never in a fresh
        // checkout. Asking the filesystem alone made this test pass locally and fail in CI, whose
        // PHP job builds no assets. Asking git makes the answer the same everywhere.
        $this->assertSame(
            [],
            $this->checks->absentNamedFiles(
                $this->contents($this->documentation()),
                self::CODE_ROOTS,
                fn (string $path): bool => file_exists(base_path($path)),
                $this->isGitignored(...),
            ),
            'The documentation names files that do not exist — renamed or deleted without updating it.',
        );
    }

    public function test_every_reference_from_the_code_to_the_documentation_resolves(): void
    {
        // A docblock that says `@see docs/x.md` or `@see CLAUDE.md — "Section"` is a link too, and
        // the one nobody checks when a document is renamed or a section moves out.
        $files = [];
        foreach (self::CODE_DIRECTORIES as $directory) {
            $files = [...$files, ...$this->filesUnder($directory)];
        }
        // The seam: without code to read, this test would pass on nothing at all.
        $this->assertContains('tests/Feature/Documentation/DocumentationIndexTest.php', $files);

        $this->assertSame(
            [],
            $this->checks->deadCodeReferences(
                $this->contents(array_values(array_diff($files, self::FOREIGN_REFERENCES))),
                $this->read('CLAUDE.md'),
                $this->isFile(...),
            ),
            'The code points at documentation that does not exist.',
        );
    }

    public function test_every_link_in_the_documentation_leads_somewhere(): void
    {
        // A reference to another section is a Markdown link to its anchor — never an italic title,
        // never "above" or "below". Moving a section breaks a positional reference silently; it
        // breaks a link loudly, here. Renaming a heading changes its anchor: this test then names
        // every link still pointing at the old one.
        $this->assertSame(
            [],
            $this->checks->brokenLinks(
                $this->contents($this->documentation()),
                fn (string $path): ?string => $this->isFile($path) ? $this->read($path) : null,
            ),
            'Links in the documentation that lead nowhere.',
        );
    }

    public function test_every_class_of_a_documented_family_is_named_in_the_documentation(): void
    {
        // Only the families the documentation names class by class. Measured on 2026-09-20: these
        // four have zero undocumented classes, while the same rule applied to app/Services or
        // app/Console/Commands is noise — the documentation names a command by its signature
        // ("php artisan cms:translate") and a probe by its behaviour, never by its class.
        //
        // What this guards is presence, not accuracy: a stale line still passes. It replaces
        // "remember to document it" with "the suite says so".
        $families = [];
        foreach (self::DOCUMENTED_FAMILIES as $family) {
            $families[$family] = array_map(fn (string $file): string => basename($file, '.php'), $this->filesUnder($family));
            // A family with no class checks nothing: remove it from DOCUMENTED_FAMILIES instead.
            $this->assertNotSame([], $families[$family], "{$family} holds no class.");
        }

        $this->assertSame(
            [],
            $this->checks->undocumentedClasses($families, implode("\n", $this->contents($this->documentation()))),
            'Classes nobody documented — name each one where its domain is documented.',
        );
    }

    public function test_claude_md_stays_under_its_ceiling(): void
    {
        $lines = $this->checks->lineCount($this->read('CLAUDE.md'));

        $this->assertLessThan(
            self::MAX_LINES,
            $lines,
            "CLAUDE.md has {$lines} lines, for a ceiling of ".self::MAX_LINES.'. What is consulted rather than known '
            .'belongs in docs/, with a pointer here; what applies to one part of the code belongs in a rule of docs/rules/.',
        );
    }

    /**
     * @return list<string> CLAUDE.md and every reference document, as repository-relative paths
     */
    private function documentation(): array
    {
        return ['CLAUDE.md', ...$this->referenceDocuments()];
    }

    /** @return list<string> */
    private function referenceDocuments(): array
    {
        return $this->checks->referenceDocuments($this->filesUnder('docs'), self::NOT_REFERENCES);
    }

    /** @return list<string> */
    private function reachableDocuments(): array
    {
        try {
            $rows = $this->checks->indexRows($this->read('CLAUDE.md'));
        } catch (InvalidArgumentException $exception) {
            $this->fail($exception->getMessage());
        }
        // Every row that exists: reachableDocuments() reads the README rows alone.
        $existing = array_values(array_filter($rows, $this->isFile(...)));

        return $this->checks->reachableDocuments($rows, $this->contents($existing));
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, string> path => content
     */
    private function contents(array $paths): array
    {
        return array_combine($paths, array_map($this->read(...), $paths));
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
            // The copy scripts/mutate-lines.js keeps beside a file while it mutates it is no file of
            // the repository: read as code, it made this very test fail before its own pass could start.
            if ($file->isFile() && ! str_ends_with($file->getFilename(), '.before-mutation')) {
                $files[] = ltrim(substr($file->getPathname(), strlen(base_path())), '/');
            }
        }

        return $files;
    }

    private function read(string $relativePath): string
    {
        return (string) file_get_contents(base_path($relativePath));
    }

    private function isFile(string $relativePath): bool
    {
        return is_file(base_path($relativePath));
    }

    /**
     * Whether git ignores a path — a build or runtime artifact rather than a file of the repository.
     */
    private function isGitignored(string $path): bool
    {
        $status = shell_exec('cd '.escapeshellarg(base_path()).' && git check-ignore -q -- '.escapeshellarg(rtrim($path, '/')).'; echo $?');

        return trim((string) $status) === '0';
    }
}
