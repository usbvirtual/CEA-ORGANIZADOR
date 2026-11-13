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
 * Suggestions panel: the corrections that suggest_preview proposes, grouped by category, each one
 * with a checkbox, an editable new name and the reasons; the chosen ones go to rename_apply.
 *
 * The names that suggest_preview and rename_apply carry are RAW: they are never decoded and are only
 * written with textContent or as an input value. Only the group (a formatted path) is decoded. The
 * modules of Moodle arrive in the options, so this one runs in node:test with jsdom.
 *
 * @module     tool_ceaorganizador/suggestpanel
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {openDialog} from 'tool_ceaorganizador/dialog';
import {format} from 'tool_ceaorganizador/treeview';
import {exclusive} from 'tool_ceaorganizador/selection';
import {splitBatches} from 'tool_ceaorganizador/renamepanel';

const MAX_BATCH = 100;
const MAX_NAME = 254;
const REASONS = ['formato', 'glosario', 'tilde', 'variante'];

let counter = 0;

/**
 * Opens the panel.
 *
 * @param {Object} options
 * @param {Object} options.api suggestPreview and renameApply.
 * @param {Object} options.strings Strings of the panel.
 * @param {Object} options.toast Object with show(message, options).
 * @param {Function} options.onError Receives the exceptions of the web services.
 * @param {Function} options.undo Undoes a journal entry (async; resolves {ok} or nothing).
 * @param {Function} options.reload Reloads the tree from the server.
 * @param {Function} options.decode Decodes the entities of formatted text (the group).
 * @param {Number} [options.categoryid] Category and its descendants; 0 or none is the whole site.
 * @param {Element} [options.trigger] Gets the focus back on close.
 * @param {Document} [options.doc]
 * @returns {{close: Function, element: Element}}
 */
