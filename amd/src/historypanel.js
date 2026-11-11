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
 * History panel: the journal of operations, a page at a time, with an undo per entry.
 *
 * Summaries, user names and the conflicts of an undo are formatted strings
 * (decode, then textContent).
 *
 * @module     tool_ceaorganizador/historypanel
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {openDialog} from 'tool_ceaorganizador/dialog';
import {format} from 'tool_ceaorganizador/treeview';

/**
 * Opens the panel.
 *
 * @param {Object} options
 * @param {Object} options.api history(page).
 * @param {Object} options.strings Strings of the panel.
 * @param {Function} options.onError Receives the exceptions of the web services.
 * @param {Function} options.undo Undoes an entry: (logid, {quiet}) resolves {ok, conflicts} or null.
 * @param {Function} options.reload Reloads the tree from the server.
 * @param {Function} [options.viewProgress] Opens the progress panel of a cloning batch: (logid).
 * @param {Function} [options.decode] Turns a formatted string into plain text.
 * @param {Element} [options.trigger] Gets the focus back on close.
 * @param {Document} [options.doc]
 * @returns {{close: Function, element: Element, load: Function}}
 */
export const open = ({
    api,
    strings,
    onError,
    undo,
    reload,
    viewProgress,
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

    const dialog = openDialog({doc, title: strings.history, trigger, className: 'tool-ceaorganizador-dialogo-ancho'});
    const root = dialog.element;
    const conflicts = create('div', 'tool-ceaorganizador-aviso');
    conflicts.dataset.region = 'conflicts';
    conflicts.setAttribute('role', 'alert');
    conflicts.hidden = true;
    const list = create('ul', 'tool-ceaorganizador-historial');
    list.dataset.region = 'rows';
    const pager = create('div', 'tool-ceaorganizador-acciones');
    const prev = create('button', 'btn btn-secondary btn-sm', strings.prevpage);
    prev.type = 'button';
    prev.dataset.action = 'prev';
    const label = create('span', 'tool-ceaorganizador-ruta');
    const next = create('button', 'btn btn-secondary btn-sm', strings.nextpage);
    next.type = 'button';
    next.dataset.action = 'next';
    const close = create('button', 'btn btn-link', strings.close);
    close.type = 'button';
    close.addEventListener('click', () => dialog.close());
    pager.append(prev, label, next, close);
    root.append(conflicts, list, pager);

    let page = 0;
    let token = 0;
    let busy = false;

    const showConflicts = (messages, title) => {
        conflicts.textContent = '';
        conflicts.hidden = messages.length === 0;
        if (!messages.length) {
            return;
        }
        conflicts.appendChild(create('strong', '', title));
        const ul = create('ul');
        messages.forEach((message) => ul.appendChild(create('li', '', decode(message))));
        conflicts.appendChild(ul);
    };

    const undoRow = async(row, undoButton) => {
        if (busy) {
            return;
        }
        busy = true;
        undoButton.disabled = true;
        try {
            const result = await undo(row.id, {quiet: true});
            if (result && result.ok) {
                showConflicts([], '');
                await reload();
                await load(page);
            } else if (result) {
                showConflicts(result.conflicts.length ? result.conflicts : [strings.error_generic], strings.undoconflicts);
                undoButton.disabled = false;
            } else {
                undoButton.disabled = false;
            }
        } catch (error) {
            undoButton.disabled = false;
            onError(error);
        } finally {
            busy = false;
        }
    };

    const draw = (result) => {
        list.textContent = '';
        if (!result.rows.length) {
            list.appendChild(create('li', '', strings.history_empty));
        }
        result.rows.forEach((row) => {
            const li = create('li', 'tool-ceaorganizador-historial-fila');
            li.dataset.id = String(row.id);
            li.appendChild(create('div', 'tool-ceaorganizador-nombre', decode(row.summary)));
            const when = new Date(row.timecreated * 1000).toLocaleString();
            const who = row.userfullname ? `${decode(row.userfullname)} · ` : '';
            li.appendChild(create('div', 'tool-ceaorganizador-ruta', `${who}${when}`));
            if (row.action === 'clone') {
                // A cloning batch cannot be undone (the new courses are deleted from Moodle), but its progress
                // can be followed again. The history closes first: two dialogs would share the Esc key.
                li.dataset.kind = 'clone';
                if (viewProgress) {
                    const progressButton = create('button', 'btn btn-secondary btn-sm', strings.cloneviewprogress);
                    progressButton.type = 'button';
                    progressButton.dataset.action = 'viewprogress';
                    progressButton.addEventListener('click', () => {
                        dialog.close();
                        viewProgress(row.id);
                    });
                    li.appendChild(progressButton);
                }
            } else if (row.undone) {
                li.appendChild(create('span', 'tool-ceaorganizador-insignia', strings.historyundone));
            } else {
                const undoButton = create('button', 'btn btn-secondary btn-sm', strings.undo);
                undoButton.type = 'button';
                undoButton.dataset.action = 'undo';
                undoButton.addEventListener('click', () => undoRow(row, undoButton));
                li.appendChild(undoButton);
            }
            list.appendChild(li);
        });
        const pages = Math.max(1, Math.ceil(result.total / (result.perpage || 1)));
        label.textContent = format(strings.historypage, `${page + 1} / ${pages}`);
        prev.disabled = page <= 0;
        next.disabled = page + 1 >= pages;
    };

    async function load(wanted) {
        const mine = ++token;
        try {
            const result = await api.history(wanted);
            if (mine === token) {
                page = wanted;
                draw(result);
            }
        } catch (error) {
            onError(error);
        }
    }

    prev.addEventListener('click', () => load(page - 1));
    next.addEventListener('click', () => load(page + 1));
    load(0);
    return {close: dialog.close, element: root, load};
};
