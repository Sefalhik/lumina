// Loads module — tells whether a JavaScript source loads a given package (LUMN-152).
// Imported by scripts/__tests__/advisory-premises.test.js.
//
// Two tolerated advisories (accepted-advisories.json) rest on a fact: @vue/test-utils bundles the code
// of js-beautify and never loads the installed package, whose dependencies carry the flaws. A later
// @vue/test-utils that loaded it would make both reasons false without a word. This reads the code
// only: a `require('js-beautify')` inside a comment — js-beautify's own documentation, bundled with it —
// loads nothing.

/**
 * The source with its comments blanked out, string literals kept: a comment marker inside a string is
 * not a comment, and a string is where a module name is written.
 *
 * @param {string} source
 * @returns {string}
 */
export function withoutComments(source) {
    return source.replace(
        /("(?:\\.|[^"\\\n])*"|'(?:\\.|[^'\\\n])*'|`(?:\\.|[^`\\])*`)|\/\*[\s\S]*?\*\/|\/\/[^\n]*/g,
        (match, literal) => literal ?? ' ',
    );
}

/**
 * Whether the source loads the package — `require('name')`, `import … from 'name'`, `import('name')`
 * or `import 'name'` — or one of its files, `name/…`. Another package whose name starts the same way
 * does not count.
 *
 * @param {string} source
 * @param {string} name
 * @returns {boolean}
 */
export function loadsModule(source, name) {
    const quoted = `(['"\`])${name.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')}(?:\\/[^'"\`]*)?\\1`;
    // One expression per form, each with its own quote group: in a single alternation, `\1` would point
    // at the first form's group, and a group that took no part matches the empty string — a closing
    // quote that differs from the opening one would then pass.
    const forms = [`\\brequire\\s*\\(\\s*`, `\\bimport\\s*\\(\\s*`, `\\bfrom\\s*`, `\\bimport\\s+`];
    const code = withoutComments(source);
    return forms.some((form) => new RegExp(form + quoted).test(code));
}