export const open = ({api, strings, toast, onError, undo, reload, decode, categoryid = 0, trigger, doc = document}) => {
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
    const checkbox = (field, label) => {
        counter += 1;
        const wrap = create('div', 'custom-control form-check custom-checkbox');
        const input = create('input', 'custom-control-input form-check-input');
        input.type = 'checkbox';
        input.id = `tool-ceaorganizador-suggest-${counter}`;
        input.dataset.field = field;
        const text = create('label', 'custom-control-label form-check-label', label);
        text.htmlFor = input.id;
        wrap.append(input, text);
        return {wrap, input};
    };

    let closed = false;
    const dialog = openDialog({
        doc,
        title: strings.suggest,
        trigger,
        className: 'tool-ceaorganizador-dialogo-ancho',
        onClose: () => {
            closed = true;
        },
    });
    const root = dialog.element;

    const courses = checkbox('includecourses', strings.suggestcourses);
    courses.input.checked = true;
    const toggleButton = button('toggleall', strings.suggesttoggleall);
    const applyButton = button('apply', format(strings.suggestapply, 0), 'btn btn-primary');
    applyButton.disabled = true;
    const closeButton = button('close', strings.close, 'btn btn-link');
    closeButton.addEventListener('click', () => dialog.close());
    const actions = create('div', 'tool-ceaorganizador-acciones');
    actions.append(courses.wrap, toggleButton, applyButton, closeButton);

    const notice = create('p', 'tool-ceaorganizador-aviso');
    notice.dataset.region = 'notice';
    notice.setAttribute('role', 'status');
    notice.hidden = true;
    const list = create('div', 'tool-ceaorganizador-sugerencias');
    list.dataset.region = 'rows';
    root.append(actions, notice, list);

    let rows = [];
    let token = 0;
    let busy = false;

    const say = (text) => {
        notice.textContent = text;
        notice.hidden = text === '';
    };

    const checks = () => [...list.querySelectorAll('[data-field="selected"]')];

    // The changes that apply would send: checked rows whose new name is filled in, differs and can be saved.
    // rename_apply reads the new name as PARAM_TEXT, which strips tags, so a name with '<' is never sent.
    const pending = () => {
        const changes = [];
        list.querySelectorAll('[data-index]').forEach((node) => {
            const row = rows[Number(node.dataset.index)];
            const value = node.querySelector('[data-field="new"]').value.trim();
            if (!row || !node.querySelector('[data-field="selected"]').checked) {
                return;
            }
            if (value === '' || value === row.old || value.includes('<') || value.length > MAX_NAME) {
                return;
            }
            changes.push({type: row.type, id: row.id, old: row.old, new: value});
        });
        return changes;
    };

    const refresh = () => {
        const count = pending().length;
        applyButton.textContent = format(strings.suggestapply, count);
        applyButton.disabled = busy || count === 0;
        toggleButton.disabled = busy || rows.length === 0;
        courses.input.disabled = busy;
    };

    const setBusy = (value) => {
        busy = value;
        list.setAttribute('aria-busy', value ? 'true' : 'false');
        refresh();
    };

    const draw = (result) => {
        rows = result.rows;
        list.textContent = '';
        let group = null;
        let section = null;
        rows.forEach((row, index) => {
            if (section === null || row.group !== group) {
                group = row.group;
                section = create('section', 'tool-ceaorganizador-sugerencias-grupo');
                // The group is a formatted path (entities); the empty one is the top level.
                section.appendChild(create('h3', 'tool-ceaorganizador-sugerencias-titulo',
                    group === '' ? strings.suggestroot : decode(group)));
                list.appendChild(section);
            }
            const node = create('div', 'tool-ceaorganizador-sugerencia');
            node.dataset.index = String(index);
            node.dataset.key = `${row.type}:${row.id}`;
            const pick = checkbox('selected', row.old);
            pick.input.checked = true;
            pick.input.addEventListener('change', refresh);
            // The label of the checkbox is the old name, written as text.
            const edit = create('input', 'form-control form-control-sm');
            edit.type = 'text';
            edit.dataset.field = 'new';
            edit.setAttribute('aria-label', `${strings.after}: ${row.old}`);
            edit.value = row.new;
            edit.addEventListener('input', refresh);
            const chips = create('span', 'tool-ceaorganizador-razones');
            row.reasons.forEach((code) => {
                const text = REASONS.includes(code) ? strings[`reason_${code}`] : code;
                const chip = create('span', 'tool-ceaorganizador-insignia', text);
                chip.dataset.reason = code;
                chips.appendChild(chip);
            });
            node.append(pick.wrap, edit, chips);
            section.appendChild(node);
        });
        if (rows.length === 0) {
            say(strings.suggestnone);
        } else if (result.truncated) {
            say(format(strings.suggesttruncated, result.total));
        } else {
            say('');
        }
    };

    const load = exclusive(async() => {
        const mine = ++token;
        setBusy(true);
        try {
            const result = await api.suggestPreview({categoryid, includecourses: courses.input.checked});
            if (mine === token) {
                draw(result);
            }
        } catch (error) {
            onError(error);
        } finally {
            setBusy(false);
        }
    });

    // One undo that goes back through the batches, the last one first, and stops at the first conflict.
    const undoAll = async(logids) => {
        for (const logid of [...logids].reverse()) {
            const result = await undo(logid);
            if (!result || !result.ok) {
                break;
            }
        }
        // The panel is stale after an undo: load it again if it is still open.
        if (!closed) {
            load();
        }
    };

    const apply = exclusive(async() => {
        const changes = pending();
        if (changes.length === 0) {
            return;
        }
        setBusy(true);
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
        if (logids.length > 0) {
            // Without per-row results the batches that were recorded count as applied.
            let message = format(strings.renamedone, done || changes.length);
            if (skipped > 0) {
                message += ` ${strings.renameskipped}`;
            }
            toast.show(message, {actionLabel: strings.undo, onAction: () => undoAll(logids)});
        } else if (!failure) {
            say(strings.renameskipped);
        }
        try {
            if (logids.length > 0) {
                await reload();
            }
        } catch (error) {
            failure = failure || error;
        }
        busy = false;
        if (failure) {
            onError(failure);
        }
        // The panel shows what is left after the renames.
        await load();
        refresh();
    });

    courses.input.addEventListener('change', () => load());
    toggleButton.addEventListener('click', () => {
        if (busy) {
            return;
        }
        const all = checks().every((check) => check.checked);
        checks().forEach((check) => {
            check.checked = !all;
        });
        refresh();
    });
    applyButton.addEventListener('click', () => apply());
    load();
    courses.input.focus();
    return {close: dialog.close, element: root};
};
