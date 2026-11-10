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
 * Accessible tree view of the categories, with courses loaded on expand.
 *
 * It imports no Moodle module: the web service client and the strings arrive
 * in the state, so it runs in node:test with jsdom. Every server string is
 * written with textContent.
 *
 * @module     tool_ceaorganizador/treeview
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const STORAGE_KEY = 'tool_ceaorganizador_expanded';

/**
 * Replaces the {$a} placeholder of a Moodle string.
 *
 * @param {String} text
 * @param {*} value
 * @returns {String}
 */
export const format = (text, value) => String(text).replace('{$a}', value);

/**
 * Reads the ids of the expanded categories; nothing when there is no storage.
 *
 * @param {Object|null} storage localStorage or a stand-in.
 * @returns {Set<Number>}
 */
export const loadExpanded = (storage) => {
    try {
        const ids = JSON.parse(storage.getItem(STORAGE_KEY));
        return new Set(Array.isArray(ids) ? ids.filter(Number.isInteger) : []);
    } catch (e) {
        return new Set();
    }
};

/**
 * Saves the ids of the expanded categories, ignoring storage errors.
 *
 * @param {Object|null} storage
 * @param {Set<Number>} expanded
 */
export const saveExpanded = (storage, expanded) => {
    try {
        storage.setItem(STORAGE_KEY, JSON.stringify([...expanded]));
    } catch (e) {
        // Private mode or blocked storage: the tree still works, just not remembered.
    }
};

const defaultStorage = (container) => {
    try {
        return container.ownerDocument.defaultView.localStorage;
    } catch (e) {
        return null;
    }
};

/**
 * Renders the tree in the container.
 *
 * @param {Element} container Element that becomes the role="tree".
 * @param {Object} model Model from treemodel.createModel().
 * @param {Object} state
 * @param {Object} state.api Object with getCourses(categoryid, search, page).
 * @param {Object} state.strings needsort, hidden, loadmore and coursecount.
 * @param {Set<Number>} [state.visible] Ids to show (result of model.filter); all when absent.
 * @param {Object} [state.storage] Where the expanded ids are remembered.
 * @param {Function} [state.onError] Receives the errors of the web services.
 * @returns {{refresh: Function, toggle: Function}}
 */
