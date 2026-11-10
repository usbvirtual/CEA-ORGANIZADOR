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
 * Moving with the mouse or touch: pointer events, a ghost and drop indicators.
 *
 * It imports no Moodle module. The rules live in dropzones; the request, the
 * undo notice and the revert in mover.
 *
 * @module     tool_ceaorganizador/dragdrop
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {zoneFor, canDrop, destinationFor, scrollDelta} from 'tool_ceaorganizador/dropzones';

const THRESHOLD = 5;
const ROW = '.tool-ceaorganizador-fila';
const MARKS = ['before', 'inside', 'after', 'invalid'].map((zone) => `tool-ceaorganizador-drop-${zone}`);

/**
 * Describes a treeitem for the drop rules.
 *
 * @param {Element} item Element with role="treeitem".
 * @returns {{type: String, id: Number, category: (Number|undefined)}} category only for courses.
 */
export const describeItem = (item) => {
    const described = {type: item.dataset.type, id: Number(item.dataset.id)};
    if (described.type === 'course') {
        const owner = item.parentElement.closest('[role="treeitem"]');
        described.category = owner ? Number(owner.dataset.id) : 0;
    }
    return described;
};

/**
 * Ids of the courses drawn under a category, in order.
 *
 * @param {Element} container Tree element.
 * @param {Number} categoryid
 * @returns {Number[]}
 */
export const courseIds = (container, categoryid) => {
    const owner = container.querySelector(`[data-type="category"][data-id="${categoryid}"]`);
    if (!owner) {
        return [];
    }
    return [...owner.querySelectorAll(':scope > [role="group"] > [data-type="course"]')]
        .map((node) => Number(node.dataset.id));
};

/**
 * Removes every drop indicator of the tree.
 *
 * @param {Element} container
 */
export const clearMarks = (container) => {
    container.querySelectorAll(`.${MARKS.join(', .')}`).forEach((node) => node.classList.remove(...MARKS));
};

/**
 * Marks the zone of an item where the drop would land.
 *
 * @param {Element} container
 * @param {Element} item
 * @param {String} zone 'before', 'inside' or 'after'.
 * @param {Boolean} valid False draws the invalid-drop outline instead.
 */
export const markDrop = (container, item, zone, valid) => {
    clearMarks(container);
    const row = item.querySelector(`:scope > ${ROW}`);
    if (row) {
        row.classList.add(valid ? `tool-ceaorganizador-drop-${zone}` : 'tool-ceaorganizador-drop-invalid');
    }
};

const scrollParent = (node) => {
    const win = node.ownerDocument.defaultView;
    for (let el = node.parentElement; el; el = el.parentElement) {
        const overflow = win.getComputedStyle(el).overflowY;
        if ((overflow === 'auto' || overflow === 'scroll') && el.scrollHeight > el.clientHeight) {
            return el;
        }
    }
    return null;
};

/**
 * Enables dragging of the tree items.
 *
 * @param {Element} container Tree element.
 * @param {Object} ctx Live context: model, and optionally toast and strings (invaliddrop).
 * @param {Object} hooks
 * @param {Function} hooks.onDrop Called with (dragged, destination) on a valid drop.
 * @returns {{destroy: Function}}
 */
