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
 * Delete panel ("Borrar categorías"): asks the server which of the chosen categories are empty, lists what
 * will be deleted and what will be kept (with the reason), and deletes on confirmation.
 *
 * The paths of delete_preview are formatted (entities): they are decoded with options.decode. The names of
 * delete_apply are raw and are never decoded. Everything is written with textContent. The modules of Moodle
 * arrive in the options, so this one runs in node:test.
 *
 * @module     tool_ceaorganizador/deletepanel
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {openDialog} from 'tool_ceaorganizador/dialog';
import {format} from 'tool_ceaorganizador/treeview';

/** @var {Number} Maximum categories per call; the server refuses more (deleterunner::MAX_ITEMS). */
export const MAX_ITEMS = 200;

/**
 * Text of the reason a category is kept.
 *
 * @param {Object} strings
 * @param {String} reason hascourses, haschildren, hascontent, nopermission or notfound.
 * @returns {String}
 */
const reasonText = (strings, reason) => strings[`deletereason_${reason}`] || reason;

/**
 * Opens the panel.
 *
 * @param {Object} options
 * @param {Object} options.api deletePreview(ids) and deleteApply(ids).
 * @param {Object} options.strings Strings of the panel.
 * @param {Object} options.toast Object with show(message, options).
 * @param {Function} options.onError Receives the exceptions of the web services.
 * @param {Function} options.undo Undoes a journal entry and reloads the tree.
 * @param {Function} options.reload Reloads the tree from the server.
 * @param {Number[]} options.ids Categories chosen (courses are not sent).
 * @param {Function} [options.onApplied] Called with the ids deleted, after categories were deleted (for example to
 *                                        take them out of the selection).
 * @param {Function} [options.decode] Turns a formatted string into plain text.
 * @param {Element} [options.trigger] Gets the focus back on close.
 * @param {Document} [options.doc]
 * @returns {{close: Function, element: Element, ready: Promise}}
 */
export const open = ({
    api,
    strings,
    toast,
    onError,
    undo,
    reload,
    ids,
    onApplied = () => null,
    decode = (text) => text,
    trigger,
    doc = document,
}) => {
    const create = (tag, className, text) => {
        const node = doc.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    };

    let closed = false;
    const dialog = openDialog({
        doc,
        title: strings.deletetitle,
        trigger,
        className: 'tool-ceaorganizador-dialogo-ancho',
        onClose: () => {
            closed = true;
        },
    });
    const root = dialog.element;

    const note = create('p', 'tool-ceaorganizador-ruta', strings.deletenote);
    const notice = create('p', 'tool-ceaorganizador-aviso');
    notice.dataset.region = 'notice';
    notice.setAttribute('role', 'status');
    notice.hidden = true;

    const section = (region, title) => {
        const box = create('section', 'tool-ceaorganizador-fusion-bloque');
        box.dataset.region = region;
        box.hidden = true;
        const list = create('ul', 'tool-ceaorganizador-dialogo-lista');
        box.append(create('h3', 'tool-ceaorganizador-sugerencias-titulo', title), list);
        return {box, list};
    };
    const deleted = section('delete', strings.deletewill);
    const skipped = section('skipped', strings.deleteskipped);

    const applyButton = create('button', 'btn btn-danger', format(strings.deleteapply, 0));
    applyButton.type = 'button';
    applyButton.dataset.action = 'apply';
    applyButton.disabled = true;
    const closeButton = create('button', 'btn btn-link', strings.close);
    closeButton.type = 'button';
    closeButton.dataset.action = 'close';
    closeButton.addEventListener('click', () => dialog.close());
    const actions = create('div', 'tool-ceaorganizador-acciones');
    actions.append(applyButton, closeButton);
    root.append(note, notice, deleted.box, skipped.box, actions);

    let todo = [];
    let busy = true;
    root.setAttribute('aria-busy', 'true');

    const say = (text) => {
        notice.textContent = text;
        notice.hidden = text === '';
    };

    const draw = (result) => {
        todo = result.delete.map((row) => Number(row.id));
        deleted.list.textContent = '';
        result.delete.forEach((row) => {
            const li = create('li', 'tool-ceaorganizador-nombre', decode(row.path));
            li.dataset.id = String(row.id);
            deleted.list.appendChild(li);
        });
        deleted.box.hidden = todo.length === 0;
        skipped.list.textContent = '';
        result.skipped.forEach((row) => {
            const li = create('li');
            li.dataset.id = String(row.id);
            li.dataset.reason = row.reason;
            // A category that no longer exists has no path: its id stands in.
            li.appendChild(create('span', 'tool-ceaorganizador-nombre', row.path ? decode(row.path) : `#${row.id}`));
            li.appendChild(create('span', 'tool-ceaorganizador-ruta', ` · ${reasonText(strings, row.reason)}`));
            skipped.list.appendChild(li);
        });
        skipped.box.hidden = result.skipped.length === 0;
        applyButton.textContent = format(strings.deleteapply, todo.length);
        say(todo.length === 0 ? strings.deletenothing : '');
    };

    const setBusy = (value) => {
        busy = value;
        root.setAttribute('aria-busy', value ? 'true' : 'false');
        applyButton.disabled = value || todo.length === 0;
    };

    const ready = (async() => {
        // One batch only, so a single journal entry and a single undo: more is refused before any request.
        if (ids.length > MAX_ITEMS) {
            say(format(strings.error_toomanydelete, MAX_ITEMS));
            setBusy(false);
            return;
        }
        try {
            const result = await api.deletePreview(ids);
            if (!closed) {
                draw(result);
            }
        } catch (error) {
            onError(error);
        } finally {
            setBusy(false);
        }
    })();

    const apply = async() => {
        if (busy || todo.length === 0) {
            return;
        }
        setBusy(true);
        let result;
        try {
            result = await api.deleteApply(todo);
        } catch (error) {
            setBusy(false);
            onError(error);
            return;
        }
        dialog.close();
        let message = result.deleted > 0 ? format(strings.deletedone, result.deleted) : strings.deletenothing;
        if (result.skipped.length > 0) {
            message += ` ${format(strings.deleteskippedcount, result.skipped.length)}`;
        }
        toast.show(message, result.logid > 0 ? {actionLabel: strings.undo, onAction: () => undo(result.logid)} : {});
        if (result.deleted > 0) {
            const kept = new Set(result.skipped.map((row) => Number(row.id)));
            onApplied(todo.filter((id) => !kept.has(id)));
            try {
                await reload();
            } catch (error) {
                onError(error);
            }
        }
    };
    applyButton.addEventListener('click', apply);

    closeButton.focus();
    return {close: dialog.close, element: root, ready};
};
