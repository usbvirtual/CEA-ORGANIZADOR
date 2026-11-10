// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * In-memory model of the category tree (pure: no DOM, no Moodle modules).
 *
 * @module     tool_ceaorganizador/treemodel
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SEPARATOR = ' / ';

/**
 * Lower-cases and strips the diacritics, to compare names loosely.
 *
 * @param {String} text
 * @returns {String}
 */
export const normalize = (text) => String(text === null || text === undefined ? '' : text)
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase();

/**
 * Builds the model from the categories returned by get_tree.
 *
 * @param {Object[]} rows Categories (id, parent, name, sortorder, visible, coursecount, needsort...).
 * @returns {Object} The model.
 */
export const createModel = (rows) => {
    const byId = new Map();
    // Ordered ids of the children of each parent; 0 is the root.
    const kids = new Map();

    const kidsOf = (parent) => {
        if (!kids.has(parent)) {
            kids.set(parent, []);
        }
        return kids.get(parent);
    };

    rows.forEach((row) => byId.set(row.id, {...row}));
    [...byId.values()]
        .sort((a, b) => (a.sortorder - b.sortorder) || (a.id - b.id))
        .forEach((row) => kidsOf(row.parent).push(row.id));

    const get = (id) => byId.get(id) || null;

    const children = (id) => (kids.get(id) || []).map((childid) => byId.get(childid));

    const pathOf = (id) => {
        const names = [];
        let row = get(id);
        while (row) {
            names.unshift(row.name);
            row = get(row.parent);
        }
        return names;
    };

    /**
     * Whether candidate lies inside ancestor (at any depth).
     *
     * @param {Number} ancestorid
     * @param {Number} candidateid
     * @returns {Boolean}
     */
    const isDescendant = (ancestorid, candidateid) => {
        let row = get(candidateid);
        while (row) {
            if (row.parent === ancestorid) {
                return true;
            }
            row = get(row.parent);
        }
        return false;
    };

    const depthOf = (id) => pathOf(id).length;

    /**
     * Ids matching the query by name or path, plus their ancestors.
     *
     * @param {String} query
     * @returns {Set<Number>}
     */
    const filter = (query) => {
        const needle = normalize(query).trim();
        const visible = new Set();
        byId.forEach((row, id) => {
            // The path includes the names of the ancestors, so matching it
            // also covers the name.
            if (needle !== '' && !normalize(pathOf(id).join(SEPARATOR)).includes(needle)) {
                return;
            }
            let current = row;
            while (current && !visible.has(current.id)) {
                visible.add(current.id);
                current = get(current.parent);
            }
        });
        return visible;
    };

    /**
     * Moves a category in memory (optimistic update).
     *
     * @param {Number} id Category to move.
     * @param {Number} newparent New parent, 0 for top level.
     * @param {Number} afterid Sibling it goes after, 0 for first.
     * @returns {Object} Descriptor {id, newparent, afterid} that undoes the move.
     */
    const applyMoveCategory = (id, newparent, afterid) => {
        const row = get(id);
        const oldsiblings = kidsOf(row.parent);
        const oldindex = oldsiblings.indexOf(id);
        const inverse = {id, newparent: row.parent, afterid: oldindex > 0 ? oldsiblings[oldindex - 1] : 0};
        oldsiblings.splice(oldindex, 1);
        const siblings = kidsOf(newparent);
        const at = afterid ? siblings.indexOf(afterid) + 1 : 0;
        siblings.splice(at, 0, id);
        row.parent = newparent;
        return inverse;
    };

    /**
     * Plain description of the structure, to compare models.
     *
     * @returns {Object[]} Rows in tree order with their derived path and depth.
     */
    const snapshot = () => {
        const out = [];
        const walk = (parent) => {
            kidsOf(parent).forEach((childid) => {
                const row = get(childid);
                out.push({
                    id: row.id,
                    parent: row.parent,
                    name: row.name,
                    path: pathOf(childid).join(SEPARATOR),
                    depth: depthOf(childid),
                });
                walk(childid);
            });
        };
        walk(0);
        return out;
    };

    return {children, get, pathOf, isDescendant, filter, applyMoveCategory, snapshot};
};
