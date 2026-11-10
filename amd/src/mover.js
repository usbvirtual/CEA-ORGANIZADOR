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
 * The single move pipeline: optimistic update, web service, undo notice and revert.
 *
 * Drag and drop, keyboard and the "Move to..." dialog all end here. It imports
 * no Moodle module: everything it needs arrives in the context.
 *
 * @module     tool_ceaorganizador/mover
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {canDrop, destinationFor} from 'tool_ceaorganizador/dropzones';

const ASSEMBLE = 'tool-ceaorganizador-ensamblar';

/**
 * Moves a course in the loaded lists and the counters of the model (no request).
 *
 * @param {Object} ctx Context of createMover().
 * @param {Number} id Course.
 * @param {Number} category Destination category.
 * @param {Number} afterid Course it goes after, 0 for first.
 * @param {Object} [known] The course, when it is in no loaded list any more (to revert).
 * @returns {Object|null} {category, afterid, course} that undoes it; null if the course is unknown.
 */
const moveCourseLocal = (ctx, id, category, afterid, known = null) => {
    const cache = ctx.state.cache;
    let course = null;
    let current = 0;
    let previous = 0;
    cache.forEach((entry, categoryid) => {
        const index = entry.items.findIndex((item) => item.id === id);
        if (course === null && index >= 0) {
            course = entry.items[index];
            current = categoryid;
            previous = index > 0 ? entry.items[index - 1].id : 0;
            entry.items.splice(index, 1);
            entry.total -= 1;
        }
    });
    if (course === null) {
        if (!known) {
            return null;
        }
        course = known;
        current = known.category;
    }
    const inverse = {category: current, afterid: previous, course};
    const count = (categoryid, delta) => {
        const row = ctx.model.get(categoryid);
        if (row) {
            row.coursecount = Math.max(0, (row.coursecount || 0) + delta);
        }
    };
    count(current, -1);
    count(category, 1);
    course.category = category;
    const to = cache.get(category);
    if (to) {
        const at = afterid ? to.items.findIndex((item) => item.id === afterid) + 1 : 0;
        if (afterid && at === 0) {
            // The sibling is on a page that is not loaded: the list is fetched again when needed.
            cache.delete(category);
        } else {
            to.items.splice(at, 0, course);
            to.total += 1;
        }
    }
    return inverse;
};

/**
 * Builds the pipeline.
 *
 * @param {Object} ctx
 * @param {Object} ctx.model Current model (read on every move, so it may be replaced).
 * @param {Object} ctx.api moveCategory, moveCourse and undo.
 * @param {Object} ctx.state State of the tree view; its cache holds the loaded courses.
 * @param {Object} ctx.view Object with refresh().
 * @param {Object} ctx.strings moved, undo and error_generic.
 * @param {Object} ctx.toast Object with show(message, options).
 * @param {Function} ctx.onError Receives the exceptions of the web services.
 * @param {Function} ctx.reload Reloads the tree from the server.
 * @param {Element} [ctx.container] Tree element, to animate and focus the moved node.
 * @returns {{move: Function, moveInside: Function}}
 */
export const createMover = (ctx) => {
    const settle = (dragged, focus) => {
        if (!ctx.container) {
            return;
        }
        // The courses are drawn a moment after the refresh.
        setTimeout(() => {
            const node = ctx.container.querySelector(`[data-type="${dragged.type}"][data-id="${dragged.id}"]`);
            if (!node) {
                return;
            }
            node.classList.add(ASSEMBLE);
            node.addEventListener('animationend', () => node.classList.remove(ASSEMBLE), {once: true});
            if (focus) {
                node.focus();
            }
        }, 0);
    };

    const undoMove = async(logid) => {
        try {
            const result = await ctx.api.undo(logid);
            if (result.ok) {
                await ctx.reload();
            } else {
                ctx.toast.show(result.conflicts.length ? result.conflicts.join(' ') : ctx.strings.error_generic);
            }
        } catch (error) {
            ctx.onError(error);
        }
    };

    /**
     * Moves an item to a destination from destinationFor().
     *
     * @param {Object} dragged {type, id} (a course also its category).
     * @param {Object} dest {parent, afterid} or {category, afterid}.
     * @param {Object} [options]
     * @param {Boolean} [options.focus] Give the moved node the focus afterwards.
     * @returns {Promise<Object|null>} The web service result; null if it failed or nothing moved.
     */
    const move = async(dragged, dest, options = {}) => {
        let request;
        let revert;
        if (dragged.type === 'category') {
            const model = ctx.model;
            const inverse = model.applyMoveCategory(dragged.id, dest.parent, dest.afterid);
            request = () => ctx.api.moveCategory(dragged.id, dest.parent, dest.afterid);
            revert = () => model.applyMoveCategory(inverse.id, inverse.newparent, inverse.afterid);
        } else {
            const inverse = moveCourseLocal(ctx, dragged.id, dest.category, dest.afterid);
            if (!inverse) {
                return null;
            }
            request = () => ctx.api.moveCourse(dragged.id, dest.category, dest.afterid);
            revert = () => moveCourseLocal(ctx, dragged.id, inverse.category, inverse.afterid, inverse.course);
        }
        ctx.view.refresh();
        let result;
        try {
            result = await request();
        } catch (error) {
            revert();
            ctx.view.refresh();
            ctx.onError(error);
            return null;
        }
        settle(dragged, options.focus);
        ctx.toast.show(ctx.strings.moved, {
            actionLabel: ctx.strings.undo,
            onAction: () => undoMove(result.logid),
        });
        return result;
    };

    /**
     * Moves an item to the first position of a category (the "Move to..." dialog).
     *
     * @param {Object} dragged
     * @param {Number} categoryid
     * @returns {Promise<Object|null>}
     */
    const moveInside = async(dragged, categoryid) => {
        const target = {type: 'category', id: categoryid};
        if (!canDrop(ctx.model, dragged, target, 'inside')) {
            return null;
        }
        const dest = destinationFor(ctx.model, dragged, target, 'inside', ctx.siblingsOf);
        return dest ? move(dragged, dest, {focus: true}) : null;
    };

    return {move, moveInside};
};
