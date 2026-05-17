/**
 * @typedef {{ v: string, _key: number }} Tech
 * @typedef {{ icon: string, name: string, techs: Tech[], _key: number }} Category
 */

/**
 * @param {string[]} techs
 * @param {function(): number} nextKey
 * @returns {Tech[]}
 */
export function parseTechs(techs, nextKey) {
    return techs.map((v) => ({ v, _key: nextKey() }));
}

/**
 * Parses the JSON string from the `skills` translatable column into the
 * internal Category representation used by the editor (techs as objects
 * with stable `_key` for vuedraggable).
 *
 * @param {string} json
 * @param {function(): number} nextKey
 * @returns {Category[]}
 */
export function parseSkills(json, nextKey) {
    /** @type {Array<{ icon?: string, name?: string, techs?: string[] }>} */
    const raw = JSON.parse(json);
    return raw.map((cat) => ({
        icon: cat.icon ?? '⬡',
        name: cat.name ?? '',
        techs: parseTechs(cat.techs ?? [], nextKey),
        _key: nextKey(),
    }));
}

/**
 * Serializes one Category back to the plain object stored in the DB
 * (strips `_key`, unwraps `v` from techs).
 *
 * @param {Category} cat
 * @returns {{ icon: string, name: string, techs: string[] }}
 */
export function serializeCategory(cat) {
    return { icon: cat.icon, name: cat.name, techs: cat.techs.map((tech) => tech.v) };
}

/**
 * @param {function(): number} nextKey
 * @returns {Category}
 */
export function createCategory(nextKey) {
    return { name: '', icon: '⬡', techs: [], _key: nextKey() };
}

/**
 * @param {function(): number} nextKey
 * @returns {Tech}
 */
export function createTech(nextKey) {
    return { v: '', _key: nextKey() };
}
