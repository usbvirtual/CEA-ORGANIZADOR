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
 * "Copias en curso" panel: polls clone_status while something is pending, shows the counters
 * and a link to every new course, and warns when nothing moves for a long time (the copies
 * run in the Moodle cron).
 *
 * The names of clone_status are RAW: they are only written with textContent. The timers are
 * injectable so that node:test can drive them.
 *
 * @module     tool_ceaorganizador/cloneprogress
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {openDialog} from 'tool_ceaorganizador/dialog';

const SAFE_URL = /^(https?:\/\/|\/)/i;

/**
 * Opens the panel and starts polling.
 *
 * @param {Number} batchid Journal id returned by clone_apply.
 * @param {Object} options
 * @param {Object} options.api cloneStatus(batchid).
 * @param {Object} options.strings Strings of the panel.
 * @param {Function} options.onError Receives the exception of the web service once the retries are spent;
 *      polling stops then.
 * @param {Number} [options.interval] Milliseconds between queries.
 * @param {Number[]} [options.retries] Milliseconds to wait before each retry of a failed query.
 * @param {Number} [options.cronwarning] Milliseconds without progress before the warning.
 * @param {Object} [options.timers] {setTimeout, clearTimeout, now}, to drive the time in tests.
 * @param {Element} [options.trigger] Gets the focus back on close.
 * @param {Document} [options.doc]
 * @returns {{close: Function, element: Element, poll: Function}}
 */
export const open = (batchid, {
    api,
    strings,
    onError,
    interval = 15000,
    retries = [15000, 30000, 60000],
    cronwarning = 600000,
    timers = {
        setTimeout: (fn, ms) => setTimeout(fn, ms),
        clearTimeout: (id) => clearTimeout(id),
        now: () => Date.now(),
    },
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

    let timer = null;
    let closed = false;
    const dialog = openDialog({
        doc,
        title: strings.cloneprogress,
        trigger,
        className: 'tool-ceaorganizador-dialogo-ancho',
        onClose: () => {
            closed = true;
            if (timer !== null) {
                timers.clearTimeout(timer);
                timer = null;
            }
        },
    });
    const root = dialog.element;
    const counters = create('p', 'tool-ceaorganizador-contadores');
    counters.dataset.region = 'counters';
    counters.setAttribute('role', 'status');
    const warning = create('p', 'tool-ceaorganizador-aviso', strings.cronwarning);
    warning.dataset.region = 'cronwarning';
    warning.hidden = true;
    const list = create('ul', 'tool-ceaorganizador-copias');
    list.dataset.region = 'copies';
    const closeButton = create('button', 'btn btn-link', strings.close);
    closeButton.type = 'button';
    closeButton.dataset.action = 'close';
    closeButton.addEventListener('click', () => dialog.close());
    const actions = create('div', 'tool-ceaorganizador-acciones');
    actions.appendChild(closeButton);
    root.append(counters, warning, list, actions);

    const labels = {
        done: strings.clonedone,
        running: strings.clonepending,
        pending: strings.clonepending,
        failed: strings.clonefailed,
    };

    // What is on screen: the list is redrawn only when it changes, so a focused link keeps the focus.
    let drawn = null;

    const draw = (result) => {
        const shown = JSON.stringify([
            result.done, result.pending, result.failed,
            result.rows.map((row) => [row.source, row.status, row.url, row.newfullname, row.reason || '']),
        ]);
        if (shown === drawn) {
            return;
        }
        drawn = shown;
        counters.textContent = [
            `${strings.clonedone}: ${result.done}`,
            `${strings.clonepending}: ${result.pending}`,
            `${strings.clonefailed}: ${result.failed}`,
        ].join(' · ');
        list.textContent = '';
        result.rows.forEach((row) => {
            const li = create('li', 'tool-ceaorganizador-copia');
            li.dataset.status = row.status;
            if (row.url && SAFE_URL.test(row.url)) {
                const link = create('a', 'tool-ceaorganizador-nombre');
                link.setAttribute('href', row.url);
                link.setAttribute('target', '_blank');
                link.setAttribute('rel', 'noopener');
                link.textContent = row.newfullname;
                li.appendChild(link);
            } else {
                li.appendChild(create('span', 'tool-ceaorganizador-nombre', row.newfullname));
            }
            li.appendChild(create('span', 'tool-ceaorganizador-insignia', labels[row.status] || row.status));
            // Optional: only copies that could not be queued carry it, and older servers send none.
            if (row.status === 'failed' && typeof row.reason === 'string' && row.reason !== '') {
                const reason = create('span', 'tool-ceaorganizador-ruta', row.reason);
                reason.dataset.region = 'reason';
                li.appendChild(reason);
            }
            list.appendChild(li);
        });
    };

    let signature = null;
    let lastProgress = timers.now();
    let failures = 0;

    async function poll() {
        timer = null;
        if (closed) {
            return;
        }
        let result;
        try {
            result = await api.cloneStatus(batchid);
        } catch (error) {
            if (closed) {
                return;
            }
            // A dropped request should not end the follow-up: retry later, and give up only after the last wait.
            if (failures < retries.length) {
                timer = timers.setTimeout(poll, retries[failures]);
                failures += 1;
                return;
            }
            onError(error);
            return;
        }
        if (closed) {
            return;
        }
        failures = 0;
        draw(result);
        const current = `${result.done}/${result.pending}/${result.failed}`;
        if (current !== signature) {
            signature = current;
            lastProgress = timers.now();
        }
        warning.hidden = !(result.pending > 0 && timers.now() - lastProgress >= cronwarning);
        if (result.pending > 0) {
            timer = timers.setTimeout(poll, interval);
        }
    }

    poll();
    return {close: dialog.close, element: root, poll};
};
