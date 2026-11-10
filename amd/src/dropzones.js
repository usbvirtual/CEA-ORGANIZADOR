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
 * Drop rules of the organiser (pure: no DOM, no Moodle modules).
 *
 * A dragged or target item is described as {type: 'category'|'course', id}; a
 * course also carries the id of its category.
 *
 * @module     tool_ceaorganizador/dropzones
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Zone of the target row under the pointer.
 *
 * @param {Number} offsetY Pointer position inside the row.
 * @param {Number} height Height of the row.
 * @param {String} targetKind 'category' (thirds) or 'course' (halves).
 * @returns {String} 'before', 'inside' or 'after'.
 */
export const zoneFor = (offsetY, height, targetKind) => {
    const ratio = height > 0 ? offsetY / height : 0;
    if (targetKind === 'category') {
        if (ratio < 1 / 3) {
            return 'before';
        }
        return ratio < 2 / 3 ? 'inside' : 'after';
    }
    return ratio < 0.5 ? 'before' : 'after';
};

/**
 * Whether the dragged item may be dropped on that zone of the target.
 *
 * @param {Object} model Model from treemodel.createModel().
 * @param {Object} dragged
 * @param {Object} target
 * @param {String} zone
 * @returns {Boolean}
 */
export const canDrop = (model, dragged, target, zone) => {
    if (dragged.type === target.type && dragged.id === target.id) {
        return false;
    }
    if (dragged.type === 'category') {
        // Categories go among categories only, and never into themselves or their descendants.
        return target.type === 'category' && !model.isDescendant(dragged.id, target.id);
    }
    if (target.type === 'course') {
        return zone !== 'inside';
    }
    if (zone === 'inside') {
        return true;
    }
    // Before or after a category means its parent, and courses cannot live at the root.
    const row = model.get(target.id);
    return Boolean(row) && row.parent !== 0;
};

const previous = (list, id) => {
    const index = list.indexOf(id);
    return index > 0 ? list[index - 1] : 0;
};

// Position for 'before'/'after' of a sibling in the list without the dragged item.
const afterFor = (list, targetid, zone) => (zone === 'after' ? targetid : previous(list, targetid));

/**
 * Where the dragged item ends up: {parent, afterid} for a category, {category, afterid} for a course.
 *
 * @param {Object} model
 * @param {Object} dragged
 * @param {Object} target
 * @param {String} zone
 * @param {Function} [siblingsOf] Receives a category id and returns the ids of its courses in order.
 * @returns {Object|null} The destination, or null when it is not valid or the item is already there.
 */
export const destinationFor = (model, dragged, target, zone, siblingsOf = () => []) => {
    if (!canDrop(model, dragged, target, zone)) {
        return null;
    }
    if (dragged.type === 'category') {
        const current = model.get(dragged.id);
        const currentlist = model.children(current.parent).map((row) => row.id);
        let dest;
        if (zone === 'inside') {
            dest = {parent: target.id, afterid: 0};
        } else {
            const parent = model.get(target.id).parent;
            const list = model.children(parent).map((row) => row.id).filter((id) => id !== dragged.id);
            dest = {parent, afterid: afterFor(list, target.id, zone)};
        }
        const same = dest.parent === current.parent && dest.afterid === previous(currentlist, dragged.id);
        return same ? null : dest;
    }
    let category;
    let afterid = 0;
    if (target.type === 'course') {
        category = target.category;
        const list = siblingsOf(category).filter((id) => id !== dragged.id);
        afterid = afterFor(list, target.id, zone);
    } else if (zone === 'inside') {
        category = target.id;
    } else {
        category = model.get(target.id).parent;
    }
    const currentlist = siblingsOf(category);
    const already = dragged.category === category && currentlist.includes(dragged.id)
        && previous(currentlist, dragged.id) === afterid;
    return already ? null : {category, afterid};
};

/**
 * Scroll speed for a pointer near the edge of a scroll area.
 *
 * @param {Number} position Pointer coordinate.
 * @param {Number} start Start edge of the area.
 * @param {Number} end End edge of the area.
 * @param {Number} [edge=40] Distance from the edge where scrolling starts.
 * @param {Number} [max=20] Pixels per step at the very edge.
 * @returns {Number} Negative to scroll back, positive forward, 0 when away from the edges.
 */
export const scrollDelta = (position, start, end, edge = 40, max = 20) => {
    if (position < start + edge) {
        return -Math.ceil(max * Math.min(1, (start + edge - position) / edge));
    }
    if (position > end - edge) {
        return Math.ceil(max * Math.min(1, (position - (end - edge)) / edge));
    }
    return 0;
};
