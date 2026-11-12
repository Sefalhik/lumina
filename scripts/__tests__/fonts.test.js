// @vitest-environment node
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// The site serves its own fonts from Fontsource packages (LUMN-102). What the browser renders is
// proven by tests/e2e/fonts.spec.js; this proves the seams it relies on, where a mistake fails
// silently: a family the stylesheets name that no imported package declares renders in the
// fallback font, and nothing says so. It lives here rather than in PHPUnit because it reads
// node_modules, which only the JS job of CI installs.
const inRepository = (path) => fileURLToPath(new URL(`../../${path}`, import.meta.url));
const read = (path) => readFileSync(inRepository(path), 'utf8');

/**
 * The generic families of CSS Fonts 4, and the keywords a font-family may hold. A generic is always
 * available: the families written after it are never the one a page renders — only a fallback for
 * the characters it lacks, such as an emoji font. A system stack (`ui-sans-serif, system-ui, …`)
 * therefore promises no font the site must serve.
 */
const GENERIC = new Set([
    'serif',
    'sans-serif',
    'monospace',
    'cursive',
    'fantasy',
    'system-ui',
    'math',
    'emoji',
    'fangsong',
    'ui-serif',
    'ui-sans-serif',
    'ui-monospace',
    'ui-rounded',
    'inherit',
    'initial',
    'unset',
    'revert',
    'revert-layer',
]);

/**
 * What app.css imports from Fontsource: a whole package (`@fontsource/vt323`, its index.css) or one
 * of its files (`@fontsource/space-mono/700.css`) — a package whose index.css ships the regular weight
 * alone needs its bold imported by file (LUMN-103).
 */
const fontImports = () =>
    [...read('resources/css/app.css').matchAll(/^@import\s+"(@fontsource[^"]+)";/gm)].map((m) => m[1]);

/** The package an import comes from: its scope and name, without the file. */
export const packageOf = (specifier) => specifier.split('/').slice(0, 2).join('/');

/** The stylesheet an import loads: the file it names, or the package's index.css. */
export const stylesheetOf = (specifier) =>
    specifier.split('/').length > 2 ? `node_modules/${specifier}` : `node_modules/${specifier}/index.css`;

const importedPackages = () => [...new Set(fontImports().map(packageOf))];

const declaredFamilies = () =>
    new Set(
        fontImports().flatMap((specifier) =>
            [...read(stylesheetOf(specifier)).matchAll(/font-family:\s*'([^']+)'/g)].map((m) => m[1]),
        ),
    );

const filesUnder = (directory, extension) =>
    readdirSync(inRepository(directory), { recursive: true })
        .filter((name) => name.endsWith(extension))
        .map((name) => `${directory}/${name}`);

/**
 * The families a page relies on, in every font-family declaration or --font-* variable — quoted or
 * not, in a stylesheet or an inline style attribute: those written before the first generic family.
 * A var() keeps its fallback, the only family it names.
 */
