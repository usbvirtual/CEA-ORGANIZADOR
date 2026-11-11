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
 * «Quitar repetidos» panel: a category (chosen in the panel or given by its menu), the groups of repeated courses
 * of it and its subcategories, a choice per group of the course that stays and whether the group is cleaned, and
 * the apply step, which moves the others to a hidden «Repetidos» folder.
 *
 * The paths of dedupe_preview are formatted (entities) and are decoded with options.decode; the course names are
 * raw and are never decoded. Everything is written with textContent. The modules of Moodle arrive in the options,
 * so this one runs in node:test.
 *
 * @module     tool_ceaorganizador/dedupepanel
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {openDialog} from 'tool_ceaorganizador/dialog';
import {format} from 'tool_ceaorganizador/treeview';
import {exclusive} from 'tool_ceaorganizador/selection';

const SEPARATOR = ' / ';

let counter = 0;

/**
 * Courses a set of groups would move: all but the one that stays, in every group included.
 *
 * @param {Array} groups Groups of the panel, with included.
 * @returns {Number}
 */
export const movesOf = (groups) => groups
    .filter((group) => group.included)
    .reduce((total, group) => total + group.courses.length - 1, 0);

/**
 * Opens the panel.
 *
 * @param {Object} options
 * @param {Object} options.api dedupePreview(categoryid) and dedupeApply(params).
 * @param {Object} options.strings Strings of the panel.
 * @param {Object} options.toast Object with show(message, options).
 * @param {Function} options.onError Receives the exceptions of the web services.
 * @param {Function} options.undo Undoes a journal entry (async; resolves {ok} or nothing).
 * @param {Function} options.reload Reloads the tree from the server.
 * @param {Function} options.getModel Returns the live tree model (reload() replaces it).
 * @param {Function} [options.decode] Turns a formatted string into plain text.
 * @param {Number} [options.categoryid] Category to analyse at once (from its menu); 0 asks for one.
 * @param {Element} [options.trigger] Gets the focus back on close.
 * @param {Document} [options.doc]
 * @returns {{close: Function, element: Element, ready: Promise}}
 */
