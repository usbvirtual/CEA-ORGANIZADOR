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

import {decodeEntities} from 'tool_ceaorganizador/text';

const STORAGE_KEY = 'tool_ceaorganizador_expanded';
const SELECTED = 'tool-ceaorganizador-seleccionado';
const CHECKBOX = ':scope > .tool-ceaorganizador-fila > .tool-ceaorganizador-casilla';

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
const loadExpanded = (storage) => {
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
const saveExpanded = (storage, expanded) => {
    try {
        storage.setItem(STORAGE_KEY, JSON.stringify([...expanded]));
    } catch (e) {
        // Private mode or blocked storage: the tree still works, just not remembered.
    }
};

/**
 * Marks the nodes of the tree that are in the selection (class and checkbox).
 *
 * @param {Element} container Tree element.
 * @param {Object} selection Object with has(type, id).
 */
export const syncSelection = (container, selection) => {
    container.querySelectorAll('[role="treeitem"][data-id]').forEach((li) => {
        const chosen = selection.has(li.dataset.type, Number(li.dataset.id));
        li.classList.toggle(SELECTED, chosen);
        const box = li.querySelector(CHECKBOX);
        if (box) {
            box.checked = chosen;
        }
    });
};

/**
 * The "Hidden" label of a row.
 *
 * @param {Document} doc
 * @param {String} text
 * @returns {Element}
 */
const createHiddenLabel = (doc, text) => {
    const label = doc.createElement('span');
    label.className = 'tool-ceaorganizador-oculta badge badge-secondary bg-secondary';
    label.textContent = text;
    return label;
};

/**
 * Shows or removes the "Hidden" label of the drawn rows of a course, without drawing the tree again.
 *
 * The label stays the description of the row (aria-describedby), and the node menu offers
 * "Show" or "Hide" from the new state.
 *
 * @param {Element} container Tree element.
 * @param {Number} id Course.
 * @param {Boolean} visible
 * @param {String} text The "Hidden" string.
 */
export const setCourseVisible = (container, id, visible, text) => {
    const doc = container.ownerDocument;
    container.querySelectorAll(`[role="treeitem"][data-type="course"][data-id="${Number(id)}"]`).forEach((li) => {
        li.dataset.visible = visible ? '1' : '0';
        const row = li.querySelector(':scope > .tool-ceaorganizador-fila');
        const labelid = `tool-ceaorganizador-course-${Number(id)}-hidden`;
        const old = row ? row.querySelector(':scope > .tool-ceaorganizador-oculta') : null;
        const described = (li.getAttribute('aria-describedby') || '').split(' ')
            .filter((token) => token !== '' && token !== labelid);
        if (old) {
            old.remove();
        }
        if (!visible && row) {
            const label = createHiddenLabel(doc, text);
            label.id = labelid;
            const name = row.querySelector(':scope > .tool-ceaorganizador-nombre');
            row.insertBefore(label, name ? name.nextSibling : null);
            described.push(labelid);
        }
        if (described.length) {
            li.setAttribute('aria-describedby', described.join(' '));
        } else {
            li.removeAttribute('aria-describedby');
        }
    });
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
 * @param {Object} [state.selection] Selection from selection.createSelection(); adds a checkbox per row.
 * @param {Function} [state.onAction] Called with (action, item, button) by the node menu: moveto, sort,
 *                   rename, suggest, merge, clone, selectcontents, delete, hide or show. Adds the menu button.
 * @param {String} [state.wwwroot] Site URL, for the "Open in Moodle" link of the menu.
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
    const pending = state.pending || (state.pending = new Map());
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

    // Optional "Move to..." button; state.onMoveTo(item, button) opens the dialog.
    const addMoveButton = (li, row, name) => {
        if (!state.onMoveTo) {
            return;
        }
        const button = create('button', 'tool-ceaorganizador-mover btn btn-secondary btn-sm', strings.moveto);
        button.type = 'button';
        button.tabIndex = -1;
        button.dataset.action = 'moveto';
        button.setAttribute('aria-label', format(strings.movetitle, name));
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            state.onMoveTo(li, button);
        });
        row.appendChild(button);
    };

    // Optional selection checkbox; its state lives in state.selection.
    const addCheckbox = (li, row, type, id, name) => {
        if (!state.selection) {
            return;
        }
        const box = create('input', 'tool-ceaorganizador-casilla');
        box.type = 'checkbox';
        box.tabIndex = -1;
        box.dataset.action = 'select';
        box.setAttribute('aria-label', format(strings.selectitem, name));
        box.checked = state.selection.has(type, id);
        li.classList.toggle(SELECTED, box.checked);
        box.addEventListener('click', (event) => event.stopPropagation());
        box.addEventListener('change', () => state.selection.toggle(type, id));
        row.appendChild(box);
    };

    // Optional node menu; state.onAction(action, item, button) runs the chosen action.
    const addMenu = (li, row, type, id, name) => {
        if (!state.onAction) {
            return;
        }
        const wrap = create('span', 'tool-ceaorganizador-menu-contenedor');
        const trigger = create('button', 'tool-ceaorganizador-menu-boton btn btn-secondary btn-sm', '⋯');
        trigger.type = 'button';
        trigger.tabIndex = -1;
        trigger.dataset.action = 'menu';
        trigger.setAttribute('aria-haspopup', 'menu');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-label', format(strings.nodemenu, name));
        wrap.appendChild(trigger);

        let menu = null;
        let byKey = false;
        const closeMenu = (restore) => {
            if (!menu) {
                return;
            }
            menu.remove();
            menu = null;
            trigger.setAttribute('aria-expanded', 'false');
            // eslint-disable-next-line no-use-before-define
            doc.removeEventListener('click', onOutside, true);
            if (restore) {
                (byKey ? li : trigger).focus();
            }
        };
        function onOutside(event) {
            if (menu && !wrap.contains(event.target)) {
                closeMenu(false);
            }
        }
        const entries = [['moveto', strings.moveto]];
        if (type === 'category') {
            entries.push(
                ['sort', strings.autosort],
                ['rename', strings.rename],
                ['suggest', strings.suggest],
                ['merge', strings.merge],
                ['clone', strings.clonetoperiod],
                ['open', strings.opencategory],
                ['selectcontents', strings.selectcontents],
                ['delete', strings.deletecategory]
            );
        } else {
            entries.push(['rename', strings.rename], ['visibility']);
        }
        // A course offers "Show" or "Hide" from its state when the menu opens: it may change in place.
        const resolve = ([action, label]) => {
            if (action !== 'visibility') {
                return [action, label];
            }
            return li.dataset.visible === '0' ? ['show', strings.showcourse] : ['hide', strings.hidecourse];
        };
        const openMenu = () => {
            if (state.closeMenu) {
                state.closeMenu(false);
            }
            menu = create('ul', 'tool-ceaorganizador-menu');
            menu.setAttribute('role', 'menu');
            entries.map(resolve).forEach(([action, label]) => {
                if (action === 'open' && state.wwwroot === undefined) {
                    return;
                }
                const item = create('li');
                item.setAttribute('role', 'none');
                let control;
                if (action === 'open') {
                    control = create('a', 'tool-ceaorganizador-menu-opcion', label);
                    control.href = `${state.wwwroot}/course/management.php?categoryid=${id}`;
                } else {
                    control = create('button', 'tool-ceaorganizador-menu-opcion', label);
                    control.type = 'button';
                    control.addEventListener('click', () => {
                        closeMenu(false);
                        state.onAction(action, li, trigger);
                    });
                }
                control.setAttribute('role', 'menuitem');
                control.dataset.action = action;
                item.appendChild(control);
                menu.appendChild(item);
            });
            menu.addEventListener('click', (event) => event.stopPropagation());
            menu.addEventListener('keydown', (event) => {
                const options = [...menu.querySelectorAll('[role="menuitem"]')];
                const at = options.indexOf(doc.activeElement);
                if (event.key === 'Escape' || event.key === 'Tab') {
                    event.preventDefault();
                    closeMenu(true);
                } else if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    options[(at + 1) % options.length].focus();
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    options[(at - 1 + options.length) % options.length].focus();
                }
            });
            wrap.appendChild(menu);
            trigger.setAttribute('aria-expanded', 'true');
            state.closeMenu = closeMenu;
            doc.addEventListener('click', onOutside, true);
            menu.querySelector('[role="menuitem"]').focus();
        };
        trigger.addEventListener('click', (event) => {
            event.stopPropagation();
            if (menu) {
                closeMenu(true);
            } else {
                byKey = false;
                openMenu();
            }
        });
        // ContextMenu and Shift+F10 on the treeitem open it; Esc gives the focus back to the treeitem.
        li.ceaOpenMenu = () => {
            if (!menu) {
                byKey = true;
                openMenu();
            }
        };
        row.appendChild(wrap);
    };

    // Keys that work on a focused node, announced to assistive technology.
    const shortcuts = () => ['Space']
        .concat(state.onMoveTo ? ['M'] : [], state.selection ? ['S'] : [], state.onAction ? ['Shift+F10'] : [])
        .join(' ');

    const hiddenLabel = () => createHiddenLabel(doc, strings.hidden);

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
            li.setAttribute('aria-keyshortcuts', shortcuts());
            li.dataset.id = String(course.id);
            li.dataset.type = 'course';
            li.dataset.visible = course.visible ? '1' : '0';
            li.tabIndex = -1;
            const row = create('div', 'tool-ceaorganizador-fila');
            addCheckbox(li, row, 'course', course.id, decodeEntities(course.fullname));
            const name = create('span', 'tool-ceaorganizador-nombre', decodeEntities(course.fullname));
            name.id = `tool-ceaorganizador-course-${course.id}-name`;
            row.appendChild(name);
            li.setAttribute('aria-labelledby', name.id);
            if (!course.visible) {
                const label = hiddenLabel();
                label.id = `tool-ceaorganizador-course-${course.id}-hidden`;
                row.appendChild(label);
                li.setAttribute('aria-describedby', label.id);
            }
            addMoveButton(li, row, decodeEntities(course.fullname));
            addMenu(li, row, 'course', course.id, decodeEntities(course.fullname));
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

    // One request per category: expand, collapse and expand again share the pending promise.
    const loadCourses = async(group, id, level) => {
        let entry = cache.get(id);
        if (!entry) {
            if (!pending.has(id)) {
                const request = Promise.resolve(state.api.getCourses(id, '', 0)).then((result) => {
                    const loaded = {id, items: result.courses, total: result.total, page: 1};
                    cache.set(id, loaded);
                    return loaded;
                }).finally(() => pending.delete(id));
                pending.set(id, request);
            }
            entry = await pending.get(id);
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
        li.setAttribute('aria-keyshortcuts', shortcuts());
        li.dataset.id = String(row.id);
        li.dataset.type = 'category';
        li.tabIndex = -1;
        const line = create('div', 'tool-ceaorganizador-fila');
        const chevron = create('span', 'tool-ceaorganizador-chevron');
        chevron.setAttribute('aria-hidden', 'true');
        line.appendChild(chevron);
        addCheckbox(li, line, 'category', row.id, decodeEntities(row.name));
        const prefix = `tool-ceaorganizador-category-${row.id}`;
        const described = [];
        const name = create('span', 'tool-ceaorganizador-nombre', decodeEntities(row.name));
        name.id = `${prefix}-name`;
        line.appendChild(name);
        li.setAttribute('aria-labelledby', name.id);
        const count = create('span', 'tool-ceaorganizador-cuenta', format(strings.coursecount, row.coursecount));
        count.id = `${prefix}-count`;
        described.push(count.id);
        line.appendChild(count);
        if (!row.visible) {
            const label = hiddenLabel();
            label.id = `${prefix}-hidden`;
            described.push(label.id);
            line.appendChild(label);
        }
        if (row.needsort) {
            const badge = create('span', 'tool-ceaorganizador-insignia', strings.needsort);
            badge.dataset.region = 'needsort';
            badge.id = `${prefix}-needsort`;
            described.push(badge.id);
            line.appendChild(badge);
        }
        li.setAttribute('aria-describedby', described.join(' '));
        addMoveButton(li, line, decodeEntities(row.name));
        addMenu(li, line, 'category', row.id, decodeEntities(row.name));
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

    // A re-render replaces the nodes: when the focus was inside the tree it goes back to the same item.
    const active = doc.activeElement;
    const focused = active && container.contains(active) ? active.closest('[role="treeitem"]') : null;
    const focusKey = focused ? {type: focused.dataset.type, id: focused.dataset.id} : null;

    container.textContent = '';
    container.setAttribute('role', 'tree');
    const list = [];
    shownChildren(0).forEach((row) => list.push(buildCategory(row, 1)));
    list.forEach((li) => container.appendChild(li));
    if (list.length) {
        list[0].tabIndex = 0;
    }
    if (focusKey && (!doc.activeElement || doc.activeElement === doc.body)) {
        const same = container.querySelector(`[role="treeitem"][data-type="${focusKey.type}"][data-id="${focusKey.id}"]`);
        const target = same || list[0];
        if (target) {
            target.focus();
        }
    }

    // The listeners are bound once; they call the toggle of the latest render (its model may be new).
    const holder = container.ceaTree || (container.ceaTree = {});
    holder.toggle = toggle;

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
                        holder.toggle(item);
                    } else if (isOpen) {
                        focusItem(item.querySelector(':scope > [role="group"] > [role="treeitem"]'));
                    }
                    break;
                case 'ArrowLeft':
                    if (isOpen) {
                        holder.toggle(item);
                    } else {
                        focusItem(item.parentElement.closest('[role="treeitem"]'));
                    }
                    break;
                case 'Enter':
                case ' ':
                    holder.toggle(item);
                    break;
                case 'ContextMenu':
                case 'F10':
                    if ((event.key === 'F10' && !event.shiftKey) || !item.ceaOpenMenu) {
                        handled = false;
                        break;
                    }
                    item.ceaOpenMenu();
                    break;
                case 's':
                    if (!state.selection) {
                        handled = false;
                        break;
                    }
                    state.selection.toggle(item.dataset.type, Number(item.dataset.id));
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
