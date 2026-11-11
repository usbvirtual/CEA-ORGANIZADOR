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
 * Auto sort panel: scope, preview of what changes (before and after) and confirm.
 *
 * Names and paths of the preview are formatted strings: they go through decode()
 * and are written with textContent. The modules of Moodle arrive in the options.
 *
 * @module     tool_ceaorganizador/sortpanel
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {openDialog} from 'tool_ceaorganizador/dialog';

let counter = 0;

/**
 * Opens the panel.
 *
 * @param {Object} options
 * @param {Object} options.api sortPreview and sortApply.
 * @param {Object} options.strings Strings of the panel.
 * @param {Object} options.toast Object with show(message, options).
 * @param {Function} options.onError Receives the exceptions of the web services.
 * @param {Function} options.undo Undoes a journal entry.
 * @param {Function} options.reload Reloads the tree and the summary from the server.
 * @param {Number} [options.categoryid] Category to sort, 0 for the whole site; ignored with categoryids.
 * @param {Number[]} [options.categoryids] Several categories to sort at once, with one preview and one undo.
 * @param {String[]} [options.paths] Formatted paths of those categories, for the subtitle.
 * @param {String} [options.path] Formatted path of the category, for the subtitle.
 * @param {Boolean} [options.recursive] Initial state of the checkbox; true for the whole site.
 * @param {Function} [options.decode] Turns a formatted string into plain text.
 * @param {Element} [options.trigger] Gets the focus back on close.
 * @param {Document} [options.doc]
 * @returns {{close: Function, element: Element, load: Function}}
 */
export const open = ({
    api, strings, toast, onError, undo, reload, categoryid = 0, categoryids = [], paths = [], path = '', recursive, decode = (text) => text, trigger,
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

    const dialog = openDialog({doc, title: strings.autosort, trigger, className: 'tool-ceaorganizador-dialogo-ancho'});
    const root = dialog.element;
    const many = categoryids.length > 0;
    if (many) {
        const count = create('p', 'tool-ceaorganizador-ruta', String(strings.sortselectedcount).replace('{$a}', categoryids.length));
        count.dataset.region = 'selectedcount';
        const joined = paths.map((entry) => decode(entry)).join(' | ');
        count.title = joined;
        // Screen readers do not reach the tooltip: the same list, visually hidden.
        const hidden = create('span', 'sr-only visually-hidden', ` ${joined}`);
        hidden.dataset.region = 'selectedpaths';
        count.appendChild(hidden);
        root.appendChild(count);
    } else if (path) {
        root.appendChild(create('p', 'tool-ceaorganizador-ruta', decode(path)));
    }

    counter += 1;
    const controls = create('div', 'tool-ceaorganizador-controles');
    const scopeLabel = create('label', 'tool-ceaorganizador-campo');
    scopeLabel.appendChild(create('span', '', strings.autosortscope));
    const scope = create('select', 'custom-select form-select');
    scope.dataset.field = 'scope';
    [
        ['both', strings.autosortscope_both],
        ['categories', strings.autosortscope_categories],
        ['courses', strings.autosortscope_courses],
    ].forEach(([value, text]) => {
        const option = create('option', '', text);
        option.value = value;
        scope.appendChild(option);
    });
    scopeLabel.appendChild(scope);
    const check = create('div', 'custom-control form-check custom-checkbox');
    const deep = create('input', 'custom-control-input form-check-input');
    deep.type = 'checkbox';
    deep.id = `tool-ceaorganizador-sort-${counter}-recursive`;
    deep.dataset.field = 'recursive';
    deep.checked = recursive === undefined ? (!many && categoryid === 0) : Boolean(recursive);
    const deepLabel = create('label', 'custom-control-label form-check-label', strings.autosortrecursive);
    deepLabel.htmlFor = deep.id;
    check.append(deep, deepLabel);
    controls.append(scopeLabel, check);

    const notice = create('p', 'tool-ceaorganizador-aviso');
    notice.dataset.region = 'notice';
    notice.setAttribute('role', 'status');
    notice.hidden = true;
    const preview = create('div', 'tool-ceaorganizador-vista');
    preview.dataset.region = 'preview';
    preview.setAttribute('aria-live', 'polite');
    const confirm = create('button', 'btn btn-primary', strings.confirm);
    confirm.type = 'button';
    confirm.dataset.action = 'confirm';
    confirm.disabled = true;
    const cancel = create('button', 'btn btn-link', strings.close);
    cancel.type = 'button';
    cancel.addEventListener('click', () => dialog.close());
    const actions = create('div', 'tool-ceaorganizador-acciones');
    actions.append(confirm, cancel);
    root.append(controls, notice, preview, actions);

    let hash = '';
    let token = 0;
    let busy = false;

    const say = (text) => {
        notice.textContent = text;
        notice.hidden = text === '';
    };

    const params = () => (many
        ? {categoryid: 0, categoryids, scope: scope.value, recursive: deep.checked}
        : {categoryid, scope: scope.value, recursive: deep.checked});

    const column = (heading, list) => {
        const box = create('div', 'tool-ceaorganizador-columna');
        box.appendChild(create('h4', 'tool-ceaorganizador-columna-titulo', heading));
        const ol = create('ol');
        list.forEach((entry) => ol.appendChild(create('li', '', decode(entry.name))));
        box.appendChild(ol);
        return box;
    };

    const draw = (result) => {
        preview.textContent = '';
        hash = result.hash;
        confirm.disabled = busy || result.changes.length === 0;
        if (result.changes.length === 0) {
            preview.appendChild(create('p', '', strings.autosortnothing));
            return;
        }
        preview.appendChild(create('h3', 'tool-ceaorganizador-vista-titulo', strings.autosortpreview));
        result.changes.forEach((change) => {
            const block = create('section', 'tool-ceaorganizador-cambio');
            block.dataset.kind = change.kind;
            const label = strings[`autosortscope_${change.kind}`] || '';
            const where = change.parentpath ? decode(change.parentpath) : (path ? decode(path) : '');
            block.appendChild(create('h4', 'tool-ceaorganizador-cambio-titulo', [where, label].filter(Boolean).join(' · ')));
            const columns = create('div', 'tool-ceaorganizador-columnas');
            columns.append(column(strings.before, change.before), column(strings.after, change.after));
            block.appendChild(columns);
            preview.appendChild(block);
        });
    };

    // Loads the preview; only the latest request draws.
    const load = async() => {
        const mine = ++token;
        confirm.disabled = true;
        try {
            const result = await api.sortPreview(params());
            if (mine === token) {
                draw(result);
            }
        } catch (error) {
            onError(error);
        }
    };

    const apply = async() => {
        if (busy || confirm.disabled) {
            return;
        }
        busy = true;
        confirm.disabled = true;
        let result = null;
        try {
            result = await api.sortApply({...params(), hash});
        } catch (error) {
            busy = false;
            confirm.disabled = false;
            onError(error);
            return;
        }
        busy = false;
        if (result.status !== 'ok') {
            say(strings.stale);
            await load();
            return;
        }
        dialog.close();
        if (result.logid > 0) {
            toast.show(strings.autosortdone, {actionLabel: strings.undo, onAction: () => undo(result.logid)});
            await reload();
        } else {
            toast.show(strings.autosortnothing);
        }
    };

    scope.addEventListener('change', () => {
        say('');
        load();
    });
    deep.addEventListener('change', () => {
        say('');
        load();
    });
    confirm.addEventListener('click', apply);
    load();
    scope.focus();
    return {close: dialog.close, element: root, load};
};