export const familiesIn = (source) =>
    [...source.matchAll(/(?:font-family|--font-[a-z]+)\s*:\s*([^;}\n<>]+)/g)].flatMap((declaration) => {
        const stack = declaration[1]
            .replace(/!important/g, '')
            .replace(/var\(\s*--[\w-]+\s*,?/g, '')
            .replace(/\)/g, '')
            .split(',')
            .map((family) =>
                family
                    .trim()
                    .replace(/^["']+|["']+$/g, '')
                    .trim(),
            )
            .filter((family) => family !== '' && !family.startsWith('--'));
        const generic = stack.findIndex((family) => GENERIC.has(family));

        return generic === -1 ? stack : stack.slice(0, generic);
    });

/**
 * Laravel's own error layout, copied by LUMN-37 only to stamp the CSP nonce: its system font stacks
 * (normalize.css, Tailwind's preflight) name fonts installed on the visitor's machine, which no
 * package serves on purpose. LUMN-105 replaces it with the site's error pages — and deletes it, at
 * which point the test below fails and this exclusion goes with it.
 */
const NOT_THE_SITES_CODE = ['resources/views/errors/minimal.blade.php'];

const usedFamilies = () =>
    new Set(
        [
            ...filesUnder('resources/css', '.scss'),
            ...filesUnder('resources/css', '.css'),
            ...filesUnder('resources/js', '.vue'),
            ...filesUnder('resources/views', '.blade.php'),
        ]
            .filter((file) => !NOT_THE_SITES_CODE.includes(file))
            .flatMap((file) => familiesIn(read(file))),
    );

describe('packageOf and stylesheetOf', () => {
    it('read a whole package as its index.css', () => {
        expect(packageOf('@fontsource/vt323')).toBe('@fontsource/vt323');
        expect(stylesheetOf('@fontsource/vt323')).toBe('node_modules/@fontsource/vt323/index.css');
    });

    it('read a file of a package as that file, from that package', () => {
        expect(packageOf('@fontsource/space-mono/700.css')).toBe('@fontsource/space-mono');
        expect(stylesheetOf('@fontsource/space-mono/700.css')).toBe('node_modules/@fontsource/space-mono/700.css');
    });
});

describe('familiesIn', () => {
    it('reads quoted families from declarations and --font-* variables, without the generic ones', () => {
        const source = `
            --font-display: 'Orbitron Variable', monospace;
            .x { font-family: "VT323", serif; }
            .y { font-family: var(--font-body, 'Share Tech Mono', monospace); }
            .z { content: 'not a font'; }
        `;

        expect(familiesIn(source)).toEqual(['Orbitron Variable', 'VT323', 'Share Tech Mono']);
    });

    it('reads unquoted families, which CSS allows and a quote-only reader would let through', () => {
        expect(familiesIn('.x { font-family: Orbitron, Share Tech Mono, monospace; }')).toEqual([
            'Orbitron',
            'Share Tech Mono',
        ]);
    });

    it('reads an inline style attribute, up to the end of the attribute', () => {
        expect(familiesIn(`<p style="font-family: 'Orbitron'">Text</p>`)).toEqual(['Orbitron']);
        expect(familiesIn(`<p style="color: red; font-family: Cinzel">Text</p>`)).toEqual(['Cinzel']);
    });

    it('names nothing for a var() without fallback, a generic family or !important', () => {
        expect(familiesIn('.x { font-family: var(--font-body); }')).toEqual([]);
        expect(familiesIn('.x { font-family: monospace !important; }')).toEqual([]);
        expect(familiesIn(".x { font-family: 'VT323' !important; }")).toEqual(['VT323']);
    });

    it('stops at the first generic family: a system stack promises nothing the site must serve', () => {
        expect(
            familiesIn('body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto; }'),
        ).toEqual([]);
        expect(familiesIn(".x { font-family: 'Share Tech Mono', monospace, 'Apple Color Emoji'; }")).toEqual([
            'Share Tech Mono',
        ]);
    });

    it('reads one declaration per line, never across lines', () => {
        expect(familiesIn(`.x { font-family: 'VT323'\n}\n.y { content: 'Orbitron'; }`)).toEqual(['VT323']);
    });
});

describe('the fonts', () => {
    it('are checked everywhere but in files that still exist, so that an exclusion never outlives its file', () => {
        for (const file of NOT_THE_SITES_CODE) {
            expect(() => read(file), `${file} is gone: remove it from NOT_THE_SITES_CODE`).not.toThrow();
        }
    });

    it('named by the stylesheets are all declared by an imported package', () => {
        const used = usedFamilies();
        // The four of today: a reader that found nothing would let the next check pass unchecked.
        expect(used.size).toBeGreaterThanOrEqual(4);

        const declared = declaredFamilies();
        // A variable font is named after its package: 'Orbitron Variable', not 'Orbitron'.
        expect([...used].filter((family) => !declared.has(family))).toEqual([]);
    });

    it('are imported from packages listed with what ships to the browser, with a caret', () => {
        const packages = importedPackages();
        expect(packages).toHaveLength(5);

        // In dependencies, beside vue, tailwindcss and daisyui: what reaches the browser. And a caret,
        // as for every npm package of the project.
        const { dependencies } = JSON.parse(read('package.json'));
        for (const name of packages) {
            expect(dependencies[name], `${name} is imported but not a dependency`).toMatch(/^\^/);
        }
    });
});
