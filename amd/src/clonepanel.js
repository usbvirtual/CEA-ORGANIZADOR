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
 * Cloning panel: period, label and dates, a preview table with one editable name pair per
 * course, and the apply step, which hands over to the progress panel.
 *
 * What clone_preview formats (names, paths) is decoded with options.decode and written with
 * textContent. newfullname and newshortname are RAW: they are never decoded and travel back
 * as typed. The modules of Moodle arrive in the options, so this one runs in node:test.
 *
 * @module     tool_ceaorganizador/clonepanel
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {openDialog} from 'tool_ceaorganizador/dialog';
import {format} from 'tool_ceaorganizador/treeview';
import {periodOptions, isValidLabel, dateToTimestamp, suggestContainer} from 'tool_ceaorganizador/period';
import {open as defaultProgress} from 'tool_ceaorganizador/cloneprogress';

const ERROR_CODES = [
    'alreadycloned', 'destinsideseeds', 'nopermission', 'queuefailed', 'shortnameduplicate', 'shortnametaken', 'toolong',
];

/**
 * Opens the panel.
 *
 * @param {Object} options
 * @param {Object} options.api clonePreview and cloneApply.
 * @param {Object} options.strings Strings of the panel.
 * @param {Object} options.toast Object with show(message, options).
 * @param {Function} options.onError Receives the exceptions of the web services.
 * @param {Object} options.model Tree model (snapshot()), read when the panel opens.
 * @param {Function} [options.decode] Turns a formatted string into plain text.
 * @param {Object[]} options.items [{type, id}] to clone.
 * @param {Function} [options.openProgress] Opens the progress panel: (batchid, options).
 * @param {Element} [options.trigger] Gets the focus back on close.
 * @param {Document} [options.doc]
 * @returns {{close: Function, element: Element}}
 */
