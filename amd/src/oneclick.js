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
 * One click from the selection of the tree: «Quitar repetidos» and «Unificar categorías» run the preview of the
 * selected categories and ask one short question, [Aplicar] [Ver detalle] [Cancelar], instead of opening the panel.
 *
 * [Aplicar] applies the sure groups only, with the default course that stays, and the hash of the preview it just
 * ran; the «Posibles repetidos» always need the panel ([Revisar]). The modules of Moodle arrive in the options, so
 * this one runs in node:test. Everything is written with textContent.
 *
 * @module     tool_ceaorganizador/oneclick
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {openDialog} from 'tool_ceaorganizador/dialog';
import {format} from 'tool_ceaorganizador/treeview';
import {movesOf} from 'tool_ceaorganizador/dedupepanel';

/**
 * Fills the {$a->name} placeholders of a string.
 *
 * @param {String} text
 * @param {Object} values
 * @returns {String}
 */
export const fill = (text, values) => String(text).replace(/\{\$a->(\w+)\}/g, (match, name) => (
    Object.prototype.hasOwnProperty.call(values, name) ? String(values[name]) : match
));

/**
 * The categories of a list that are not inside another one of the list.
 *
 * @param {Object} model Tree model, with isDescendant(ancestor, id).
 * @param {Number[]} ids
 * @returns {Number[]}
 */
export const outerIds = (model, ids) => ids.filter((id) => !ids.some((other) => other !== id && model.isDescendant(other, id)));

/**
 * Asks one short question.
 *
 * @param {Object} options
 * @param {Object} options.strings
 * @param {String} options.title
 * @param {String[]} options.lines Text of the question, one paragraph each.
 * @param {Boolean} options.canApply Whether [Aplicar] is offered enabled.
 * @param {Boolean} options.review Whether [Revisar] is offered, for the possible repeated courses.
 * @param {Element} [options.trigger]
 * @param {Document} [options.doc]
 * @returns {Promise<String>} 'apply', 'detail', 'review' or 'cancel'.
 */
export const ask = ({strings, title, lines, canApply, review, trigger, doc = document}) => new Promise((resolve) => {
    let answered = false;
    const answer = (value) => {
        if (!answered) {
            answered = true;
            resolve(value);
        }
    };
    const dialog = openDialog({doc, title, trigger, onClose: () => answer('cancel')});
    const root = dialog.element;
    root.dataset.region = 'oneclick';
    lines.forEach((line) => {
        const paragraph = doc.createElement('p');
        paragraph.textContent = line;
        root.appendChild(paragraph);
    });
    const actions = doc.createElement('div');
    actions.className = 'tool-ceaorganizador-acciones';
    const add = (action, label, className, enabled = true) => {
        const node = doc.createElement('button');
        node.type = 'button';
        node.className = className;
        node.dataset.action = action;
        node.textContent = label;
        node.disabled = !enabled;
        node.addEventListener('click', () => {
            answer(action);
            dialog.close();
        });
        actions.appendChild(node);
        return node;
    };
    const apply = add('apply', strings.confirmapply, 'btn btn-primary', canApply);
    add('detail', strings.confirmdetail, 'btn btn-secondary');
    if (review) {
        add('review', strings.possiblereview, 'btn btn-secondary');
    }
    const cancel = add('cancel', strings.confirmcancel, 'btn btn-link');
    root.appendChild(actions);
    (canApply ? apply : cancel).focus();
});

/**
 * The default course that stays in a group.
 *
 * @param {Object} group
 * @returns {Number}
 */
const keepOf = (group) => (group.courses.find((course) => course.keep) || group.courses[0]).courseid;

