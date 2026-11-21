// @vitest-environment node
import { afterEach, describe, expect, it } from 'vitest';
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { globToRegExp, ruleProblems, rulePaths, trackedFiles } from '../claude-rules.js';

/**
 * The rules of docs/rules/ reach Claude Code through symbolic links in .claude/rules/, and load when
 * a file their `paths:` frontmatter names is read or written (LUMN-150). Each detection is proven on
 * a temporary tree, where it must see the defects placed there and those only; the same detection
 * then runs on the repository.
 */

const RULE = '---\npaths:\n  - "app/**"\n---\n\n# Rule\n';
const repository = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

let root = null;

afterEach(() => {
    if (root !== null) {
        rmSync(root, { recursive: true, force: true });
        root = null;
    }
});

/**
 * @param {Record<string, string>} files path → content
 * @param {Record<string, string>} [links] path → relative target
 */
function tree(files, links = {}) {
    root = mkdtempSync(join(tmpdir(), 'claude-rules-'));
    for (const [path, content] of Object.entries(files)) {
        mkdirSync(dirname(join(root, path)), { recursive: true });
        writeFileSync(join(root, path), content);
    }
    for (const [path, target] of Object.entries(links)) {
        mkdirSync(dirname(join(root, path)), { recursive: true });
        symlinkSync(target, join(root, path));
    }
    return root;
}

describe('rulePaths: the one frontmatter form Claude Code is sure to read', () => {
    it.each([
        ['one pattern', '---\npaths:\n  - "app/**"\n---\n\n# Title', ['app/**']],
        [
            'several patterns',
            '---\npaths:\n  - "app/**"\n  - "resources/**/*.{vue,js}"\n---\n',
            ['app/**', 'resources/**/*.{vue,js}'],
        ],
        ['a frontmatter alone, with no final newline', '---\npaths:\n  - "app/**"\n---', ['app/**']],
    ])('reads %s', (_case, markdown, expected) => {
        expect(rulePaths(markdown)).toEqual(expected);
    });

    it.each([
        ['no frontmatter', '# Title\n', 'no frontmatter'],
        ['a block that does not open the file', '# Title\n\n---\npaths:\n  - "app/**"\n---\n', 'no frontmatter'],
        ['a frontmatter never closed', '---\npaths:\n  - "app/**"\n\n# Title\n', 'expected form'],
        ['no pattern at all', '---\npaths:\n---\n', 'expected form'],
        ['another key after paths', '---\npaths:\n  - "app/**"\ndescription: Rule\n---\n', 'expected form'],
        ['a pattern without quotes', '---\npaths:\n  - app/**\n---\n', 'expected form'],
        ['single quotes', "---\npaths:\n  - 'app/**'\n---\n", 'expected form'],
        ['a comment at the end of a line', '---\npaths:\n  - "app/**" # backend\n---\n', 'expected form'],
        ['the comma-separated form', '---\npaths: "app/**, tests/**"\n---\n', 'expected form'],
        ['another indentation', '---\npaths:\n    - "app/**"\n---\n', 'expected form'],
        ['Windows line endings', '---\r\npaths:\r\n  - "app/**"\r\n---\r\n', 'expected form'],
    ])('refuses %s, which Claude Code might ignore and load everywhere', (_case, markdown, reason) => {
        expect(() => rulePaths(markdown)).toThrow(reason);
    });
});

describe('globToRegExp: what a pattern names', () => {
    it.each([
        ['** crosses directories', 'app/**', 'app/Services/Mail/MailService.php', true],
        ['** stays under its directory', 'app/**', 'apps/Service.php', false],
        ['**/ covers no directory', 'resources/**/*.vue', 'resources/App.vue', true],
        ['**/ covers several directories', 'resources/**/*.vue', 'resources/js/components/Boot.vue', true],
        ['* stops at a slash', 'scripts/*.js', 'scripts/__tests__/a.test.js', false],
        ['* matches within a name', 'scripts/*.js', 'scripts/check.js', true],
        ['? is one character, never a slash', 'a?c', 'a/c', false],
        ['? is one character', 'a?c', 'abc', true],
        ['the extension is respected', 'resources/**/*.vue', 'resources/js/app.js', false],
        ['a dot is a dot', 'a.b', 'axb', false],
        ['braces: one of the variants', 'resources/**/*.{vue,js}', 'resources/js/app.js', true],
        ['braces: none of the variants', 'resources/**/*.{vue,js}', 'resources/js/app.ts', false],
        ['a character class', 'file[12].md', 'file2.md', true],
        ['a negated class', 'file[!12].md', 'file1.md', false],
        ['an escaped bracket', 'photos \\[2024/**', 'photos [2024/a.jpg', true],
        ['the whole path, not a part of it', 'app/**', 'src/app/x.php', false],
        ['the end of the name, not a beginning of it', 'scripts/*.js', 'scripts/check.json', false],
    ])('%s', (_case, glob, path, expected) => {
        expect(globToRegExp(glob).test(path)).toBe(expected);
    });

    it.each([['photos [2024/**'], ['{a,[b}']])('names nothing for the invalid pattern %j', (glob) => {
        expect(globToRegExp(glob)).toBeNull();
    });
});