export const open = ({
    api,
    strings,
    toast,
    onError,
    model,
    decode = (text) => text,
    items,
    openProgress = defaultProgress,
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
    const button = (action, label, className) => {
        const node = create('button', className, label);
        node.type = 'button';
        node.dataset.action = action;
        return node;
    };
    const field = (name, label, control) => {
        const wrap = create('label', 'tool-ceaorganizador-campo');
        wrap.appendChild(create('span', '', label));
        control.dataset.field = name;
        wrap.appendChild(control);
        return wrap;
    };

    const dialog = openDialog({doc, title: strings.clonetoperiod, trigger, className: 'tool-ceaorganizador-dialogo-ancho'});
    const root = dialog.element;

    const snapshot = model.snapshot();
    const options = periodOptions(snapshot, decode);
    const select = create('select', 'custom-select form-select');
    options.forEach((entry) => {
        const node = create('option', '', entry.text);
        node.value = String(entry.id);
        select.appendChild(node);
    });
    const label = create('input', 'form-control');
    label.type = 'text';
    label.setAttribute('inputmode', 'numeric');
    label.setAttribute('placeholder', 'AAAA-PP');
    const start = create('input', 'form-control');
    start.type = 'date';
    const end = create('input', 'form-control');
    end.type = 'date';
    const container = create('input', 'form-control');
    container.type = 'text';
    container.maxLength = 254;
    const containerHelp = create('small', 'form-text text-muted', strings.clonecontainer_help);
    containerHelp.id = `tool-ceaorganizador-clone-container-${Date.now()}`;
    container.setAttribute('aria-describedby', containerHelp.id);
    // The help sits outside the label, so it describes the field without becoming part of its name.
    const containerField = create('div', 'tool-ceaorganizador-campo');
    containerField.append(field('container', strings.clonecontainer, container), containerHelp);
    const form = create('div', 'tool-ceaorganizador-formulario');
    form.append(
        field('period', strings.cloneperiod, select),
        field('label', strings.clonelabel, label),
        containerField,
        field('start', strings.clonestart, start),
        field('end', strings.cloneend, end)
    );

    const notice = create('p', 'tool-ceaorganizador-aviso');
    notice.dataset.region = 'notice';
    notice.setAttribute('role', 'status');
    notice.hidden = true;
    const note = create('p', 'tool-ceaorganizador-ruta', strings.clonecronnote);
    const total = create('p', 'tool-ceaorganizador-ruta');
    total.dataset.region = 'total';
    total.hidden = true;
    const tbody = create('tbody');
    const headrow = create('tr');
    [strings.clonesource, strings.clonedest, strings.clonenames, strings.clonestate]
        .forEach((text) => headrow.appendChild(create('th', '', text)));
    const thead = create('thead');
    thead.appendChild(headrow);
    const table = create('table', 'table table-sm tool-ceaorganizador-tabla');
    table.append(thead, tbody);
    const tablewrap = create('div', 'tool-ceaorganizador-tabla-contenedor');
    tablewrap.appendChild(table);
    tablewrap.hidden = true;

    const previewButton = button('preview', strings.clonepreview, 'btn btn-secondary');
    const applyButton = button('apply', format(strings.cloneapply, 0), 'btn btn-primary');
    applyButton.disabled = true;
    const closeButton = button('close', strings.close, 'btn btn-link');
    closeButton.addEventListener('click', () => dialog.close());
    const actions = create('div', 'tool-ceaorganizador-acciones');
    actions.append(previewButton, applyButton, closeButton);
    root.append(form, note, actions, notice, total, tablewrap);

    if (options.length === 0) {
        notice.textContent = strings.clonenoperiods;
        notice.hidden = false;
        previewButton.disabled = true;
    }

    let labelEdited = false;
    let containerEdited = false;
    const suggest = () => {
        const chosen = options.find((entry) => String(entry.id) === select.value);
        if (!labelEdited) {
            label.value = chosen ? chosen.label : '';
        }
        if (!containerEdited) {
            container.value = chosen ? suggestContainer(snapshot, chosen.id, decode) : '';
        }
    };
    suggest();

    // What the preview was made with: the hash only matches these values.
    let plan = null;
    let rows = [];
    let hash = '';
    let busy = false;
    let token = 0;

    const say = (text) => {
        notice.textContent = text;
        notice.hidden = text === '';
    };

    const rowError = (row, tr) => {
        if (row.error) {
            return row.error;
        }
        const inputs = tr.querySelectorAll('input[data-field]');
        return [...inputs].some((input) => input.value.trim() === '') ? 'empty' : null;
    };

    // The rows apply sends, with the values the inputs hold now. The server clones only these rows
    // (a row left out is 'notselected'), so the count of the button is always the length of this list.
    const pending = () => {
        const out = [];
        tbody.querySelectorAll('tr').forEach((tr) => {
            const row = rows[Number(tr.dataset.index)];
            if (!row || rowError(row, tr)) {
                return;
            }
            out.push({
                courseid: row.courseid,
                newfullname: tr.querySelector('[data-field="newfullname"]').value,
                newshortname: tr.querySelector('[data-field="newshortname"]').value,
            });
        });
        return out;
    };

    const refresh = () => {
        const count = pending().length;
        applyButton.textContent = format(strings.cloneapply, count);
        applyButton.disabled = busy || plan === null || count === 0;
    };

    const showState = (tr, row) => {
        const code = rowError(row, tr);
        let message = '';
        if (code === 'empty') {
            message = strings.cloneerror_empty;
        } else if (code) {
            message = ERROR_CODES.includes(code) ? strings[`cloneerror_${code}`] : strings.error_generic;
        }
        tr.querySelector('[data-region="state"]').textContent = message;
        tr.classList.toggle('tool-ceaorganizador-fila-error', Boolean(code));
    };

    const drawRows = () => {
        tbody.textContent = '';
        rows.forEach((row, index) => {
            const tr = create('tr');
            tr.dataset.courseid = String(row.courseid);
            tr.dataset.index = String(index);
            const source = create('td');
            source.appendChild(create('div', 'tool-ceaorganizador-nombre', decode(row.fullname)));
            source.appendChild(create('div', 'tool-ceaorganizador-ruta', decode(row.shortname)));
            const dest = create('td');
            dest.appendChild(create('div', 'tool-ceaorganizador-ruta', decode(row.destpath)));
            if (row.newcategories.length > 0) {
                const paths = row.newcategories.map((path) => decode(path));
                const tag = create('span', 'tool-ceaorganizador-etiqueta', strings.clonenew);
                tag.title = paths.join('\n');
                dest.appendChild(tag);
                // Screen readers do not reach the tooltip: the same paths, visually hidden.
                const hidden = create('span', 'sr-only visually-hidden', ` ${paths.join('; ')}`);
                hidden.dataset.region = 'newcategories';
                dest.appendChild(hidden);
            }
            const names = create('td');
            [
                ['newfullname', strings.clonenewfullname, row.newfullname],
                ['newshortname', strings.clonenewshortname, row.newshortname],
            ]
                .forEach(([name, text, value]) => {
                    const input = create('input', 'form-control form-control-sm');
                    input.type = 'text';
                    input.dataset.field = name;
                    input.value = value;
                    input.disabled = Boolean(row.error);
                    input.setAttribute('aria-label', `${text}: ${decode(row.fullname)}`);
                    input.addEventListener('input', () => {
                        showState(tr, row);
                        refresh();
                    });
                    names.appendChild(input);
                });
            const state = create('td', 'tool-ceaorganizador-error');
            state.dataset.region = 'state';
            tr.append(source, dest, names, state);
            tbody.appendChild(tr);
            showState(tr, row);
        });
        tablewrap.hidden = rows.length === 0;
        total.textContent = format(strings.clonetotal, rows.length);
        total.hidden = false;
        refresh();
    };

    const readForm = () => {
        const periodid = Number(select.value);
        const periodlabel = label.value.trim();
        const startdate = dateToTimestamp(start.value);
        const enddate = dateToTimestamp(end.value);
        if (!periodid) {
            return {error: strings.cloneerror_noperiod};
        }
        if (!isValidLabel(periodlabel)) {
            return {error: strings.cloneerror_label};
        }
        if (startdate === null || enddate === null || enddate <= startdate) {
            return {error: strings.cloneerror_dates};
        }
        return {periodid, periodlabel, startdate, enddate, container: container.value.trim()};
    };

    // Loads the preview of `wanted`, the values of a form already validated.
    const load = async(wanted) => {
        const mine = ++token;
        busy = true;
        previewButton.disabled = true;
        try {
            const result = await api.clonePreview({items, ...wanted});
            if (mine === token) {
                plan = wanted;
                rows = result.rows;
                hash = result.hash;
                drawRows();
            }
        } catch (error) {
            onError(error);
        } finally {
            busy = false;
            previewButton.disabled = options.length === 0;
            refresh();
        }
    };

    const preview = async() => {
        if (busy) {
            return;
        }
        const wanted = readForm();
        if (wanted.error) {
            say(wanted.error);
            return;
        }
        say('');
        await load(wanted);
    };

    // Changing the form makes the preview obsolete: the apply button waits for a new one.
    const invalidate = () => {
        plan = null;
        refresh();
    };
    select.addEventListener('change', () => {
        suggest();
        invalidate();
    });
    label.addEventListener('input', () => {
        labelEdited = true;
        invalidate();
    });
    container.addEventListener('input', () => {
        containerEdited = true;
        invalidate();
    });
    start.addEventListener('input', invalidate);
    end.addEventListener('input', invalidate);

    const apply = async() => {
        const sent = pending();
        if (busy || plan === null || sent.length === 0) {
            return;
        }
        const wanted = plan;
        busy = true;
        previewButton.disabled = true;
        applyButton.disabled = true;
        let result = null;
        try {
            result = await api.cloneApply({items, ...wanted, rows: sent, hash});
        } catch (error) {
            busy = false;
            previewButton.disabled = false;
            refresh();
            onError(error);
            return;
        }
        if (result.status === 'stale') {
            busy = false;
            say(strings.clonestale);
            await load(wanted);
            return;
        }
        dialog.close();
        if (result.batchid > 0) {
            openProgress(result.batchid, {api, strings, onError, trigger});
        } else {
            toast.show(strings.clonenothing);
        }
    };

    previewButton.addEventListener('click', preview);
    applyButton.addEventListener('click', apply);
    (options.length ? select : closeButton).focus();
    return {close: dialog.close, element: root};
};