export const open = ({
    api,
    strings,
    toast,
    onError,
    undo,
    reload,
    getModel,
    decode = (text) => text,
    categoryid = 0,
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
    const button = (action, label, className = 'btn btn-secondary btn-sm') => {
        const node = create('button', className, label);
        node.type = 'button';
        node.dataset.action = action;
        return node;
    };

    let closed = false;
    const dialog = openDialog({
        doc,
        title: strings.dedupe,
        trigger,
        className: 'tool-ceaorganizador-dialogo-ancho',
        onClose: () => {
            closed = true;
        },
    });
    const root = dialog.element;

    // The category: a searchable list, as the target of «Unificar semillas».
    const search = create('input', 'form-control');
    search.type = 'search';
    search.setAttribute('aria-label', strings.dedupecategory);
    const list = create('ul', 'tool-ceaorganizador-dialogo-lista');
    list.dataset.region = 'categories';
    const now = create('p', 'tool-ceaorganizador-ruta');
    now.dataset.region = 'category';
    now.setAttribute('role', 'status');
    const pickBox = create('section', 'tool-ceaorganizador-fusion-bloque');
    pickBox.append(create('h3', 'tool-ceaorganizador-sugerencias-titulo', strings.dedupecategory), search, now, list);

    const note = create('p', 'tool-ceaorganizador-ruta', strings.dedupenote);
    const notice = create('p', 'tool-ceaorganizador-aviso');
    notice.dataset.region = 'notice';
    notice.setAttribute('role', 'status');
    notice.hidden = true;
    const folderLine = create('p', 'tool-ceaorganizador-ruta');
    folderLine.dataset.region = 'folder';
    folderLine.hidden = true;
    const folderWarning = create('p', 'tool-ceaorganizador-aviso', strings.repeatedfoldervisible);
    folderWarning.dataset.region = 'foldervisible';
    folderWarning.setAttribute('role', 'status');
    folderWarning.hidden = true;
    const groupsBox = create('div');
    groupsBox.dataset.region = 'groups';
    const applyButton = button('apply', format(strings.dedupeapply, 0), 'btn btn-primary');
    applyButton.disabled = true;
    const closeButton = button('close', strings.close, 'btn btn-link');
    closeButton.addEventListener('click', () => dialog.close());
    const actions = create('div', 'tool-ceaorganizador-acciones');
    actions.append(applyButton, closeButton);
    root.append(pickBox, note, notice, folderLine, folderWarning, groupsBox, actions);

    let chosen = 0;
    // What the preview was made with: the hash only matches that category.
    let planid = 0;
    let groups = [];
    let hash = '';
    let busy = false;
    let token = 0;

    const say = (text) => {
        notice.textContent = text;
        notice.hidden = text === '';
    };

    const refresh = () => {
        const count = movesOf(groups);
        applyButton.textContent = format(strings.dedupeapply, count);
        applyButton.disabled = busy || planid === 0 || count === 0;
    };

    const setBusy = (value) => {
        busy = value;
        root.setAttribute('aria-busy', value ? 'true' : 'false');
        refresh();
    };

    // Tree order: each category followed by its subcategories.
    const ordered = () => {
        const model = getModel();
        const out = [];
        const walk = (parent) => model.children(parent).forEach((row) => {
            out.push(row.id);
            walk(row.id);
        });
        walk(0);
        return out;
    };
    const pathOf = (id) => decode(getModel().pathOf(id).join(SEPARATOR));

    const drawCategories = () => {
        const shown = getModel().filter(search.value);
        list.textContent = '';
        ordered().filter((id) => shown.has(id)).forEach((id) => {
            const item = create('li');
            const pick = create('button', 'tool-ceaorganizador-dialogo-opcion btn btn-link', pathOf(id));
            pick.type = 'button';
            pick.dataset.categoryId = String(id);
            pick.setAttribute('aria-pressed', id === chosen ? 'true' : 'false');
            // eslint-disable-next-line no-use-before-define
            pick.addEventListener('click', () => choose(id));
            item.appendChild(pick);
            list.appendChild(item);
        });
    };

    const reasonsText = (reasons) => reasons.map((reason) => strings[`dedupereason_${reason}`] || reason).join(', ');

    const drawGroup = (group) => {
        counter += 1;
        const box = create('section', 'tool-ceaorganizador-fusion-bloque');
        box.dataset.groupKey = group.key;
        box.appendChild(create('h3', 'tool-ceaorganizador-sugerencias-titulo', decode(group.path)));
        const includeWrap = create('div', 'custom-control form-check custom-checkbox');
        const include = create('input', 'custom-control-input form-check-input');
        include.type = 'checkbox';
        include.id = `tool-ceaorganizador-dedupe-${counter}`;
        include.dataset.action = 'include';
        include.checked = group.included;
        const includeLabel = create('label', 'custom-control-label form-check-label', strings.dedupeinclude);
        includeLabel.htmlFor = include.id;
        includeWrap.append(include, includeLabel);
        box.appendChild(includeWrap);

        const options = create('ul', 'tool-ceaorganizador-dialogo-lista');
        const radios = [];
        group.courses.forEach((course, index) => {
            const item = create('li');
            item.dataset.courseid = String(course.courseid);
            const wrap = create('div', 'custom-control form-check custom-radio');
            const radio = create('input', 'custom-control-input form-check-input');
            radio.type = 'radio';
            radio.name = `tool-ceaorganizador-dedupe-${counter}`;
            radio.id = `tool-ceaorganizador-dedupe-${counter}-${index}`;
            radio.value = String(course.courseid);
            radio.checked = course.courseid === group.keep;
            radio.disabled = !group.included;
            radio.addEventListener('change', () => {
                if (radio.checked) {
                    group.keep = course.courseid;
                }
            });
            radios.push(radio);
            const label = create('label', 'custom-control-label form-check-label');
            label.htmlFor = radio.id;
            // Raw names: plain text, never decoded.
            label.appendChild(create('span', 'tool-ceaorganizador-nombre', course.fullname));
            const when = new Date(course.timemodified * 1000).toLocaleString();
            const details = [course.shortname, format(strings.dedupemodified, when), reasonsText(course.reasons)]
                .filter((text) => text !== '');
            label.appendChild(create('span', 'tool-ceaorganizador-ruta', ` · ${details.join(' · ')}`));
            if (course.courseid === group.default) {
                label.appendChild(create('span', 'tool-ceaorganizador-insignia', strings.dedupekeep));
                if (course.visible === false) {
                    const warning = create('span', 'tool-ceaorganizador-insignia', strings.winnerhidden);
                    warning.dataset.region = 'winnerhidden';
                    label.appendChild(warning);
                }
            }
            wrap.append(radio, label);
            item.appendChild(wrap);
            options.appendChild(item);
        });
        include.addEventListener('change', () => {
            group.included = include.checked;
            radios.forEach((radio) => {
                radio.disabled = !include.checked;
            });
            refresh();
        });
        box.appendChild(options);
        return box;
    };

    const draw = (result) => {
        groupsBox.textContent = '';
        groups = result.groups.map((group) => {
            const kept = group.courses.find((course) => course.keep) || group.courses[0];
            return {...group, keep: kept.courseid, default: kept.courseid, included: true};
        });
        groups.forEach((group) => groupsBox.appendChild(drawGroup(group)));
        folderLine.textContent = format(strings.dedupefolder, decode(result.folder.path));
        folderLine.hidden = groups.length === 0;
        // An existing visible folder would leave the moved courses visible.
        folderWarning.hidden = groups.length === 0 || !result.folder.visible;
        if (groups.length === 0) {
            say(strings.dedupenothing);
        } else {
            say(format(strings.dedupegroups, groups.length));
        }
    };

    // One apply at a time. A preview is never blocked: choosing another category discards the answer of the
    // one in flight (token) and asks again.
    const serial = exclusive((task) => task());

    const loadTask = async(keepNotice = false) => {
        const wanted = chosen;
        if (wanted === 0) {
            return;
        }
        const mine = ++token;
        if (!keepNotice) {
            say('');
        }
        setBusy(true);
        try {
            const result = await api.dedupePreview(wanted);
            if (mine === token && !closed) {
                planid = wanted;
                hash = result.hash;
                draw(result);
                if (keepNotice) {
                    say(strings.dedupestale);
                }
            }
        } catch (error) {
            onError(error);
        } finally {
            setBusy(false);
        }
    };

    function choose(id) {
        chosen = id;
        now.textContent = id > 0 ? format(strings.dedupecategorynow, pathOf(id)) : '';
        // A preview still in flight belongs to the old choice: its answer is discarded.
        token++;
        planid = 0;
        groups = [];
        groupsBox.textContent = '';
        folderLine.hidden = true;
        folderWarning.hidden = true;
        drawCategories();
        refresh();
        return loadTask();
    }

    const undoDedupe = async(logid) => {
        const result = await undo(logid);
        if (result && result.ok && !closed) {
            await loadTask();
        }
    };

    const applyTask = async() => {
        if (busy || planid === 0 || movesOf(groups) === 0) {
            return;
        }
        setBusy(true);
        let result = null;
        try {
            result = await api.dedupeApply({
                categoryid: planid,
                groups: groups.filter((group) => group.included).map((group) => ({key: group.key, keep: group.keep})),
                hash,
            });
        } catch (error) {
            setBusy(false);
            onError(error);
            return;
        }
        if (result.status === 'stale') {
            await loadTask(true);
            return;
        }
        // The preview is spent: a new one is made below.
        planid = 0;
        groups = [];
        groupsBox.textContent = '';
        refresh();
        if (result.logid > 0) {
            // A partial result stopped halfway: what moved is recorded and can be undone.
            const message = result.status === 'partial' ? strings.partialdone : strings.dedupedone;
            toast.show(format(message, result.moved), {
                actionLabel: strings.undo,
                onAction: () => undoDedupe(result.logid),
            });
            try {
                await reload();
            } catch (error) {
                onError(error);
            }
        }
        // What is left: normally nothing, since the folder is never analysed.
        if (closed) {
            setBusy(false);
        } else {
            await loadTask();
        }
    };
    const apply = () => serial(applyTask);

    search.addEventListener('input', drawCategories);
    applyButton.addEventListener('click', apply);

    drawCategories();
    refresh();
    let ready = Promise.resolve();
    if (categoryid > 0) {
        ready = choose(categoryid);
        closeButton.focus();
    } else {
        search.focus();
    }
    return {close: dialog.close, element: root, ready};
};
