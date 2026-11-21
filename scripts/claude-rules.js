// Claude Code rules — checks that each rule of docs/rules/ is loaded when, and only when, it should.
// Usage: node scripts/claude-rules.js [git tree]   (run on the repository by scripts/__tests__/claude-rules.test.js)
//
// The documentation has three tiers (LUMN-150, docs/rules/README.md): CLAUDE.md, loaded in every
// session; the rules of a domain, loaded when Claude reads or writes a file their `paths:` frontmatter
// names; and the references a rule links to, read when needed. A rule is a document of docs/rules/,
// exposed to Claude Code by a symbolic link in .claude/rules/ — never a copy, which would drift.
//
// None of this shows in a review. A copy instead of a link, a broken or misdirected link, a rule with
// no link, a frontmatter Claude Code cannot read — the rule then loads in every session —, a pattern
// that names no file — the rule then never loads: each passes unnoticed. The design comes from
// myFormasup (FORMASUP-2383), where it was proven first.
//
// Exit code: 0 when every rule is bound, 1 otherwise.

import { execFileSync } from 'node:child_process';
import { existsSync, lstatSync, readdirSync, readFileSync, realpathSync } from 'node:fs';
import { basename, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const RULES_DOCUMENTS = 'docs/rules';
export const RULES_LINKS = '.claude/rules';

/** The index of docs/rules/: a document, not a rule, and the one file of the directory without a link. */
export const RULES_INDEX = 'README.md';

/**
 * The patterns of a rule's frontmatter, in the one form this repository accepts: `paths:`, then one
 * pattern per line, in double quotes, indented by two spaces, Unix line endings. That subset is valid
 * YAML by construction. Anything else is refused: Claude Code ignores a frontmatter it cannot read, and
 * the rule then loads in every session.
 *
 * @param {string} markdown
 * @returns {string[]}
 */
export function rulePaths(markdown) {
    if (!markdown.startsWith('---')) {
        throw new Error('no frontmatter: the rule would never be loaded by path');
    }
    const frontmatter = /^---\npaths:\n((?: {2}- "[^"\\\n]+"\n)+)---(?:\n|$)/.exec(markdown);
    if (frontmatter === null) {
        throw new Error(
            'frontmatter not in the expected form: `paths:`, then one pattern per line in double quotes (`  - "app/**"`), Unix line endings',
        );
    }
    return [...frontmatter[1].matchAll(/"([^"]+)"/g)].map((match) => match[1]);
}

/**
 * The regular expression a Claude Code glob stands for: `**` crosses directories, `*` and `?` do not,
 * braces list variants, brackets a character class. An unclosed bracket makes the pattern invalid:
 * it then names no file, and null is returned.
 *
 * @param {string} glob
 * @returns {RegExp | null}
 */
export function globToRegExp(glob) {
    const body = globBody(glob);
    return body === null ? null : new RegExp(`^${body}$`, 'u');
}

/**
 * @param {string} glob
 * @returns {string | null}
 */
function globBody(glob) {
    const tokens = glob.match(/\*\*\/|\*\*|\*|\?|\{[^{}]*\}|\[!?[^\]]+\]|\\.|\[|[^*?{[\\]+|./gsu) ?? [];
    let body = '';
    for (const token of tokens) {
        const fragment = globFragment(token);
        if (fragment === null) {
            return null;
        }
        body += fragment;
    }
    return body;
}

/**
 * @param {string} token
 * @returns {string | null}
 */
function globFragment(token) {
    if (token === '**/') return '(?:.*/)?';
    if (token === '**') return '.*';
    if (token === '*') return '[^/]*';
    if (token === '?') return '[^/]';
    if (token === '[') return null;
    if (token.startsWith('{')) return braceBody(token.slice(1, -1));
    if (token.startsWith('[!')) return `[^${token.slice(2, -1)}]`;
    if (token.startsWith('[')) return token;
    if (token.startsWith('\\')) return escapeRegExp(token.slice(1));
    return escapeRegExp(token);
}

/**
 * @param {string} variants
 * @returns {string | null}
 */
function braceBody(variants) {
    const bodies = variants.split(',').map(globBody);
    return bodies.includes(null) ? null : `(?:${bodies.join('|')})`;
}

/** @param {string} text */
function escapeRegExp(text) {
    return text.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&');
}

/**
 * Every problem with the rules of a tree: documents without their link, links that are not links or
 * lead elsewhere, entries of .claude/rules/ that expose no document, frontmatters that cannot be read,
 * and patterns that name none of the given files.
 *
 * @param {string} root
 * @param {string[]} files repository-relative paths of the files the patterns may name
 * @returns {string[]}
 */
export function ruleProblems(root, files) {
    const documents = ruleDocuments(root);
    const problems = [];

    for (const document of documents) {
        const link = `${RULES_LINKS}/${basename(document)}`;
        if (!isSymlink(join(root, link))) {
            problems.push(`${document}: ${link} is not a symbolic link`);
        } else if (resolved(join(root, link)) !== resolved(join(root, document))) {
            problems.push(`${document}: ${link} leads to another file`);
        } else if (!files.includes(link)) {
            // A link made but never committed works on one machine, and nowhere else.
            problems.push(`${document}: ${link} is not tracked by git, so no other checkout would have it`);
        }

        let globs;
        try {
            globs = rulePaths(readFileSync(join(root, document), 'utf8'));
        } catch (error) {
            problems.push(`${document}: ${error.message}`);
            continue;
        }
        for (const glob of globs) {
            const pattern = globToRegExp(glob);
            if (pattern === null || !files.some((file) => pattern.test(file))) {
                problems.push(`${document}: "${glob}" names no file, so the rule would never load`);
            }
        }
    }

    const targets = new Set(documents.map((document) => resolved(join(root, document))));
    for (const entry of entries(join(root, RULES_LINKS))) {
        if (!targets.has(resolved(join(root, RULES_LINKS, entry)))) {
            problems.push(`${RULES_LINKS}/${entry}: exposes no document of ${RULES_DOCUMENTS}/`);
        }
    }

    return problems;
}

/**
 * @param {string} root
 * @returns {string[]} the rule documents of docs/rules/, its index excepted
 */
function ruleDocuments(root) {
    return entries(join(root, RULES_DOCUMENTS))
        .filter((name) => name.endsWith('.md') && name !== RULES_INDEX)
        .map((name) => `${RULES_DOCUMENTS}/${name}`);
}

/** @param {string} directory */
function entries(directory) {
    return existsSync(directory) ? readdirSync(directory).sort() : [];
}

/** @param {string} path */
function isSymlink(path) {
    try {
        return lstatSync(path).isSymbolicLink();
    } catch {
        return false;
    }
}

/**
 * The real path, or null for a path that does not resolve — a broken link resolves to nothing, and
 * so to no document.
 *
 * @param {string} path
 * @returns {string | null}
 */
function resolved(path) {
    try {
        return realpathSync(path);
    } catch {
        return null;
    }
}

/**
 * The files git tracks: what a pattern must name. A file that exists on one machine only — generated,
 * ignored — would make a pattern look alive here and dead in a fresh checkout.
 *
 * @param {string} root
 * @returns {string[]}
 */
export function trackedFiles(root) {
    return execFileSync('git', ['ls-files', '-z'], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    // The repository by default; another git tree when one is named, which is how the exit codes are tested.
    const root = process.argv[2] ?? fileURLToPath(new URL('..', import.meta.url));
    const problems = ruleProblems(root, trackedFiles(root));
    if (problems.length > 0) {
        console.error(
            `Claude Code rules that would not load as intended:\n\n${problems.map((p) => `• ${p}`).join('\n')}`,
        );
        process.exit(1);
    }
}
