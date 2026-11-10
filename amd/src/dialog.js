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
 * Modal dialog shell shared by the move picker and the panels: role=dialog,
 * focus trap, Esc to close and focus restore. Plain DOM, no Moodle modules.
 *
 * @module     tool_ceaorganizador/dialog
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const FOCUSABLE = 'input:not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), '
    + '[href], [tabindex]:not([tabindex="-1"])';

let counter = 0;

/**
 * Opens an empty dialog with a title; the caller appends its content to `element`.
 *
 * @param {Object} options
 * @param {Document} [options.doc] Document where the dialog is drawn.
 * @param {String} options.title Plain title, written with textContent.
 * @param {Element} [options.trigger] Element that had the focus; it gets it back on close.
 * @param {String} [options.className] Extra class of the dialog (for example a wider one).
 * @param {Function} [options.onClose] Called once when the dialog closes.
 * @returns {{close: Function, element: Element, overlay: Element, heading: Element}}
 */
export const openDialog = ({doc = document, title, trigger, className = '', onClose}) => {
    const create = (tag, classes) => {
        const node = doc.createElement(tag);
        if (classes) {
            node.className = classes;
        }
        return node;
    };

    const overlay = create('div', 'tool-ceaorganizador tool-ceaorganizador-dialogo-fondo');
    const element = create('div', `tool-ceaorganizador-dialogo ${className}`.trim());
    element.setAttribute('role', 'dialog');
    element.setAttribute('aria-modal', 'true');
    const heading = create('h2', 'tool-ceaorganizador-dialogo-titulo');
    counter += 1;
    heading.id = `tool-ceaorganizador-dialog-${counter}-title`;
    heading.textContent = title;
    element.setAttribute('aria-labelledby', heading.id);
    element.appendChild(heading);
    overlay.appendChild(element);

    let closed = false;

    function close() {
        if (closed) {
            return;
        }
        closed = true;
        doc.removeEventListener('keydown', onKey, true);
        overlay.remove();
        if (trigger && typeof trigger.focus === 'function') {
            trigger.focus();
        }
        if (onClose) {
            onClose();
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
        const nodes = [...element.querySelectorAll(FOCUSABLE)];
        if (!nodes.length) {
            event.preventDefault();
            return;
        }
        const first = nodes[0];
        const last = nodes[nodes.length - 1];
        if (!element.contains(doc.activeElement)) {
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
    doc.addEventListener('keydown', onKey, true);
    doc.body.appendChild(overlay);
    return {close, element, overlay, heading};
};
