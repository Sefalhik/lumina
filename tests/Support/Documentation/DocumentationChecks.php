<?php

declare(strict_types=1);

namespace Tests\Support\Documentation;

use InvalidArgumentException;

/**
 * The detections that keep the documentation true (LUMN-34, LUMN-150), as pure functions: each takes
 * the contents it judges and returns the problems it finds, and reads nothing by itself.
 *
 * They used to live inside Tests\Feature\Documentation\DocumentationIndexTest, run on the repository
 * alone. A clean repository never took their failing branch: deleting the line that records a problem
 * left the suite green, 35 times over in one mechanical pass. Here each is proven on fixed input first
 * (Tests\Unit\Documentation\DocumentationChecksTest), then applied to the repository.
 */
final class DocumentationChecks
{
    /** The file that indexes a subdirectory of docs/: the subdirectory is indexed through it. */
    public const SUBDIRECTORY_INDEX = 'README.md';

    /**
     * The Markdown files of docs/ that are references: every one outside the given directories.
     *
     * @param  list<string>  $files  repository-relative paths
     * @param  list<string>  $notReferences  directory prefixes, each ending with a slash
     * @return list<string> sorted
     */
    public function referenceDocuments(array $files, array $notReferences): array
    {
        $documents = array_values(array_filter(
            $files,
            fn (string $path): bool => str_starts_with($path, 'docs/') && str_ends_with($path, '.md')
                && ! array_any($notReferences, fn (string $excluded): bool => str_starts_with($path, $excluded)),
        ));
        sort($documents);

        return $documents;
    }

    /**
     * The docs/ paths the index table of CLAUDE.md lists, in its "Where things are documented" section.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException when CLAUDE.md has no such section
     */
    public function indexRows(string $claudeMd): array
    {
        if (preg_match('/^## Where things are documented\n(.*?)(?=^## |\z)/ms', $claudeMd, $section) !== 1) {
            throw new InvalidArgumentException('CLAUDE.md has no "## Where things are documented" section.');
        }
        preg_match_all('/^\|[^|\n]+\|\s*`(docs\/[\w.\/-]+\.md)`\s*\|/m', $section[1], $rows);

        return $rows[1];
    }

    /**
     * Every document a reader reaches from the index: its rows, and, for a row naming the README.md of
     * a subdirectory, each document of that same subdirectory the README links to.
     *
     * @param  list<string>  $rows
     * @param  array<string, string>  $readmes  path => content, for the indexed READMEs that exist
     * @return list<string>
     */
    public function reachableDocuments(array $rows, array $readmes): array
    {
        $reachable = $rows;

        foreach ($rows as $row) {
            if (basename($row) !== self::SUBDIRECTORY_INDEX || ! isset($readmes[$row])) {
                continue;
            }
            preg_match_all('/\]\(([^)#\s]+\.md)(?:#[^)]*)?\)/', $this->withoutCodeBlocks($readmes[$row]), $links);
            foreach ($links[1] as $link) {
                $document = $this->resolve(dirname($row), $link);
                if ($document !== null && dirname($document) === dirname($row)) {
                    $reachable[] = $document;
                }
            }
        }

        return array_values(array_unique($reachable));
    }

    /**
     * Documents over the size, with theirs.
     *
     * @param  array<string, string>  $documents  path => content
     * @return list<string>
     */
    public function oversized(array $documents, int $maxBytes): array
    {
        $oversized = [];
        foreach ($documents as $path => $content) {
            if (strlen($content) > $maxBytes) {
                $oversized[] = sprintf('%s: %d bytes', $path, strlen($content));
            }
        }

        return $oversized;
    }

    /** Lines of a text, the last one counted even without a final newline. */
    public function lineCount(string $content): int
    {
        if ($content === '') {
            return 0;
        }

        return substr_count($content, "\n") + (str_ends_with($content, "\n") ? 0 : 1);
    }

    /**
     * The `docs/…md` paths a document cites in inline code that lead to no document.
     *
     * @param  array<string, string>  $documents  path => content
     * @param  callable(string): bool  $isFile
     * @return list<string>
     */
    public function deadCitations(array $documents, callable $isFile): array
    {
        $dead = [];
        foreach ($documents as $document => $content) {
            preg_match_all('/`(docs\/[\w.\/-]+\.md)`/', $content, $cited);
            foreach (array_unique($cited[1]) as $path) {
                if (! $isFile($path)) {
                    $dead[] = "{$document} → {$path}";
                }
            }
        }

        return $dead;
    }

