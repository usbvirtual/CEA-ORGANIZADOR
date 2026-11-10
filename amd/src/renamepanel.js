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
 * Mass rename panel: a list of operations, a preview table with one editable
 * name per row, and the apply step in batches.
 *
 * The names that rename_preview and rename_apply carry are RAW (plain text, never
 * formatted): they are never decoded and are only written with textContent or as
 * an input value. The modules of Moodle arrive in the options, so this one runs
 * in node:test with jsdom.
 *
 * @module     tool_ceaorganizador/renamepanel
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {openDialog} from 'tool_ceaorganizador/dialog';
import {format} from 'tool_ceaorganizador/treeview';

const MAX_BATCH = 500;
const MAX_OPS = 50;
const MAX_NAME = 254;
const ERROR_CODES = ['empty', 'toolong', 'multilang', 'shortnametaken', 'shortnameduplicate'];

/**
 * Builds the operation list of rename_preview from the form, exactly as the server whitelists it.
 * Operations that would do nothing (an empty search or an empty affix) are left out.
 *
 * @param {Object} formState
 * @param {Object[]} formState.ops Forms of the operations, in order: {type: 'replace', search, replace,
 *                   casesensitive, wholeword}, {type: 'clean', case} or {type: 'affix', position, mode, text}.
 * @returns {Object[]}
 */
export const buildOps = (formState) => {
    const ops = [];
    (formState.ops || []).forEach((form) => {
        switch (form.type) {
            case 'replace':
                if (String(form.search || '') !== '') {
                    ops.push({
                        type: 'replace',
                        search: String(form.search),
                        replace: String(form.replace || ''),
                        casesensitive: Boolean(form.casesensitive),
                        wholeword: Boolean(form.wholeword),
                    });
                }
                break;
            case 'clean':
                ops.push({type: 'clean', case: form.case || 'none'});
                break;
            case 'affix':
                if (String(form.text || '') !== '') {
                    ops.push({
                        type: 'affix',
                        position: form.position === 'suffix' ? 'suffix' : 'prefix',
                        mode: form.mode === 'remove' ? 'remove' : 'add',
                        text: String(form.text),
                    });
                }
                break;
            default:
        }
    });
    return ops;
};

/**
 * Splits a list into consecutive batches.
 *
 * @param {Array} list
 * @param {Number} size
 * @returns {Array[]}
 */
export const splitBatches = (list, size) => {
    const batches = [];
    for (let at = 0; at < list.length; at += size) {
        batches.push(list.slice(at, at + size));
    }
    return batches;
};

/**
 * Error code of a row: the one of the server, or the one a manual edit causes.
 *
 * @param {Object} row Row of rename_preview.
 * @param {String} value Current value of the new name.
 * @returns {String|null}
 */
const errorOf = (row, value) => {
    if (row.error) {
        return row.error;
    }
    const name = value.trim();
    if (name === '') {
        return 'empty';
    }
    // rename_apply receives the name as PARAM_TEXT, which strips tags: a name with '<' would
    // not be saved as shown, so it is never sent (the same refusal as multilanguage names).
    if (name.includes('<')) {
        return 'multilang';
    }
    return name.length > MAX_NAME ? 'toolong' : null;
};

let counter = 0;

/**
 * Opens the panel.
 *
 * @param {Object} options
 * @param {Object} options.api renamePreview and renameApply.
 * @param {Object} options.strings Strings of the panel.
 * @param {Object} options.toast Object with show(message, options).
 * @param {Function} options.onError Receives the exceptions of the web services.
 * @param {Function} options.undo Undoes a journal entry (async; resolves {ok} or nothing).
 * @param {Function} options.reload Reloads the tree from the server.
 * @param {Object[]} options.items [{type, id}] to rename.
 * @param {Element} [options.trigger] Gets the focus back on close.
 * @param {Document} [options.doc]
 * @returns {{close: Function, element: Element}}
 */
