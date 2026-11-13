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
 * Show and hide courses: the request, the "Hidden" labels in place and the undo notice.
 *
 * It imports no Moodle module: everything it needs arrives in the options.
 *
 * @module     tool_ceaorganizador/visibility
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setCourseVisible} from 'tool_ceaorganizador/treeview';
import {exclusive} from 'tool_ceaorganizador/selection';
import {splitBatches} from 'tool_ceaorganizador/renamepanel';

/** @var {Number} Most courses per request; the server refuses more (set_visibility::MAXITEMS). */
export const MAX_BATCH = 100;

/**
 * Ids of the courses among the items of a selection, in their order.
 *
 * @param {Object[]} items Items {type, id}, as selection.items() gives them.
 * @returns {Number[]}
 */
export const selectedCourseIds = (items) => items.filter((item) => item.type === 'course').map((item) => item.id);

/**
 * Builds the action.
 *
 * @param {Object} options
 * @param {Object} options.api Object with setVisibility(courseids, visible).
 * @param {Object} options.state State of the tree view; its cache holds the loaded courses.
 * @param {Element} options.container Tree element.
 * @param {Object} options.strings hidden, visibilitychanged and undo.
 * @param {Object} options.toast Object with show(message, options).
 * @param {Function} options.undo Undoes a journal entry (mover.undo), which reloads the tree.
 * @param {Function} options.onError Receives the exceptions of the web services.
 * @param {Function} [options.isBusy] Whether another write is running (the mover); then nothing is sent.
 * @returns {{apply: Function}}
 */
export const createVisibility = ({api, state, container, strings, toast, undo, onError, isBusy = () => false}) => {
    // Keeps the loaded lists in step, so a later redraw of the tree shows the same labels.
    const remember = (ids, visible) => {
        const wanted = new Set(ids);
        state.cache.forEach((entry) => {
            entry.items.forEach((course) => {
                if (wanted.has(course.id)) {
                    course.visible = visible;
                }
            });
        });
    };

    // One undo that goes back through the batches, the last one first, and stops at the first conflict.
    const undoAll = async(logids) => {
        for (const logid of [...logids].reverse()) {
            const result = await undo(logid);
            if (!result || !result.ok) {
                return;
            }
        }
    };

    // Batches of MAX_BATCH, one after another; one notice and one undo for all of them.
    const run = exclusive(async(ids, visible, trigger) => {
        const button = trigger && trigger.tagName === 'BUTTON' ? trigger : null;
        if (button) {
            button.disabled = true;
        }
        const logids = [];
        const done = [];
        let failure = null;
        try {
            for (const batch of splitBatches(ids, MAX_BATCH)) {
                const result = await api.setVisibility(batch, visible);
                if (result.logid > 0) {
                    logids.push(result.logid);
                }
                // Every course asked for now has that visibility: the skipped ones already had it.
                done.push(...batch);
            }
        } catch (error) {
            failure = error;
        } finally {
            if (button) {
                button.disabled = false;
            }
        }
        remember(done, visible);
        done.forEach((id) => setCourseVisible(container, id, visible, strings.hidden));
        if (done.length > 0) {
            const options = logids.length > 0
                ? {actionLabel: strings.undo, onAction: () => undoAll(logids)}
                : {};
            toast.show(strings.visibilitychanged, options);
        }
        if (failure) {
            onError(failure);
            return null;
        }
        return {logids};
    });

    /**
     * Shows or hides courses.
     *
     * @param {Number[]} ids Courses only.
     * @param {Boolean} visible
     * @param {Element} [trigger] Button disabled while the request runs.
     * @returns {Promise<Object|null>|null} {logids} of the batches recorded; null when there is nothing to send,
     *                                      when a batch failed or when another write is running.
     */
    const apply = (ids, visible, trigger) => {
        if (!ids.length || isBusy()) {
            return null;
        }
        return run(ids, visible, trigger);
    };

    return {apply};
};
