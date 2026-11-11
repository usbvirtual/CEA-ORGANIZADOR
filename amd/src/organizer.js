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
import Config from 'core/config';
import * as api from 'tool_ceaorganizador/api';
import {createModel} from 'tool_ceaorganizador/treemodel';
import {decodeEntities} from 'tool_ceaorganizador/text';
import {render, format, syncSelection} from 'tool_ceaorganizador/treeview';
import * as toast from 'tool_ceaorganizador/toast';
import {createMover} from 'tool_ceaorganizador/mover';
import {attach as attachDrag, describeItem, courseIds} from 'tool_ceaorganizador/dragdrop';
import {attach as attachKeyboard} from 'tool_ceaorganizador/keyboard';
import {open as openPicker} from 'tool_ceaorganizador/movepicker';
import {createSelection, loadSubtreeCourses, loadAllPages, exclusive, selectedCategoryIds} from 'tool_ceaorganizador/selection';
import {open as openSort} from 'tool_ceaorganizador/sortpanel';
import {open as openRename} from 'tool_ceaorganizador/renamepanel';
import {open as openClone} from 'tool_ceaorganizador/clonepanel';
import {open as openCloneProgress} from 'tool_ceaorganizador/cloneprogress';
import {open as openHistory} from 'tool_ceaorganizador/historypanel';
import {createVisibility, selectedCourseIds} from 'tool_ceaorganizador/visibility';

const SEARCH_DELAY = 250;
const MIN_COURSE_SEARCH = 3;

