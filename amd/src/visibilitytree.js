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
 * «Ocultar todo» and «Mostrar todo»: counts what would change, asks once with the counts, applies, offers the undo
 * and reloads the tree (the «Oculta» labels come back from the server).
 *
 * It imports no Moodle module: everything it needs arrives in the options, so it runs in node:test.
 *
 * @module     tool_ceaorganizador/visibilitytree
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {openDialog} from 'tool_ceaorganizador/dialog';

/**
 * Replaces the {$a->name} placeholders of a string.
 *
 * @param {String} text
 * @param {Object} values
 * @returns {String}
 */
export const fill = (text, values) => Object.keys(values)
    .reduce((result, name) => result.split(`{$a->${name}}`).join(String(values[name])), String(text));

/**
 * Asks to apply, with the counts.
 *
 * @param {Object} options
 * @param {Object} options.strings
 * @param {String} options.title
 * @param {String} options.text
 * @param {Element} [options.trigger]
 * @param {Document} [options.doc]
 * @returns {Promise<Boolean>} Whether the user chose to apply.
 */
const confirm = ({strings, title, text, trigger, doc}) => new Promise((resolve) => {
    let answered = false;
    const answer = (value) => {
        if (!answered) {
            answered = true;
            resolve(value);
        }
    };
    const dialog = openDialog({doc, title, trigger, onClose: () => answer(false)});
    const root = dialog.element;
    root.dataset.region = 'visibilitytree';
    const paragraph = doc.createElement('p');
    paragraph.dataset.region = 'counts';
    paragraph.textContent = text;
    const actions = doc.createElement('div');
    actions.className = 'tool-ceaorganizador-acciones';
    const add = (action, label, className, value) => {
        const node = doc.createElement('button');
        node.type = 'button';
        node.className = className;
        node.dataset.action = action;
        node.textContent = label;
        node.addEventListener('click', () => {
            answer(value);
            dialog.close();
        });
        actions.appendChild(node);
        return node;
    };
    const apply = add('apply', strings.confirmapply, 'btn btn-primary', true);
    add('cancel', strings.confirmcancel, 'btn btn-link', false);
    root.append(paragraph, actions);
    apply.focus();
});

/**
 * Hides or shows whole categories.
 *
 * @param {Object} options
 * @param {Object} options.api visibilityTreePreview(categoryids, show) and visibilityTreeApply(categoryids, show, hash).
 * @param {Object} options.strings
 * @param {Object} options.toast Object with show(message, options).
 * @param {Function} options.onError Receives the exceptions of the web services.
 * @param {Function} options.undo Undoes a journal entry (and reloads the tree).
 * @param {Function} options.reload Reloads the tree from the server.
 * @param {Number[]} options.categoryids Categories only (the outer ones of a selection).
 * @param {Boolean} options.show True for «Mostrar todo», false for «Ocultar todo».
 * @param {Element} [options.trigger] Gets the focus back when the question closes.
 * @param {Document} [options.doc]
 * @returns {Promise<Object|null>} The result of the apply; null when nothing was applied.
 */
export const run = async({api, strings, toast, onError, undo, reload, categoryids, show, trigger, doc = document}) => {
    if (!categoryids.length) {
        return null;
    }
    let preview;
    try {
        preview = await api.visibilityTreePreview(categoryids, show);
    } catch (error) {
        onError(error);
        return null;
    }
    const counts = {categories: preview.categories, courses: preview.courses};
    if (preview.categories + preview.courses === 0) {
        toast.show(strings.visibilitytreenothing);
        return null;
    }
    const ok = await confirm({
        strings,
        title: show ? strings.showtree : strings.hidetree,
        text: fill(show ? strings.showtreeconfirm : strings.hidetreeconfirm, counts),
        trigger,
        doc,
    });
    if (!ok) {
        return null;
    }
    let result;
    try {
        result = await api.visibilityTreeApply(categoryids, show, preview.hash);
    } catch (error) {
        onError(error);
        return null;
    }
    if (result.status === 'stale') {
        toast.show(strings.visibilitytreestale);
        return result;
    }
    const done = {categories: result.categories, courses: result.courses};
    const message = fill(result.status === 'partial' ? strings.visibilitytreepartial : strings.visibilitytreedone, done);
    toast.show(message, result.logid > 0 ? {actionLabel: strings.undo, onAction: () => undo(result.logid)} : {});
    try {
        await reload();
    } catch (error) {
        onError(error);
    }
    return result;
};

/**
 * The two buttons of the selection bar: «Ocultar todo lo seleccionado» and «Mostrar todo lo seleccionado».
 *
 * Only the categories of the selection count (their courses come with them); the courses selected on their own are
 * left to «Ocultar/Mostrar seleccionados».
 *
 * @param {Object} options
 * @param {Object} options.strings hidetreeselected and showtreeselected.
 * @param {Function} options.categoryIds Returns the selected categories, the outer ones only.
 * @param {Function} options.apply Called with (button, categoryids, show).
 * @param {Document} [options.doc]
 * @returns {{hide: Element, show: Element, sync: Function}} sync() disables both when no category is selected.
 */
export const treeButtons = ({strings, categoryIds, apply, doc = document}) => {
    const make = (action, label, show) => {
        const button = doc.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-secondary btn-sm';
        button.dataset.action = action;
        button.textContent = label;
        button.addEventListener('click', () => apply(button, categoryIds(), show));
        return button;
    };
    const hide = make('hidetreeselection', strings.hidetreeselected, false);
    const show = make('showtreeselection', strings.showtreeselected, true);
    const sync = () => {
        const none = categoryIds().length === 0;
        hide.disabled = none;
        show.disabled = none;
    };
    sync();
    return {hide, show, sync};
};
