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
 * Entry point of the category organiser.
 *
 * @module     tool_ceaorganizador/organizer
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_strings as getStrings} from 'core/str';
import Notification from 'core/notification';
import * as api from 'tool_ceaorganizador/api';
import {createModel} from 'tool_ceaorganizador/treemodel';
import {decodeEntities} from 'tool_ceaorganizador/text';
import {render, format} from 'tool_ceaorganizador/treeview';
import * as toast from 'tool_ceaorganizador/toast';
import {createMover} from 'tool_ceaorganizador/mover';
import {attach as attachDrag, describeItem, courseIds} from 'tool_ceaorganizador/dragdrop';
import {attach as attachKeyboard} from 'tool_ceaorganizador/keyboard';
import {open as openPicker} from 'tool_ceaorganizador/movepicker';

const SEARCH_DELAY = 250;
const MIN_COURSE_SEARCH = 3;

// Literal shape, so that check-strings can verify every key.
const REQUESTS = [
    {key: 'coursecount', component: 'tool_ceaorganizador'},
    {key: 'dropafter', component: 'tool_ceaorganizador'},
    {key: 'dropbefore', component: 'tool_ceaorganizador'},
    {key: 'dropcancelled', component: 'tool_ceaorganizador'},
    {key: 'dropinside', component: 'tool_ceaorganizador'},
    {key: 'error_generic', component: 'tool_ceaorganizador'},
    {key: 'grabbed', component: 'tool_ceaorganizador'},
    {key: 'hidden', component: 'tool_ceaorganizador'},
    {key: 'invaliddrop', component: 'tool_ceaorganizador'},
    {key: 'loadmore', component: 'tool_ceaorganizador'},
    {key: 'moved', component: 'tool_ceaorganizador'},
    {key: 'movetitle', component: 'tool_ceaorganizador'},
    {key: 'moveto', component: 'tool_ceaorganizador'},
    {key: 'movetoroot', component: 'tool_ceaorganizador'},
    {key: 'needsort', component: 'tool_ceaorganizador'},
    {key: 'needsortsummary', component: 'tool_ceaorganizador'},
    {key: 'undo', component: 'tool_ceaorganizador'},
];

/**
 * Starts the organiser on its root element.
 *
 * @param {Object} options
 * @param {String} options.root Selector of the root element.
 * @returns {Promise|null} Resolves when the tree is drawn; null if there is no root.
 */
export const init = ({root}) => {
    const rootel = document.querySelector(root);
    if (!rootel) {
        return null;
    }
    const treeel = rootel.querySelector('[data-region="tree"]');
    const summary = rootel.querySelector('[data-region="summary"]');
    const summarytext = rootel.querySelector('[data-region="summarytext"]');
    const input = rootel.querySelector('[data-region="search"]');
    const results = rootel.querySelector('[data-region="courseresults"]');

    return Promise.all([getStrings(REQUESTS), api.getTree()]).then(([values, tree]) => {
        const strings = {};
        REQUESTS.forEach((request, index) => {
            strings[request.key] = values[index];
        });
        const state = {api, strings, onError: Notification.exception};
        // The context is read live: reload() replaces the model and the view.
        const ctx = {
            model: createModel(tree.categories, decodeEntities),
            api,
            state,
            strings,
            toast,
            onError: Notification.exception,
            container: treeel,
            siblingsOf: (id) => courseIds(treeel, id),
            view: {refresh: () => current.refresh()},
            reload: () => api.getTree().then((fresh) => {
                ctx.model = createModel(fresh.categories, decodeEntities);
                state.cache.clear();
                current = render(treeel, ctx.model, state);
                showSummary(fresh.needsortcount);
                return null;
            }),
        };
        const mover = createMover(ctx);
        const moveTo = (item, trigger) => {
            const dragged = describeItem(item);
            const label = item.querySelector(':scope > .tool-ceaorganizador-fila > .tool-ceaorganizador-nombre');
            openPicker({
                model: ctx.model,
                dragged,
                name: label ? label.textContent : '',
                trigger,
                strings,
                decode: decodeEntities,
                onPick: (id) => (id === 0 ? mover.moveToRoot(dragged) : mover.moveInside(dragged, id)),
            });
        };
        state.onMoveTo = moveTo;
        let current = render(treeel, ctx.model, state);
        attachDrag(treeel, ctx, {onDrop: (dragged, dest) => mover.move(dragged, dest)});
        attachKeyboard(treeel, ctx, {
            onDrop: (dragged, dest, options) => mover.move(dragged, dest, options),
            onMoveTo: (item) => moveTo(item, item),
        });

        const showSummary = (count) => {
            if (summary) {
                summary.hidden = count <= 0;
                summarytext.textContent = format(strings.needsortsummary, count);
            }
        };
        showSummary(tree.needsortcount);

        let timer = null;
        let token = 0;
        const search = () => {
            const query = input.value;
            state.visible = query.trim() === '' ? null : ctx.model.filter(query);
            current.refresh();
            const mine = ++token;
            results.textContent = '';
            if (query.trim().length < MIN_COURSE_SEARCH) {
                return;
            }
            api.getCourses(0, query.trim(), 0).then((found) => {
                if (mine !== token) {
                    return;
                }
                found.courses.forEach((course) => {
                    const li = document.createElement('li');
                    const name = document.createElement('span');
                    name.className = 'tool-ceaorganizador-nombre';
                    name.textContent = decodeEntities(course.fullname);
                    const path = document.createElement('span');
                    path.className = 'tool-ceaorganizador-ruta';
                    path.textContent = decodeEntities(course.path);
                    li.append(name, path);
                    results.appendChild(li);
                });
                return null;
            }).catch(Notification.exception);
        };
        if (input) {
            input.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(search, SEARCH_DELAY);
            });
        }
        return null;
    }).catch(Notification.exception);
};