export const open = ({api, strings, toast, onError, undo, reload, items, trigger, doc = document}) => {
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
    const button = (action, label, className = 'btn btn-secondary btn-sm') => {
        const node = create('button', className, label);
        node.type = 'button';
        node.dataset.action = action;
        return node;
    };
    const textField = (field, label) => {
        const wrap = create('label', 'tool-ceaorganizador-campo');
        wrap.appendChild(create('span', '', label));
        const input = create('input', 'form-control');
        input.type = 'text';
        input.dataset.field = field;
        wrap.appendChild(input);
        return wrap;
    };
    const selectField = (field, label, options) => {
        const wrap = create('label', 'tool-ceaorganizador-campo');
        wrap.appendChild(create('span', '', label));
        const select = create('select', 'custom-select form-select');
        select.dataset.field = field;
        options.forEach(([value, text]) => {
            const option = create('option', '', text);
            option.value = value;
            select.appendChild(option);
        });
        wrap.appendChild(select);
        return wrap;
    };
    const checkField = (field, label) => {
        counter += 1;
        const wrap = create('div', 'custom-control form-check custom-checkbox');
        const input = create('input', 'custom-control-input form-check-input');
        input.type = 'checkbox';
        input.id = `tool-ceaorganizador-rename-${counter}`;
        input.dataset.field = field;
        const text = create('label', 'custom-control-label form-check-label', label);
        text.htmlFor = input.id;
        wrap.append(input, text);
        return wrap;
    };

    const dialog = openDialog({
        doc,
        title: strings.rename,
        trigger,
        className: 'tool-ceaorganizador-dialogo-ancho',
    });
    const root = dialog.element;

    // Operations: each item is a form; their order in the DOM is their order of application.
    const opsList = create('ol', 'tool-ceaorganizador-operaciones');
    const addSelect = selectField('optype', strings.renameoptype, [
        ['replace', strings.renameop_replace],
        ['clean', strings.renameop_clean],
        ['affix', strings.renameop_affix],
    ]);
    const addButton = button('addop', strings.renameaddop);
    const adder = create('div', 'tool-ceaorganizador-agregar');
    adder.append(addSelect, addButton);

    const move = (li, step) => {
        if (step < 0 && li.previousElementSibling) {
            opsList.insertBefore(li, li.previousElementSibling);
        } else if (step > 0 && li.nextElementSibling) {
            opsList.insertBefore(li.nextElementSibling, li);
        }
    };

    const addOp = (type) => {
        if (opsList.children.length >= MAX_OPS) {
            return;
        }
        const li = create('li', 'tool-ceaorganizador-operacion');
        li.dataset.optype = type;
        const head = create('div', 'tool-ceaorganizador-operacion-cabecera');
        head.appendChild(create('strong', '', strings[`renameop_${type}`]));
        const up = button('opup', '↑');
        up.setAttribute('aria-label', strings.opmoveup);
        up.addEventListener('click', () => move(li, -1));
        const down = button('opdown', '↓');
        down.setAttribute('aria-label', strings.opmovedown);
        down.addEventListener('click', () => move(li, 1));
        const remove = button('opremove', '×');
        remove.setAttribute('aria-label', strings.opremove);
        remove.addEventListener('click', () => li.remove());
        head.append(up, down, remove);
        const fields = create('div', 'tool-ceaorganizador-operacion-campos');
        if (type === 'replace') {
            fields.append(
                textField('search', strings.searchfor),
                textField('replace', strings.replacewith),
                checkField('casesensitive', strings.casesensitive),
                checkField('wholeword', strings.wholeword)
            );
        } else if (type === 'clean') {
            fields.appendChild(selectField('case', strings.renameop_clean, [
                ['none', strings.case_none],
                ['title', strings.case_title],
                ['sentence', strings.case_sentence],
                ['upper', strings.case_upper],
            ]));
        } else {
            fields.append(
                selectField('position', strings.affixposition, [['prefix', strings.prefix], ['suffix', strings.suffix]]),
                selectField('mode', strings.affixmode, [['add', strings.affixadd], ['remove', strings.affixremove]]),
                textField('text', strings.affixtext)
            );
        }
        li.append(head, fields);
        opsList.appendChild(li);
    };
    addButton.addEventListener('click', () => addOp(addSelect.querySelector('select').value));
    addOp('replace');

    const shortWrap = checkField('includeshortname', strings.includeshortname);

    // Preview.
    const notice = create('p', 'tool-ceaorganizador-aviso');
    notice.dataset.region = 'notice';
    notice.setAttribute('role', 'status');
    notice.hidden = true;
    const tbody = create('tbody');
    const table = create('table', 'table table-sm tool-ceaorganizador-tabla');
    const headrow = create('tr');
    headrow.append(create('th', '', strings.before), create('th', '', strings.after));
    const thead = create('thead');
    thead.appendChild(headrow);
    table.append(thead, tbody);
    const tablewrap = create('div', 'tool-ceaorganizador-tabla-contenedor');
    tablewrap.appendChild(table);
    tablewrap.hidden = true;

    const previewButton = button('preview', strings.renamepreview, 'btn btn-secondary');
    const applyButton = button('apply', strings.apply, 'btn btn-primary');
    applyButton.disabled = true;
    const closeButton = button('close', strings.close, 'btn btn-link');
    closeButton.addEventListener('click', () => dialog.close());
    const actions = create('div', 'tool-ceaorganizador-acciones');
    actions.append(previewButton, applyButton, closeButton);

    root.append(opsList, adder, shortWrap, actions, notice, tablewrap);

    let rows = [];
    const manual = {};
    let previewed = false;
    let token = 0;
    let busy = false;

    const say = (text) => {
        notice.textContent = text;
        notice.hidden = text === '';
    };

    const readForm = () => {
        const ops = [...opsList.children].map((li) => {
            const form = {type: li.dataset.optype};
            li.querySelectorAll('[data-field]').forEach((control) => {
                form[control.dataset.field] = control.type === 'checkbox' ? control.checked : control.value;
            });
            return form;
        });
        return {ops, includeshortname: shortWrap.querySelector('input').checked};
    };

    // The rows that apply would send, with their current values.
    const pending = () => {
        const changes = [];
        tbody.querySelectorAll('tr').forEach((tr) => {
            const row = rows[Number(tr.dataset.index)];
            const input = tr.querySelector('[data-field="new"]');
            if (!row || errorOf(row, input.value)) {
                return;
            }
            const value = input.value.trim();
            const hasShort = row.oldshort !== undefined && row.newshort !== undefined;
            if (value === row.old && (!hasShort || row.newshort === row.oldshort)) {
                return;
            }
            const change = {type: row.type, id: row.id, old: row.old, new: value};
            if (hasShort) {
                change.oldshort = row.oldshort;
                change.newshort = row.newshort;
            }
            changes.push(change);
        });
        return changes;
    };

    const refresh = () => {
        const count = pending().length;
        applyButton.disabled = busy || count === 0;
        const batches = Math.ceil(count / MAX_BATCH);
        if (batches > 1) {
            say(format(strings.batchsplit, batches));
        } else {
            say(previewed && rows.length === 0 ? strings.renamenothing : '');
        }
    };

    const showError = (tr, row, value) => {
        const code = errorOf(row, value);
        const cell = tr.querySelector('[data-region="error"]');
        let message = '';
        if (code) {
            message = ERROR_CODES.includes(code) ? strings[`renameerror_${code}`] : strings.error_generic;
        }
        cell.textContent = message;
        tr.classList.toggle('tool-ceaorganizador-fila-error', Boolean(code));
    };

    const drawRows = () => {
        tbody.textContent = '';
        rows.forEach((row, index) => {
            const key = `${row.type}:${row.id}`;
            const tr = create('tr');
            tr.dataset.key = key;
            tr.dataset.index = String(index);
            const before = create('td');
            before.appendChild(create('div', 'tool-ceaorganizador-nombre', row.old));
            const after = create('td');
            const input = create('input', 'form-control form-control-sm');
            input.type = 'text';
            input.dataset.field = 'new';
            input.setAttribute('aria-label', `${strings.after}: ${row.old}`);
            input.value = Object.prototype.hasOwnProperty.call(manual, key) ? manual[key] : row.new;
            after.appendChild(input);
            if (row.oldshort !== undefined && row.newshort !== undefined) {
                before.appendChild(create('div', 'tool-ceaorganizador-ruta', `${strings.shortname}: ${row.oldshort}`));
                after.appendChild(create('div', 'tool-ceaorganizador-ruta', `${strings.shortname}: ${row.newshort}`));
            }
            const error = create('div', 'tool-ceaorganizador-error');
            error.dataset.region = 'error';
            after.appendChild(error);
            input.disabled = Boolean(row.error);
            input.addEventListener('input', () => {
                if (input.value === row.new) {
                    delete manual[key];
                } else {
                    manual[key] = input.value;
                }
                showError(tr, row, input.value);
                refresh();
            });
            tr.append(before, after);
            tbody.appendChild(tr);
            showError(tr, row, input.value);
        });
        tablewrap.hidden = rows.length === 0;
        refresh();
    };

    const preview = async() => {
        if (busy) {
            return;
        }
        const mine = ++token;
        const form = readForm();
        busy = true;
        previewButton.disabled = true;
        try {
            const result = await api.renamePreview({
                items,
                ops: JSON.stringify(buildOps(form)),
                manual: JSON.stringify(manual),
                includeshortname: form.includeshortname,
            });
            if (mine === token) {
                rows = result.rows;
                previewed = true;
                drawRows();
            }
        } catch (error) {
            onError(error);
        } finally {
            busy = false;
            previewButton.disabled = false;
            refresh();
        }
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

    const apply = async() => {
        const changes = pending();
        if (busy || changes.length === 0) {
            return;
        }
        busy = true;
        previewButton.disabled = true;
        applyButton.disabled = true;
        const logids = [];
        let done = 0;
        let skipped = 0;
        let failure = null;
        try {
            for (const batch of splitBatches(changes, MAX_BATCH)) {
                const result = await api.renameApply({changes: batch});
                if (result.logid > 0) {
                    logids.push(result.logid);
                }
                result.results.forEach((entry) => {
                    done += entry.status === 'ok' ? 1 : 0;
                    skipped += entry.status === 'ok' ? 0 : 1;
                });
            }
        } catch (error) {
            failure = error;
        }
        busy = false;
        previewButton.disabled = false;
        if (logids.length === 0) {
            refresh();
            if (failure) {
                onError(failure);
            } else {
                say(strings.renameskipped);
            }
            return;
        }
        dialog.close();
        // Without per-row results the batches that were recorded count as applied.
        let message = format(strings.renamedone, done || changes.length);
        if (skipped > 0) {
            message += ` ${strings.renameskipped}`;
        }
        toast.show(message, {actionLabel: strings.undo, onAction: () => undoAll(logids)});
        try {
            await reload();
        } finally {
            if (failure) {
                onError(failure);
            }
        }
    };

    previewButton.addEventListener('click', preview);
    applyButton.addEventListener('click', apply);
    opsList.querySelector('[data-field]').focus();
    return {close: dialog.close, element: root};
};