// Literal shape, so that check-strings can verify every key.
const REQUESTS = [
    {key: 'affixadd', component: 'tool_ceaorganizador'},
    {key: 'affixmode', component: 'tool_ceaorganizador'},
    {key: 'affixposition', component: 'tool_ceaorganizador'},
    {key: 'affixremove', component: 'tool_ceaorganizador'},
    {key: 'affixtext', component: 'tool_ceaorganizador'},
    {key: 'after', component: 'tool_ceaorganizador'},
    {key: 'apply', component: 'tool_ceaorganizador'},
    {key: 'autosort', component: 'tool_ceaorganizador'},
    {key: 'autosortdone', component: 'tool_ceaorganizador'},
    {key: 'autosortnothing', component: 'tool_ceaorganizador'},
    {key: 'autosortpreview', component: 'tool_ceaorganizador'},
    {key: 'autosortrecursive', component: 'tool_ceaorganizador'},
    {key: 'autosortscope', component: 'tool_ceaorganizador'},
    {key: 'autosortscope_both', component: 'tool_ceaorganizador'},
    {key: 'autosortscope_categories', component: 'tool_ceaorganizador'},
    {key: 'autosortscope_courses', component: 'tool_ceaorganizador'},
    {key: 'autosortselected', component: 'tool_ceaorganizador'},
    {key: 'batchsplit', component: 'tool_ceaorganizador'},
    {key: 'before', component: 'tool_ceaorganizador'},
    {key: 'case_none', component: 'tool_ceaorganizador'},
    {key: 'case_sentence', component: 'tool_ceaorganizador'},
    {key: 'case_title', component: 'tool_ceaorganizador'},
    {key: 'case_upper', component: 'tool_ceaorganizador'},
    {key: 'casesensitive', component: 'tool_ceaorganizador'},
    {key: 'clearselection', component: 'tool_ceaorganizador'},
    {key: 'close', component: 'tool_ceaorganizador'},
    {key: 'cloneapply', component: 'tool_ceaorganizador'},
    {key: 'cloneend', component: 'tool_ceaorganizador'},
    {key: 'cloneerror_alreadycloned', component: 'tool_ceaorganizador'},
    {key: 'cloneerror_dates', component: 'tool_ceaorganizador'},
    {key: 'cloneerror_destinsideseeds', component: 'tool_ceaorganizador'},
    {key: 'cloneerror_empty', component: 'tool_ceaorganizador'},
    {key: 'cloneerror_label', component: 'tool_ceaorganizador'},
    {key: 'cloneerror_nopermission', component: 'tool_ceaorganizador'},
    {key: 'cloneerror_noperiod', component: 'tool_ceaorganizador'},
    {key: 'cloneerror_queuefailed', component: 'tool_ceaorganizador'},
    {key: 'cloneerror_shortnameduplicate', component: 'tool_ceaorganizador'},
    {key: 'cloneerror_shortnametaken', component: 'tool_ceaorganizador'},
    {key: 'cloneerror_toolong', component: 'tool_ceaorganizador'},
    {key: 'clonecronnote', component: 'tool_ceaorganizador'},
    {key: 'clonedest', component: 'tool_ceaorganizador'},
    {key: 'clonedone', component: 'tool_ceaorganizador'},
    {key: 'clonefailed', component: 'tool_ceaorganizador'},
    {key: 'clonelabel', component: 'tool_ceaorganizador'},
    {key: 'clonenames', component: 'tool_ceaorganizador'},
    {key: 'clonenew', component: 'tool_ceaorganizador'},
    {key: 'clonenewfullname', component: 'tool_ceaorganizador'},
    {key: 'clonenewshortname', component: 'tool_ceaorganizador'},
    {key: 'clonenoperiods', component: 'tool_ceaorganizador'},
    {key: 'clonenothing', component: 'tool_ceaorganizador'},
    {key: 'clonepending', component: 'tool_ceaorganizador'},
    {key: 'cloneperiod', component: 'tool_ceaorganizador'},
    {key: 'clonepreview', component: 'tool_ceaorganizador'},
    {key: 'cloneprogress', component: 'tool_ceaorganizador'},
    {key: 'clonesource', component: 'tool_ceaorganizador'},
    {key: 'clonestale', component: 'tool_ceaorganizador'},
    {key: 'clonestart', component: 'tool_ceaorganizador'},
    {key: 'clonestate', component: 'tool_ceaorganizador'},
    {key: 'clonetoperiod', component: 'tool_ceaorganizador'},
    {key: 'clonetotal', component: 'tool_ceaorganizador'},
    {key: 'cloneviewprogress', component: 'tool_ceaorganizador'},
    {key: 'cronwarning', component: 'tool_ceaorganizador'},
    {key: 'confirm', component: 'tool_ceaorganizador'},
    {key: 'coursecount', component: 'tool_ceaorganizador'},
    {key: 'dropafter', component: 'tool_ceaorganizador'},
    {key: 'dropbefore', component: 'tool_ceaorganizador'},
    {key: 'dropcancelled', component: 'tool_ceaorganizador'},
    {key: 'dropinside', component: 'tool_ceaorganizador'},
    {key: 'error_generic', component: 'tool_ceaorganizador'},
    {key: 'grabbed', component: 'tool_ceaorganizador'},
    {key: 'hidden', component: 'tool_ceaorganizador'},
    {key: 'hidecourse', component: 'tool_ceaorganizador'},
    {key: 'hideselected', component: 'tool_ceaorganizador'},
    {key: 'history', component: 'tool_ceaorganizador'},
    {key: 'history_empty', component: 'tool_ceaorganizador'},
    {key: 'historypage', component: 'tool_ceaorganizador'},
    {key: 'historyundone', component: 'tool_ceaorganizador'},
    {key: 'includeshortname', component: 'tool_ceaorganizador'},
    {key: 'invaliddrop', component: 'tool_ceaorganizador'},
    {key: 'keyboardhint', component: 'tool_ceaorganizador'},
    {key: 'loadingcourses', component: 'tool_ceaorganizador'},
    {key: 'loadmore', component: 'tool_ceaorganizador'},
    {key: 'moved', component: 'tool_ceaorganizador'},
    {key: 'movetitle', component: 'tool_ceaorganizador'},
    {key: 'moveto', component: 'tool_ceaorganizador'},
    {key: 'movetoroot', component: 'tool_ceaorganizador'},
    {key: 'needsort', component: 'tool_ceaorganizador'},
    {key: 'needsortsummary', component: 'tool_ceaorganizador'},
    {key: 'nextpage', component: 'tool_ceaorganizador'},
    {key: 'nodemenu', component: 'tool_ceaorganizador'},
    {key: 'opencategory', component: 'tool_ceaorganizador'},
    {key: 'opmovedown', component: 'tool_ceaorganizador'},
    {key: 'opmoveup', component: 'tool_ceaorganizador'},
    {key: 'opremove', component: 'tool_ceaorganizador'},
    {key: 'prefix', component: 'tool_ceaorganizador'},
    {key: 'prevpage', component: 'tool_ceaorganizador'},
    {key: 'rename', component: 'tool_ceaorganizador'},
    {key: 'renameaddop', component: 'tool_ceaorganizador'},
    {key: 'renamedone', component: 'tool_ceaorganizador'},
    {key: 'renameerror_empty', component: 'tool_ceaorganizador'},
    {key: 'renameerror_multilang', component: 'tool_ceaorganizador'},
    {key: 'renameerror_shortnameduplicate', component: 'tool_ceaorganizador'},
    {key: 'renameerror_shortnametaken', component: 'tool_ceaorganizador'},
    {key: 'renameerror_toolong', component: 'tool_ceaorganizador'},
    {key: 'renamenothing', component: 'tool_ceaorganizador'},
    {key: 'renameop_affix', component: 'tool_ceaorganizador'},
    {key: 'renameop_clean', component: 'tool_ceaorganizador'},
    {key: 'renameop_replace', component: 'tool_ceaorganizador'},
    {key: 'renameoptype', component: 'tool_ceaorganizador'},
    {key: 'renamepreview', component: 'tool_ceaorganizador'},
    {key: 'renameskipped', component: 'tool_ceaorganizador'},
    {key: 'replacewith', component: 'tool_ceaorganizador'},
    {key: 'resultscount', component: 'tool_ceaorganizador'},
    {key: 'searchfor', component: 'tool_ceaorganizador'},
    {key: 'selectcontents', component: 'tool_ceaorganizador'},
    {key: 'selected', component: 'tool_ceaorganizador'},
    {key: 'selectitem', component: 'tool_ceaorganizador'},
    {key: 'selectresults', component: 'tool_ceaorganizador'},
    {key: 'shortname', component: 'tool_ceaorganizador'},
    {key: 'showcourse', component: 'tool_ceaorganizador'},
    {key: 'showselected', component: 'tool_ceaorganizador'},
    {key: 'sortselectedcount', component: 'tool_ceaorganizador'},
    {key: 'stale', component: 'tool_ceaorganizador'},
    {key: 'suffix', component: 'tool_ceaorganizador'},
    {key: 'undo', component: 'tool_ceaorganizador'},
    {key: 'undoconflicts', component: 'tool_ceaorganizador'},
    {key: 'visibilitychanged', component: 'tool_ceaorganizador'},
    {key: 'wholeword', component: 'tool_ceaorganizador'},
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
        const selection = createSelection();
        const state = {api, strings, selection, wwwroot: Config.wwwroot, onError: Notification.exception};
        // The context is read live: reload() replaces the model and the view.
        const ctx = {
            model: createModel(tree.categories, decodeEntities),
            api,
            state,
            strings,
            toast,
            onError: Notification.exception,
            container: treeel,
            decode: decodeEntities,
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
        const visibility = createVisibility({
            api,
            state,
            container: treeel,
            strings,
            toast,
            undo: mover.undo,
            onError: Notification.exception,
            isBusy: mover.busy,
        });

        // Dependencies shared by the panels; the model is read live.
        const panelOptions = (trigger) => ({
            api,
            strings,
            toast,
            onError: Notification.exception,
            undo: mover.undo,
            reload: ctx.reload,
            decode: decodeEntities,
            trigger,
        });
        // Every course of the category and of its subcategories, not only the ones drawn.
        const contentsOf = async(id) => {
            const own = createSelection();
            own.addCategoryContents(ctx.model, id, await loadSubtreeCourses(api, ctx.model, id));
            return own.items();
        };
        // Loading many pages takes a while: one load at a time, with a visible busy state.
        const busyText = document.createElement('span');
        busyText.className = 'tool-ceaorganizador-ruta';
        busyText.setAttribute('role', 'status');
        busyText.hidden = true;
        const withLoading = exclusive(async(trigger, work) => {
            const button = trigger && trigger.tagName === 'BUTTON' ? trigger : null;
            busyText.textContent = strings.loadingcourses;
            busyText.hidden = false;
            treeel.setAttribute('aria-busy', 'true');
            if (button) {
                button.disabled = true;
            }
            try {
                return await work();
            } finally {
                busyText.hidden = true;
                busyText.textContent = '';
                treeel.removeAttribute('aria-busy');
                if (button) {
                    button.disabled = false;
                }
            }
        });
        state.onAction = (action, item, trigger) => {
            const dragged = describeItem(item);
            if (action === 'moveto') {
                moveTo(item, trigger);
            } else if (action === 'sort') {
                openSort({
                    ...panelOptions(trigger),
                    categoryid: dragged.id,
                    path: ctx.model.pathOf(dragged.id).join(' / '),
                });
            } else if (action === 'rename') {
                if (dragged.type !== 'category') {
                    openRename({...panelOptions(trigger), items: [{type: 'course', id: dragged.id}]});
                    return;
                }
                // Null when another load is running: the second click is ignored.
                const pending = withLoading(trigger, () => contentsOf(dragged.id));
                if (pending) {
                    pending.then((items) => openRename({...panelOptions(trigger), items})).catch(Notification.exception);
                }
            } else if (action === 'clone') {
                openClone({...panelOptions(trigger), model: ctx.model, items: [{type: 'category', id: dragged.id}]});
            } else if (action === 'hide' || action === 'show') {
                visibility.apply([dragged.id], action === 'show', trigger);
            } else if (action === 'selectcontents') {
                const pending = withLoading(trigger, () => loadSubtreeCourses(api, ctx.model, dragged.id));
                if (pending) {
                    pending
                        .then((courses) => selection.addCategoryContents(ctx.model, dragged.id, courses))
                        .catch(Notification.exception);
                }
            }
        };
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

        // Toolbar: history and the selection bar.
        const toolbar = document.createElement('div');
        toolbar.className = 'tool-ceaorganizador-herramientas';
        const historyButton = document.createElement('button');
        historyButton.type = 'button';
        historyButton.className = 'btn btn-secondary btn-sm';
        historyButton.dataset.action = 'history';
        historyButton.textContent = strings.history;
        historyButton.addEventListener('click', () => openHistory({
            ...panelOptions(historyButton),
            // A cloning batch of the history opens its progress again; the focus returns to the history button.
            viewProgress: (batchid) => openCloneProgress(batchid, {
                api,
                strings,
                onError: Notification.exception,
                trigger: historyButton,
            }),
        }));
        const bar = document.createElement('div');
        bar.className = 'tool-ceaorganizador-seleccion';
        bar.dataset.region = 'selectionbar';
        bar.hidden = true;
        const barText = document.createElement('span');
        barText.setAttribute('role', 'status');
        const renameButton = document.createElement('button');
        renameButton.type = 'button';
        renameButton.className = 'btn btn-primary btn-sm';
        renameButton.dataset.action = 'renameselection';
        renameButton.textContent = strings.rename;
        renameButton.addEventListener('click', () => openRename({...panelOptions(renameButton), items: selection.items()}));
        const cloneButton = document.createElement('button');
        cloneButton.type = 'button';
        cloneButton.className = 'btn btn-primary btn-sm';
        cloneButton.dataset.action = 'cloneselection';
        cloneButton.textContent = strings.clonetoperiod;
        cloneButton.addEventListener('click', () => openClone({
            ...panelOptions(cloneButton),
            model: ctx.model,
            items: selection.items(),
        }));
        const sortButton = document.createElement('button');
        sortButton.type = 'button';
        sortButton.className = 'btn btn-primary btn-sm';
        sortButton.dataset.action = 'sortselection';
        sortButton.textContent = strings.autosortselected;
        sortButton.addEventListener('click', () => {
            const categoryids = selectedCategoryIds(selection.items());
            const paths = categoryids.map((id) => ctx.model.pathOf(id).join(' / '));
            openSort({...panelOptions(sortButton), categoryids, paths});
        });
        // Only courses are shown or hidden: the categories of the selection are left out.
        const visibilityButton = (action, label, visible) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-secondary btn-sm';
            button.dataset.action = action;
            button.textContent = label;
            button.addEventListener('click', () => visibility.apply(selectedCourseIds(selection.items()), visible, button));
            return button;
        };
        const hideButton = visibilityButton('hideselection', strings.hideselected, false);
        const showButton = visibilityButton('showselection', strings.showselected, true);
        const clearButton = document.createElement('button');
        clearButton.type = 'button';
        clearButton.className = 'btn btn-secondary btn-sm';
        clearButton.dataset.action = 'clearselection';
        clearButton.textContent = strings.clearselection;
        clearButton.addEventListener('click', () => selection.clear());
        bar.append(barText, renameButton, cloneButton, sortButton, hideButton, showButton, clearButton);
        toolbar.append(historyButton, busyText, bar);
        const hint = document.createElement('p');
        hint.className = 'tool-ceaorganizador-ruta';
        hint.textContent = strings.keyboardhint;
        treeel.parentNode.insertBefore(toolbar, treeel);
        treeel.parentNode.insertBefore(hint, treeel);
        selection.onChange(() => {
            syncSelection(treeel, selection);
            results.querySelectorAll('input[data-id]').forEach((box) => {
                box.checked = selection.has('course', Number(box.dataset.id));
            });
            bar.hidden = selection.count() === 0;
            barText.textContent = format(strings.selected, selection.count());
            // Only categories can be sorted: the button needs at least one in the selection.
            sortButton.disabled = selectedCategoryIds(selection.items()).length === 0;
            const nocourses = selectedCourseIds(selection.items()).length === 0;
            hideButton.disabled = nocourses;
            showButton.disabled = nocourses;
        });

        const reviewButton = rootel.querySelector('[data-action="reviewsort"]');
        if (reviewButton) {
            reviewButton.addEventListener('click', () => openSort({
                ...panelOptions(reviewButton),
                categoryid: 0,
                recursive: true,
            }));
        }

        const resultsCount = (shown, total) => String(strings.resultscount)
            .replace('{$a->shown}', shown)
            .replace('{$a->total}', total);
        // Loads the pages after the first one (already in hand) and selects every match.
        const selectAllResults = async(query, first) => {
            let rows = first.courses;
            if (first.courses.length < first.total) {
                rows = (await loadAllPages(api, 0, query)).courses;
            }
            selection.addSearchResults(rows);
            return rows.length;
        };

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
                if (found.courses.length) {
                    const all = document.createElement('li');
                    const pick = document.createElement('button');
                    pick.type = 'button';
                    pick.className = 'btn btn-secondary btn-sm';
                    pick.dataset.action = 'selectresults';
                    pick.textContent = strings.selectresults;
                    // Every match, not only the page drawn: the other pages are loaded on click.
                    pick.addEventListener('click', () => {
                        const pending = withLoading(pick, () => selectAllResults(query.trim(), found));
                        if (pending) {
                            pending.catch(Notification.exception);
                        }
                    });
                    const count = document.createElement('span');
                    count.className = 'tool-ceaorganizador-ruta';
                    count.textContent = resultsCount(found.courses.length, found.total);
                    all.append(pick, count);
                    results.appendChild(all);
                }
                found.courses.forEach((course) => {
                    const li = document.createElement('li');
                    const box = document.createElement('input');
                    box.type = 'checkbox';
                    box.className = 'tool-ceaorganizador-casilla';
                    box.dataset.id = String(course.id);
                    box.checked = selection.has('course', course.id);
                    box.setAttribute('aria-label', format(strings.selectitem, decodeEntities(course.fullname)));
                    box.addEventListener('change', () => selection.toggle('course', course.id));
                    li.appendChild(box);
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