/**
 * «Quitar repetidos» on the selected categories, in one click.
 *
 * @param {Object} options
 * @param {Object} options.api dedupePreview and dedupeApply.
 * @param {Object} options.strings
 * @param {Object} options.toast
 * @param {Function} options.onError
 * @param {Function} options.undo Undoes a journal entry.
 * @param {Function} options.reload Reloads the tree.
 * @param {Number[]} options.categoryids Selected categories.
 * @param {Function} options.openPanel Opens the «Quitar repetidos» panel with {categoryids, focus}.
 * @param {String[]} [options.intro] Lines before the question.
 * @param {Element} [options.trigger]
 * @param {Document} [options.doc]
 * @returns {Promise<String>} What happened: 'none', 'apply', 'detail', 'review', 'cancel', 'stale' or 'error'.
 */
export const quickDedupe = async({api, strings, toast, onError, undo, reload, categoryids, openPanel, intro = [], trigger,
        doc = document}) => {
    let preview;
    try {
        preview = await api.dedupePreview(categoryids);
    } catch (error) {
        onError(error);
        return 'error';
    }
    const moves = movesOf(preview.groups.map((group) => ({...group, included: true})));
    const possible = (preview.possible || []).length;
    if (moves === 0 && possible === 0) {
        toast.show([...intro, strings.dedupenone].join(' '));
        return 'none';
    }
    const lines = [...intro];
    if (moves > 0) {
        lines.push(fill(strings.dedupeconfirm, {moves, categories: preview.roots.length}));
    }
    if (possible > 0) {
        lines.push(format(strings.possiblecount, possible));
    }
    const choice = await ask({strings, title: strings.dedupe, lines, canApply: moves > 0, review: possible > 0, trigger, doc});
    if (choice === 'detail' || choice === 'review') {
        openPanel({categoryids: preview.roots, focus: choice === 'review' ? 'possible' : ''});
        return choice;
    }
    if (choice !== 'apply') {
        return choice;
    }
    let result;
    try {
        result = await api.dedupeApply({
            categoryids: preview.roots,
            groups: preview.groups.map((group) => ({key: group.key, keep: keepOf(group)})),
            hash: preview.hash,
        });
    } catch (error) {
        onError(error);
        return 'error';
    }
    if (result.status === 'stale') {
        // Something changed since the preview: the panel shows the new one.
        openPanel({categoryids: preview.roots, focus: ''});
        return 'stale';
    }
    if (result.logid > 0) {
        const message = result.status === 'partial' ? strings.partialdone : strings.dedupedone;
        toast.show(format(message, result.moved), {actionLabel: strings.undo, onAction: () => undo(result.logid)});
        try {
            await reload();
        } catch (error) {
            onError(error);
        }
    } else {
        toast.show(strings.dedupenone);
    }
    return 'apply';
};

/**
 * «Unificar categorías» with the selected categories as sources, in one click.
 *
 * The target is the top-level category «Semillas» (normalized), created by create_seedroot when it is missing. The
 * selected categories inside it are left as they are. When all of them are inside it, the first one in the tree is
 * the target and the others are merged into it; with only one, there is nothing to merge and it does «Quitar
 * repetidos» on it instead.
 *
 * @param {Object} options
 * @param {Object} options.api createSeedroot, mergePreview, mergeApply, dedupePreview and dedupeApply.
 * @param {Object} options.strings
 * @param {Object} options.toast
 * @param {Function} options.onError
 * @param {Function} options.undo
 * @param {Function} options.reload
 * @param {Function} options.getModel Returns the live tree model.
 * @param {Number[]} options.categoryids Selected categories.
 * @param {Function} options.openMergePanel Opens the merge panel with {targetid, sourceids}.
 * @param {Function} options.openDedupePanel Opens the «Quitar repetidos» panel with {categoryids, focus}.
 * @param {Function} [options.decode] Turns a formatted name into plain text.
 * @param {Element} [options.trigger]
 * @param {Document} [options.doc]
 * @returns {Promise<String>} What happened, as quickDedupe().
 */