export const attach = (container, ctx, {onDrop}) => {
    const doc = container.ownerDocument;
    const win = doc.defaultView;
    const siblingsOf = (id) => courseIds(container, id);
    let press = null;
    let drag = null;
    let suppress = false;

    const onKey = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            // eslint-disable-next-line no-use-before-define
            end();
        }
    };

    function end() {
        if (drag) {
            clearInterval(drag.timer);
            drag.ghost.remove();
            drag.item.classList.remove('tool-ceaorganizador-grabbed');
            try {
                drag.row.releasePointerCapture(drag.pointerId);
            } catch (e) {
                // The capture was already gone.
            }
            container.classList.remove('tool-ceaorganizador-arrastrando');
            clearMarks(container);
            doc.removeEventListener('keydown', onKey, true);
        }
        press = null;
        drag = null;
    }

    const autoscroll = () => {
        if (!drag) {
            return;
        }
        const scroller = scrollParent(container);
        if (scroller) {
            const box = scroller.getBoundingClientRect();
            scroller.scrollTop += scrollDelta(drag.y, box.top, box.bottom);
        } else {
            win.scrollBy(0, scrollDelta(drag.y, 0, win.innerHeight));
        }
    };

    const start = (event) => {
        const row = press.item.querySelector(`:scope > ${ROW}`);
        const box = row.getBoundingClientRect();
        const ghost = row.cloneNode(true);
        ghost.querySelectorAll('[id]').forEach((node) => node.removeAttribute('id'));
        ghost.setAttribute('aria-hidden', 'true');
        ghost.classList.add('tool-ceaorganizador-fantasma');
        ghost.style.width = `${box.width}px`;
        doc.body.appendChild(ghost);
        try {
            row.setPointerCapture(press.pointerId);
        } catch (e) {
            // Synthetic pointers cannot be captured; the events still reach the container.
        }
        press.item.classList.add('tool-ceaorganizador-grabbed');
        container.classList.add('tool-ceaorganizador-arrastrando');
        doc.addEventListener('keydown', onKey, true);
        drag = {
            item: press.item,
            row,
            ghost,
            pointerId: press.pointerId,
            dragged: describeItem(press.item),
            offsetX: press.x - box.left,
            offsetY: press.y - box.top,
            y: event.clientY,
            current: null,
            timer: setInterval(autoscroll, 16),
        };
    };

    const track = (event) => {
        drag.y = event.clientY;
        drag.ghost.style.left = `${event.clientX - drag.offsetX}px`;
        drag.ghost.style.top = `${event.clientY - drag.offsetY}px`;
        const under = typeof doc.elementFromPoint === 'function' ? doc.elementFromPoint(event.clientX, event.clientY) : null;
        const target = under ? under.closest('[role="treeitem"]') : null;
        if (!target || !container.contains(target)) {
            drag.current = null;
            clearMarks(container);
            return;
        }
        const box = target.querySelector(`:scope > ${ROW}`).getBoundingClientRect();
        const described = describeItem(target);
        const zone = zoneFor(event.clientY - box.top, box.height, described.type);
        const valid = canDrop(ctx.model, drag.dragged, described, zone);
        drag.current = {target: described, zone, valid};
        markDrop(container, target, zone, valid);
    };

    container.addEventListener('pointerdown', (event) => {
        if (event.button !== 0 || event.target.closest('button, input, a, select, textarea')) {
            return;
        }
        const row = event.target.closest(ROW);
        const item = row ? row.parentElement : null;
        if (!item || item.getAttribute('role') !== 'treeitem' || !container.contains(item)) {
            return;
        }
        press = {item, pointerId: event.pointerId, x: event.clientX, y: event.clientY};
    });

    container.addEventListener('pointermove', (event) => {
        if (!press || event.pointerId !== press.pointerId) {
            return;
        }
        if (!drag) {
            if (Math.hypot(event.clientX - press.x, event.clientY - press.y) < THRESHOLD) {
                return;
            }
            start(event);
        }
        track(event);
    });

    container.addEventListener('pointerup', (event) => {
        if (!drag) {
            press = null;
            return;
        }
        // The click that follows a drag must not expand or collapse the node.
        suppress = true;
        setTimeout(() => {
            suppress = false;
        }, 0);
        const {dragged, current} = drag;
        end();
        if (!current) {
            return;
        }
        if (!current.valid) {
            if (ctx.toast && ctx.strings) {
                ctx.toast.show(ctx.strings.invaliddrop);
            }
            return;
        }
        const dest = destinationFor(ctx.model, dragged, current.target, current.zone, siblingsOf);
        if (dest) {
            onDrop(dragged, dest);
        }
        event.preventDefault();
    });

    container.addEventListener('pointercancel', end);

    container.addEventListener('click', (event) => {
        if (suppress) {
            suppress = false;
            event.stopPropagation();
            event.preventDefault();
        }
    }, true);

    return {destroy: end};
};
