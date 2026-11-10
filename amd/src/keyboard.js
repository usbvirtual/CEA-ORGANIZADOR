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
 * Moving with the keyboard: grab with Space, choose the destination with the arrows.
 *
 * It listens before the tree view does (capture phase) and only takes over
 * Space on a focused item and, once something is grabbed, every key.
 *
 * @module     tool_ceaorganizador/keyboard
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {destinationFor} from 'tool_ceaorganizador/dropzones';
import {describeItem, courseIds, markDrop, clearMarks} from 'tool_ceaorganizador/dragdrop';
import {format} from 'tool_ceaorganizador/treeview';

const ZONES = {category: ['before', 'inside', 'after'], course: ['before', 'after']};

const nameOf = (item) => {
    const name = item.querySelector(':scope > .tool-ceaorganizador-fila > .tool-ceaorganizador-nombre');
    return name ? name.textContent : '';
};

/**
 * Enables the keyboard moves on the tree.
 *
 * @param {Element} container Tree element.
 * @param {Object} ctx Live context: model and strings (grabbed, dropbefore, dropinside, dropafter, dropcancelled, invaliddrop).
 * @param {Object} hooks
 * @param {Function} hooks.onDrop Called with (dragged, destination, {focus: true}) when Enter drops.
 * @param {Function} [hooks.onMoveTo] Called with the focused item when "m" is pressed.
 * @returns {{cancel: Function}}
 */
export const attach = (container, ctx, {onDrop, onMoveTo}) => {
    const doc = container.ownerDocument;
    const live = doc.createElement('div');
    live.className = 'tool-ceaorganizador-solo-lector';
    live.setAttribute('aria-live', 'polite');
    live.setAttribute('role', 'status');
    container.insertAdjacentElement('afterend', live);
    let grab = null;

    const announce = (text) => {
        live.textContent = '';
        live.textContent = text;
    };

    const visibleItems = () => [...container.querySelectorAll('[role="treeitem"]')]
        .filter((item) => !item.parentElement.closest('[role="group"][hidden]'));

    // Valid destinations in document order, without repeating a position reached from two rows.
    const candidatesFor = (dragged) => {
        const seen = new Set();
        const list = [];
        visibleItems().forEach((item) => {
            const target = describeItem(item);
            ZONES[target.type].forEach((zone) => {
                const dest = destinationFor(ctx.model, dragged, target, zone, (id) => courseIds(container, id));
                if (!dest) {
                    return;
                }
                const key = JSON.stringify(dest);
                if (!seen.has(key)) {
                    seen.add(key);
                    list.push({item, zone, dest, name: nameOf(item)});
                }
            });
        });
        return list;
    };

    const finish = () => {
        if (grab) {
            grab.item.classList.remove('tool-ceaorganizador-grabbed');
            clearMarks(container);
        }
        grab = null;
    };

    const cancel = () => {
        if (grab) {
            finish();
            announce(ctx.strings.dropcancelled);
        }
    };

    const choose = (step) => {
        const count = grab.candidates.length;
        if (!count) {
            announce(ctx.strings.invaliddrop);
            return;
        }
        grab.index = grab.index < 0 ? (step > 0 ? 0 : count - 1) : (grab.index + step + count) % count;
        const picked = grab.candidates[grab.index];
        markDrop(container, picked.item, picked.zone, true);
        if (typeof picked.item.scrollIntoView === 'function') {
            picked.item.scrollIntoView({block: 'nearest'});
        }
        announce(format(ctx.strings[`drop${picked.zone}`], picked.name));
    };

    const drop = () => {
        const picked = grab.candidates[grab.index];
        if (!picked) {
            announce(ctx.strings.invaliddrop);
            return;
        }
        const dragged = grab.dragged;
        finish();
        onDrop(dragged, picked.dest, {focus: true});
    };

    container.addEventListener('keydown', (event) => {
        if (event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }
        const item = event.target.closest ? event.target.closest('[role="treeitem"]') : null;
        if (!grab) {
            if (!item || event.target !== item) {
                return;
            }
            if (event.key === ' ') {
                const dragged = describeItem(item);
                grab = {item, dragged, candidates: candidatesFor(dragged), index: -1};
                item.classList.add('tool-ceaorganizador-grabbed');
                announce(format(ctx.strings.grabbed, nameOf(item)));
            } else if (event.key === 'm' && onMoveTo) {
                onMoveTo(item);
            } else {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            return;
        }
        if (event.key === 'Tab') {
            cancel();
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        switch (event.key) {
            case 'ArrowDown':
                choose(1);
                break;
            case 'ArrowUp':
                choose(-1);
                break;
            case 'Enter':
                drop();
                break;
            case 'Escape':
                cancel();
                break;
            default:
        }
    }, true);

    return {cancel};
};