describe('ruleProblems, on a temporary tree', () => {
    const files = ['app/Service.php', 'tests/Unit/ServiceTest.php'];

    it('finds nothing wrong with a rule linked, readable and alive, and leaves the index alone', () => {
        const at = tree(
            { 'docs/rules/backend.md': RULE, 'docs/rules/README.md': '# Rules\n' },
            { '.claude/rules/backend.md': '../../docs/rules/backend.md' },
        );

        expect(ruleProblems(at, [...files, '.claude/rules/backend.md'])).toEqual([]);
    });

    it('finds nothing in a tree without rules', () => {
        expect(ruleProblems(tree({ 'docs/other.md': '# Other\n' }), files)).toEqual([]);
    });

    it('sees each defect placed in the tree, and those only', () => {
        const at = tree(
            {
                'docs/rules/ok.md': RULE,
                'docs/rules/unlinked.md': RULE,
                'docs/rules/copied.md': RULE,
                '.claude/rules/copied.md': RULE,
                'docs/rules/misdirected.md': RULE,
                'docs/rules/unreadable.md': '# A rule without frontmatter\n',
                'docs/rules/dead.md': '---\npaths:\n  - "app/**"\n  - "nowhere/**"\n---\n',
                'docs/rules/invalid.md': '---\npaths:\n  - "app/[**"\n---\n',
                'docs/rules/uncommitted.md': RULE,
                'docs/stray.md': '# Not a rule\n',
            },
            {
                '.claude/rules/ok.md': '../../docs/rules/ok.md',
                '.claude/rules/misdirected.md': '../../docs/rules/ok.md',
                '.claude/rules/unreadable.md': '../../docs/rules/unreadable.md',
                '.claude/rules/dead.md': '../../docs/rules/dead.md',
                '.claude/rules/invalid.md': '../../docs/rules/invalid.md',
                '.claude/rules/broken.md': '../../docs/rules/gone.md',
                '.claude/rules/stray.md': '../../docs/stray.md',
                '.claude/rules/uncommitted.md': '../../docs/rules/uncommitted.md',
            },
        );
        const tracked = [
            ...files,
            ...['ok', 'unreadable', 'dead', 'invalid'].map((name) => `.claude/rules/${name}.md`),
        ];

        expect(ruleProblems(at, tracked)).toEqual([
            'docs/rules/copied.md: .claude/rules/copied.md is not a symbolic link',
            'docs/rules/dead.md: "nowhere/**" names no file, so the rule would never load',
            'docs/rules/invalid.md: "app/[**" names no file, so the rule would never load',
            'docs/rules/misdirected.md: .claude/rules/misdirected.md leads to another file',
            'docs/rules/uncommitted.md: .claude/rules/uncommitted.md is not tracked by git, so no other checkout would have it',
            'docs/rules/unlinked.md: .claude/rules/unlinked.md is not a symbolic link',
            expect.stringMatching(/^docs\/rules\/unreadable\.md: no frontmatter/),
            '.claude/rules/broken.md: exposes no document of docs/rules/',
            '.claude/rules/copied.md: exposes no document of docs/rules/',
            '.claude/rules/stray.md: exposes no document of docs/rules/',
        ]);
    });
});

describe('run as a command, on a git tree', () => {
    const script = join(repository, 'scripts/claude-rules.js');

    /** @param {string} at */
    function committed(at) {
        execFileSync('git', ['init', '-q'], { cwd: at });
        execFileSync('git', ['add', '-A'], { cwd: at });
        return at;
    }

    it('exits 0 when every rule is bound, reading the patterns against the files git tracks', () => {
        const at = committed(
            tree(
                { 'docs/rules/backend.md': RULE, 'app/Service.php': '<?php\n' },
                {
                    '.claude/rules/backend.md': '../../docs/rules/backend.md',
                },
            ),
        );

        const run = spawnSync('node', [script, at], { encoding: 'utf8' });

        expect(run.status).toBe(0);
        expect(run.stderr).toBe('');
    });

    it('exits 1 and names each problem, a pattern that only an untracked file would match among them', () => {
        const at = tree(
            { 'docs/rules/backend.md': RULE, 'docs/rules/unlinked.md': RULE },
            {
                '.claude/rules/backend.md': '../../docs/rules/backend.md',
            },
        );
        committed(at);
        // On disk, but not tracked: a fresh checkout would not have it, and the rule would never load there.
        writeFileSync(join(at, 'app.php'), '');
        mkdirSync(join(at, 'app'));
        writeFileSync(join(at, 'app/Untracked.php'), '<?php\n');

        const run = spawnSync('node', [script, at], { encoding: 'utf8' });

        expect(run.status).toBe(1);
        expect(run.stderr).toContain('• docs/rules/backend.md: "app/**" names no file');
        expect(run.stderr).toContain('• docs/rules/unlinked.md: .claude/rules/unlinked.md is not a symbolic link');
    });
});

describe('the repository', () => {
    it('has every rule of docs/rules/ bound to Claude Code, and nothing else in .claude/rules/', () => {
        expect(ruleProblems(repository, trackedFiles(repository))).toEqual([]);
    });
});