export const render = (container, model, state) => {
    const doc = container.ownerDocument;
    const strings = state.strings;
    const storage = state.storage === undefined ? defaultStorage(container) : state.storage;
    if (!state.expanded) {
        state.expanded = storage ? loadExpanded(storage) : new Set();
    }
    if (!state.cache) {
        state.cache = new Map();
    }
    const expanded = state.expanded;
    const cache = state.cache;
    const fail = (error) => {
        if (state.onError) {
            state.onError(error);
        }
    };

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

    const hiddenLabel = () => create('span', 'tool-ceaorganizador-oculta badge badge-secondary bg-secondary', strings.hidden);

    const isShown = (row) => !state.visible || state.visible.has(row.id);

    const shownChildren = (id) => model.children(id).filter(isShown);

    const setOpen = (li, open) => {
        li.setAttribute('aria-expanded', open ? 'true' : 'false');
        const group = li.querySelector(':scope > [role="group"]');
        if (group) {
            group.hidden = !open;
        }
    };

    const appendCourses = (group, entry, level) => {
        const old = group.querySelector(':scope > [data-region="more"]');
        if (old) {
            old.remove();
        }
        group.querySelectorAll(':scope > [data-type="course"]').forEach((node) => node.remove());
        entry.items.forEach((course) => {
            const li = create('li', 'tool-ceaorganizador-curso');
            li.setAttribute('role', 'treeitem');
            li.setAttribute('aria-level', String(level));
            li.setAttribute('aria-label', course.fullname);
            li.dataset.id = String(course.id);
            li.dataset.type = 'course';
            li.tabIndex = -1;
            const row = create('div', 'tool-ceaorganizador-fila');
            row.appendChild(create('span', 'tool-ceaorganizador-nombre', course.fullname));
            if (!course.visible) {
                row.appendChild(hiddenLabel());
            }
            li.appendChild(row);
            group.appendChild(li);
        });
        if (entry.items.length < entry.total) {
            const more = create('li', 'tool-ceaorganizador-mas');
            more.setAttribute('role', 'none');
            more.dataset.region = 'more';
            const button = create('button', 'btn btn-secondary btn-sm', strings.loadmore);
            button.type = 'button';
            button.dataset.action = 'loadmore';
            button.addEventListener('click', () => {
                button.disabled = true;
                loadMore(group, entry, level).catch((error) => {
                    button.disabled = false;
                    fail(error);
                });
            });
            more.appendChild(button);
            group.appendChild(more);
        }
    };

    const loadCourses = async(group, id, level) => {
        let entry = cache.get(id);
        if (!entry) {
            const result = await state.api.getCourses(id, '', 0);
            entry = {id, items: result.courses, total: result.total, page: 1};
            cache.set(id, entry);
        }
        appendCourses(group, entry, level);
    };

    const loadMore = async(group, entry, level) => {
        const result = await state.api.getCourses(entry.id, '', entry.page);
        entry.items = entry.items.concat(result.courses);
        entry.total = result.total;
        entry.page += 1;
        appendCourses(group, entry, level);
    };

    const buildCategory = (row, level) => {
        const li = create('li', 'tool-ceaorganizador-categoria');
        li.setAttribute('role', 'treeitem');
        li.setAttribute('aria-level', String(level));
        li.setAttribute('aria-expanded', 'false');
        li.setAttribute('aria-label', row.name);
        li.dataset.id = String(row.id);
        li.dataset.type = 'category';
        li.tabIndex = -1;
        const line = create('div', 'tool-ceaorganizador-fila');
        const chevron = create('span', 'tool-ceaorganizador-chevron');
        chevron.setAttribute('aria-hidden', 'true');
        line.appendChild(chevron);
        line.appendChild(create('span', 'tool-ceaorganizador-nombre', row.name));
        line.appendChild(create('span', 'tool-ceaorganizador-cuenta', format(strings.coursecount, row.coursecount)));
        if (!row.visible) {
            line.appendChild(hiddenLabel());
        }
        if (row.needsort) {
            const badge = create('span', 'tool-ceaorganizador-insignia', strings.needsort);
            badge.dataset.region = 'needsort';
            line.appendChild(badge);
        }
        line.addEventListener('click', () => toggle(li));
        li.appendChild(line);
        if (expanded.has(row.id) || forcedOpen(row)) {
            open(li, row, level, expanded.has(row.id));
        }
        return li;
    };

    // While searching, the matches stay reachable: categories leading to one open by themselves.
    const forcedOpen = (row) => Boolean(state.visible) && shownChildren(row.id).length > 0;

    const open = (li, row, level, withcourses) => {
        let group = li.querySelector(':scope > [role="group"]');
        if (!group) {
            group = create('ul', 'tool-ceaorganizador-grupo');
            group.setAttribute('role', 'group');
            shownChildren(row.id).forEach((child) => group.appendChild(buildCategory(child, level + 1)));
            li.appendChild(group);
        }
        setOpen(li, true);
        if (withcourses) {
            loadCourses(group, row.id, level + 1).catch(fail);
        }
    };

    function toggle(li) {
        const id = Number(li.dataset.id);
        const row = model.get(id);
        if (!row || li.dataset.type !== 'category') {
            return;
        }
        const level = Number(li.getAttribute('aria-level'));
        if (li.getAttribute('aria-expanded') === 'true') {
            expanded.delete(id);
            setOpen(li, false);
        } else {
            expanded.add(id);
            open(li, row, level, true);
        }
        if (storage) {
            saveExpanded(storage, expanded);
        }
    }

    const visibleItems = () => [...container.querySelectorAll('[role="treeitem"]')]
        .filter((item) => !item.parentElement.closest('[role="group"][hidden]'));

    const focusItem = (item) => {
        if (item) {
            item.focus();
        }
    };

    container.textContent = '';
    container.setAttribute('role', 'tree');
    const list = [];
    shownChildren(0).forEach((row) => list.push(buildCategory(row, 1)));
    list.forEach((li) => container.appendChild(li));
    if (list.length) {
        list[0].tabIndex = 0;
    }

    if (!container.dataset.treeviewBound) {
        container.dataset.treeviewBound = '1';
        container.addEventListener('focusin', (event) => {
            const item = event.target.closest('[role="treeitem"]');
            if (item) {
                container.querySelectorAll('[role="treeitem"][tabindex="0"]').forEach((node) => {
                    node.tabIndex = -1;
                });
                item.tabIndex = 0;
            }
        });
        container.addEventListener('keydown', (event) => {
            const item = event.target.closest('[role="treeitem"]');
            if (!item || event.target !== item) {
                return;
            }
            const items = visibleItems();
            const index = items.indexOf(item);
            const isCategory = item.dataset.type === 'category';
            const isOpen = item.getAttribute('aria-expanded') === 'true';
            let handled = true;
            switch (event.key) {
                case 'ArrowDown':
                    focusItem(items[index + 1]);
                    break;
                case 'ArrowUp':
                    focusItem(items[index - 1]);
                    break;
                case 'Home':
                    focusItem(items[0]);
                    break;
                case 'End':
                    focusItem(items[items.length - 1]);
                    break;
                case 'ArrowRight':
                    if (isCategory && !isOpen) {
                        toggle(item);
                    } else if (isOpen) {
                        focusItem(item.querySelector(':scope > [role="group"] > [role="treeitem"]'));
                    }
                    break;
                case 'ArrowLeft':
                    if (isOpen) {
                        toggle(item);
                    } else {
                        focusItem(item.parentElement.closest('[role="treeitem"]'));
                    }
                    break;
                case 'Enter':
                case ' ':
                    toggle(item);
                    break;
                default:
                    handled = false;
            }
            if (handled) {
                event.preventDefault();
            }
        });
    }

    return {
        toggle,
        refresh: () => render(container, model, state),
    };
};