    /**
     * The repository paths a document names in inline code — outside fenced blocks, which may show an
     * example or a file about to be created — that exist nowhere and that git does not ignore. A path
     * with a wildcard or a placeholder (`*`, `{locale}`, `<file>`) is a pattern, not a file.
     *
     * @param  array<string, string>  $documents  path => content
     * @param  string  $codeRoots  alternation of the roots a checked path starts with
     * @param  callable(string): bool  $exists
     * @param  callable(string): bool  $isIgnored
     * @return list<string>
     */
    public function absentNamedFiles(array $documents, string $codeRoots, callable $exists, callable $isIgnored): array
    {
        $absent = [];
        foreach ($documents as $document => $content) {
            preg_match_all('/`((?:'.$codeRoots.')\/[^`\s]+)`/', $this->withoutCodeBlocks($content), $named);
            foreach (array_unique($named[1]) as $path) {
                if (preg_match('/[*{<]/', $path) === 1) {
                    continue;
                }
                if (! $exists(rtrim($path, '/')) && ! $isIgnored($path)) {
                    $absent[] = "{$document} → {$path}";
                }
            }
        }

        return $absent;
    }

    /**
     * What code files point at in the documentation and does not exist: a path under docs/, or a
     * section of CLAUDE.md — its name, an em dash, then the heading in double quotes — that CLAUDE.md
     * does not have.
     *
     * @param  array<string, string>  $files  path => content
     * @param  callable(string): bool  $isFile
     * @return list<string>
     */
    public function deadCodeReferences(array $files, string $claudeMd, callable $isFile): array
    {
        $dead = [];
        foreach ($files as $file => $content) {
            preg_match_all('/\bdocs\/[\w.\/-]+\.md\b/', $content, $documents);
            foreach (array_unique($documents[0]) as $path) {
                if (! $isFile($path)) {
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

        return $dead;
    }

    /**
     * The links of a document, outside fenced blocks, that lead to no file or to no heading of it.
     * Web and mail links are not followed.
     *
     * @param  array<string, string>  $documents  path => content
     * @param  callable(string): ?string  $read  the content of a repository file, null when there is none
     * @return list<string>
     */
    public function brokenLinks(array $documents, callable $read): array
    {
        $broken = [];
        foreach ($documents as $document => $content) {
            preg_match_all('/\[[^\]]*\]\(([^)\s]+)\)/', $this->withoutCodeBlocks($content), $links);
            foreach (array_unique($links[1]) as $target) {
                if (preg_match('/^(https?:|mailto:)/', $target) === 1) {
                    continue;
                }
                [$path, $fragment] = array_pad(explode('#', $target, 2), 2, '');
                $file = $path === '' ? $document : $this->resolve(dirname($document), $path);
                $targetContent = $file === null ? null : ($file === $document ? $content : $read($file));

                if ($targetContent === null) {
                    $broken[] = "{$document} → {$target} (no such file)";

                    continue;
                }
                if ($fragment !== '' && ! in_array($fragment, $this->anchorsOf($targetContent), true)) {
                    $broken[] = "{$document} → {$target} (no such heading in {$file})";
                }
            }
        }

        return $broken;
    }

    /**
     * The classes of a family the documentation never names.
     *
     * @param  array<string, list<string>>  $families  family directory => class names
     * @return list<string>
     */
    public function undocumentedClasses(array $families, string $documentation): array
    {
        $undocumented = [];
        foreach ($families as $family => $classes) {
            foreach ($classes as $class) {
                if (! str_contains($documentation, $class)) {
                    $undocumented[] = $family.'/'.$class;
                }
            }
        }

        return $undocumented;
    }

    /**
     * Resolves a relative link against the directory of the document holding it, as GitHub does.
     * Returns null for a path that climbs out of the repository.
     */
    public function resolve(string $directory, string $path): ?string
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
     * The anchors GitHub generates for a document's headings: lower-cased, stripped of everything but
     * letters, digits, spaces, hyphens and underscores, spaces turned into hyphens, and a -1, -2…
     * suffix on repeats. "Playwright — dedicated E2E database" gives "playwright--dedicated-e2e-database":
     * the dash goes, both spaces stay.
     *
     * @return list<string>
     */
    public function anchorsOf(string $markdown): array
    {
        preg_match_all('/^#{1,6}\s+(.+?)\s*#*\s*$/m', $this->withoutCodeBlocks($markdown), $headings);

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

    public function withoutCodeBlocks(string $markdown): string
    {
        return (string) preg_replace('/^```.*?^```/ms', '', $markdown);
    }
}
