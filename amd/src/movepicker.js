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
 * The "Move to..." dialog: a searchable list of destination categories.
 *
 * Plain DOM, no Moodle modules. Every name is written with textContent.
 *
 * @module     tool_ceaorganizador/movepicker
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {canDrop} from 'tool_ceaorganizador/dropzones';
import {format} from 'tool_ceaorganizador/treeview';

const SEPARATOR = ' / ';
const FOCUSABLE = 'input, button:not([disabled]), [href], [tabindex]:not([tabindex="-1"])';

/**
 * Opens the dialog.
 *
 * @param {Object} options
 * @param {Object} options.model Model from treemodel.createModel().
 * @param {Object} options.dragged {type, id[, category]} of the item to move.
 * @param {String} options.name Plain name of the item, for the title.
 * @param {Element} options.trigger Element that had the focus; it gets it back on close.
 * @param {Object} options.strings movetitle and moveto.
 * @param {Function} options.onPick Called with the id of the chosen category.
 * @param {Function} [options.decode] Turns a formatted name into plain text.
 * @param {Document} [options.doc] Document where the dialog is drawn.
 * @returns {{close: Function, element: Element}}
 */
export const open = ({model, dragged, name, trigger, strings, onPick, decode = (text) => text, doc = document}) => {
    const create = (tag, className) => {
        const node = doc.createElement(tag);
        if (className) {
            node.className = className;
        }
        return node;
    };

    const overlay = create('div', 'tool-ceaorganizador tool-ceaorganizador-dialogo-fondo');
    const dialog = create('div', 'tool-ceaorganizador-dialogo');
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    const title = create('h2', 'tool-ceaorganizador-dialogo-titulo');
    title.id = `tool-ceaorganizador-dialog-${dragged.type}-${dragged.id}-title`;
    title.textContent = format(strings.movetitle, name);
    dialog.setAttribute('aria-labelledby', title.id);
    const input = create('input', 'form-control');
    input.type = 'search';
    input.setAttribute('aria-label', strings.moveto);
    const list = create('ul', 'tool-ceaorganizador-dialogo-lista');
    dialog.append(title, input, list);
    overlay.appendChild(dialog);

    // Tree order: each category followed by its subcategories.
    const ordered = [];
    const walk = (parent) => model.children(parent).forEach((row) => {
        ordered.push(row.id);
        walk(row.id);
    });
    walk(0);
    const choices = ordered.filter((id) => canDrop(model, dragged, {type: 'category', id}, 'inside'));

    const draw = () => {
        const shown = model.filter(input.value);
        list.textContent = '';
        choices.filter((id) => shown.has(id)).forEach((id) => {
            const item = create('li');
            const button = create('button', 'tool-ceaorganizador-dialogo-opcion btn btn-link');
            button.type = 'button';
            button.textContent = decode(model.pathOf(id).join(SEPARATOR));
            button.addEventListener('click', () => {
                close();
                onPick(id);
            });
            item.appendChild(button);
            list.appendChild(item);
        });
    };

    function close() {
        doc.removeEventListener('keydown', onKey, true);
        overlay.remove();
        if (trigger && typeof trigger.focus === 'function') {
            trigger.focus();
        }
    }

    function onKey(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        const nodes = [...dialog.querySelectorAll(FOCUSABLE)];
        const first = nodes[0];
        const last = nodes[nodes.length - 1];
        if (!dialog.contains(doc.activeElement)) {
            event.preventDefault();
            first.focus();
        } else if (event.shiftKey && doc.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && doc.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            close();
        }
    });
    input.addEventListener('input', draw);
    doc.addEventListener('keydown', onKey, true);
    draw();
    doc.body.appendChild(overlay);
    input.focus();
    return {close, element: dialog};
};
