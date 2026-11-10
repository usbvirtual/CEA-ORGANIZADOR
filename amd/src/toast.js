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
 * Transient notice with an optional action (pure DOM, no Moodle modules).
 *
 * @module     tool_ceaorganizador/toast
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Shows a notice.
 *
 * @param {String} message Plain text.
 * @param {Object} [options]
 * @param {String} [options.actionLabel] Label of the action button.
 * @param {Function} [options.onAction] Called when the action is pressed.
 * @param {Number} [options.timeout=8000] Milliseconds before it closes itself.
 * @param {Element} [options.container] Where to add it; the page body by default.
 * @returns {{dismiss: Function}}
 */
export const show = (message, {actionLabel, onAction, timeout = 8000, container} = {}) => {
    const parent = container || document.body;
    const doc = parent.ownerDocument;
    const el = doc.createElement('div');
    el.className = 'tool-ceaorganizador-toast';
    el.setAttribute('role', 'status');
    const text = doc.createElement('span');
    text.textContent = message;
    el.appendChild(text);

    let timer = null;
    const dismiss = () => {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
        el.remove();
    };

    if (actionLabel) {
        const button = doc.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-secondary btn-sm';
        button.textContent = actionLabel;
        button.addEventListener('click', () => {
            dismiss();
            if (onAction) {
                onAction();
            }
        });
        el.appendChild(button);
    }

    parent.appendChild(el);
    timer = setTimeout(dismiss, timeout);
    return {dismiss};
};
