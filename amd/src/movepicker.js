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
import {normalize} from 'tool_ceaorganizador/treemodel';
import {isFirstAtRoot} from 'tool_ceaorganizador/mover';
import {openDialog} from 'tool_ceaorganizador/dialog';

const SEPARATOR = ' / ';

/**
 * Opens the dialog.
 *
 * @param {Object} options
 * @param {Object} options.model Model from treemodel.createModel().
 * @param {Object} options.dragged {type, id[, category]} of the item to move.
 * @param {String} options.name Plain name of the item, for the title.
 * @param {Element} options.trigger Element that had the focus; it gets it back on close.
 * @param {Object} options.strings movetitle, moveto and movetoroot.
 * @param {Function} options.onPick Called with the id of the chosen category, 0 for the top level.
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

    const {close, element: dialog} = openDialog({
        doc,
        title: format(strings.movetitle, name),
        trigger,
    });
    const input = create('input', 'form-control');
    input.type = 'search';
    input.setAttribute('aria-label', strings.moveto);
    const list = create('ul', 'tool-ceaorganizador-dialogo-lista');
    dialog.append(input, list);

    // Tree order: each category followed by its subcategories.
    const ordered = [];
    const walk = (parent) => model.children(parent).forEach((row) => {
        ordered.push(row.id);
        walk(row.id);
    });
    walk(0);
    const choices = ordered.filter((id) => canDrop(model, dragged, {type: 'category', id}, 'inside'));

    // The top level is offered to categories that are not already its first.
    const offerRoot = dragged.type === 'category' && !isFirstAtRoot(model, dragged.id);

    const addOption = (label, id) => {
        const item = create('li');
        const button = create('button', 'tool-ceaorganizador-dialogo-opcion btn btn-link');
        button.type = 'button';
        button.textContent = label;
        button.addEventListener('click', () => {
            close();
            onPick(id);
        });
        item.appendChild(button);
        list.appendChild(item);
    };

    const draw = () => {
        const shown = model.filter(input.value);
        list.textContent = '';
        if (offerRoot && normalize(strings.movetoroot).includes(normalize(input.value).trim())) {
            addOption(strings.movetoroot, 0);
        }
        choices.filter((id) => shown.has(id)).forEach((id) => addOption(decode(model.pathOf(id).join(SEPARATOR)), id));
    };

    input.addEventListener('input', draw);
    draw();
    input.focus();
    return {close, element: dialog};
};