export const quickMerge = async({api, strings, toast, onError, undo, reload, getModel, categoryids, openMergePanel,
        openDedupePanel, decode = (text) => text, trigger, doc = document}) => {
    let seed;
    try {
        seed = await api.createSeedroot({name: strings.mergeseeddefault});
        if (seed.created) {
            await reload();
        }
    } catch (error) {
        onError(error);
        return 'error';
    }
    const seedid = Number(seed.id);
    const model = getModel();
    const ids = categoryids.map(Number);
    const inside = ids.filter((id) => id === seedid || model.isDescendant(seedid, id));
    let outside = outerIds(model, ids.filter((id) => !inside.includes(id)));
    let targetid = seedid;
    let leftinside = inside.length > 0;
    if (outside.length === 0) {
        // All inside Semillas: the first one in the tree receives the others.
        const chosen = inTreeOrder(model, outerIds(model, inside));
        if (chosen.length < 2) {
            return quickDedupe({api, strings, toast, onError, undo, reload, categoryids: inside,
                openPanel: openDedupePanel, intro: [strings.mergealreadyinseeds], trigger, doc});
        }
        [targetid, ...outside] = chosen;
        leftinside = false;
    }
    const target = model.get(targetid);
    const targetname = target ? decode(target.name) : '';

    let preview;
    try {
        preview = await api.mergePreview({targetid, sourceids: outside});
    } catch (error) {
        onError(error);
        return 'error';
    }
    const moves = preview.rows.filter((row) => !row.existing && row.action === 'move').length;
    const repeated = preview.rows.filter((row) => row.action === 'repeated').length;
    const possible = (preview.possible || []).length;
    if (moves + repeated === 0 && possible === 0) {
        toast.show(strings.mergenothing);
        return 'none';
    }
    const lines = [fill(strings.mergeconfirm, {moves, repeated, target: targetname})];
    if (leftinside) {
        lines.push(strings.mergeinsideleft);
    }
    if (possible > 0) {
        lines.push(format(strings.possiblecount, possible));
    }
    const choice = await ask({strings, title: strings.merge, lines, canApply: moves + repeated > 0, review: possible > 0,
        trigger, doc});
    if (choice === 'detail' || choice === 'review') {
        openMergePanel({targetid, sourceids: outside});
        return choice;
    }
    if (choice !== 'apply') {
        return choice;
    }
    let result;
    try {
        result = await api.mergeApply({
            targetid,
            sourceids: outside,
            rows: preview.rows.map((row) => ({courseid: row.courseid, action: row.action})),
            hash: preview.hash,
            confirmed: [],
        });
    } catch (error) {
        onError(error);
        return 'error';
    }
    if (result.status === 'stale') {
        openMergePanel({targetid, sourceids: outside});
        return 'stale';
    }
    if (result.logid > 0) {
        let message = format(result.status === 'partial' ? strings.partialdone : strings.mergedone, result.moved);
        if (result.status !== 'partial' && result.repeated > 0) {
            message += ` · ${format(strings.mergedonerepeated, result.repeated)}`;
        }
        toast.show(message, {actionLabel: strings.undo, onAction: () => undo(result.logid)});
        try {
            await reload();
        } catch (error) {
            onError(error);
        }
    } else {
        toast.show(strings.mergenothing);
    }
    return 'apply';
};

/**
 * Ids in the order of the tree (depth first, as drawn).
 *
 * @param {Object} model Tree model, with snapshot().
 * @param {Number[]} ids
 * @returns {Number[]}
 */
export const inTreeOrder = (model, ids) => {
    const position = new Map(model.snapshot().map((row, index) => [row.id, index]));
    const at = (id) => (position.has(id) ? position.get(id) : Number.MAX_SAFE_INTEGER);
    return [...ids].sort((a, b) => at(a) - at(b));
};

/**
 * What a top-bar button does: with categories selected in the tree, the one-click flow; with none, the panel.
 *
 * @param {Object} options
 * @param {Number[]} options.categoryids Categories of the selection (courses are not sent).
 * @param {Function} options.quick Runs the one-click flow with the categories.
 * @param {Function} options.panel Opens the panel, as without a selection.
 * @returns {*} What quick or panel returns.
 */
export const fromSelection = ({categoryids, quick, panel}) => (categoryids.length > 0 ? quick(categoryids) : panel());
