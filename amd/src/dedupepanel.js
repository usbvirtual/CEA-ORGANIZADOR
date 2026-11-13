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
 * «Quitar repetidos» panel: one category (chosen in the panel or given by its menu) or several (the selection of the
 * tree), the groups of repeated courses of them and their subcategories, a choice per group of the course that stays
 * and whether the group is cleaned, the «Posibles repetidos» that need a yes or no (no by default, so nothing moves),
 * and the apply step, which moves the others to a hidden «Repetidos» folder under each chosen category.
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
 * @param {Object} options.api dedupePreview(categoryids) and dedupeApply(params).
 * @param {Object} options.strings Strings of the panel.
 * @param {Object} options.toast Object with show(message, options).
 * @param {Function} options.onError Receives the exceptions of the web services.
 * @param {Function} options.undo Undoes a journal entry (async; resolves {ok} or nothing).
 * @param {Function} options.reload Reloads the tree from the server.
 * @param {Function} options.getModel Returns the live tree model (reload() replaces it).
 * @param {Function} [options.decode] Turns a formatted string into plain text.
 * @param {Number} [options.categoryid] Category to analyse at once (from its menu); 0 asks for one.
 * @param {Number[]} [options.categoryids] Categories to analyse at once (from the selection); wins over categoryid.
 * @param {String} [options.focus] 'possible' puts the focus on the first possible group once it is loaded.
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
    categoryids = null,
    focus = '',
    trigger,
    doc = document,
}) => {
    const scope = categoryids ? categoryids.map(Number) : (categoryid > 0 ? [Number(categoryid)] : []);
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

    // The category: a searchable list, as the target of «Unificar categorías».
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

    // The chosen categories; the picker chooses one, the selection of the tree may bring several.
    let chosen = scope.slice();
    // What the preview was made with: the hash only matches those categories.
    let planids = [];
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
        applyButton.disabled = busy || planids.length === 0 || count === 0;
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
            pick.setAttribute('aria-pressed', chosen.includes(id) ? 'true' : 'false');
            // eslint-disable-next-line no-use-before-define
            pick.addEventListener('click', () => choose([id]));
            item.appendChild(pick);
            list.appendChild(item);
        });
    };

    const reasonsText = (reasons) => reasons.map((reason) => strings[`dedupereason_${reason}`] || reason).join(', ');

    // The course that stays: a radio per course; enabled while the group is included.
    const drawCourses = (group, radios) => {
        const options = create('ul', 'tool-ceaorganizador-dialogo-lista');
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
            const details = [
                course.shortname,
                format(strings.activities, course.activities || 0),
                format(strings.dedupemodified, when),
                group.possible ? '' : reasonsText(course.reasons),
            ].filter((text) => text !== '');
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
        return options;
    };

    const drawGroup = (group) => {
        counter += 1;
        const box = create('section', 'tool-ceaorganizador-fusion-bloque');
        box.dataset.groupKey = group.key;
        box.appendChild(create('h3', 'tool-ceaorganizador-sugerencias-titulo', decode(group.path)));
        const radios = [];
        const enable = (value) => {
            group.included = value;
            radios.forEach((radio) => {
                radio.disabled = !value;
            });
            refresh();
        };
        if (group.possible) {
            // «Posibles repetidos»: nothing moves unless the user says they are the same course.
            box.dataset.possible = '1';
            box.appendChild(create('p', '', strings.possibleask));
            const reason = create('p', group.reason === 'group' ? 'tool-ceaorganizador-aviso' : 'tool-ceaorganizador-ruta',
                strings[`possiblereason_${group.reason}`] || group.reason);
            reason.dataset.region = 'reason';
            box.appendChild(reason);
            const yes = button('possibleyes', strings.possibleyes);
            const no = button('possibleno', strings.possibleno);
            const mark = () => {
                yes.setAttribute('aria-pressed', group.included ? 'true' : 'false');
                no.setAttribute('aria-pressed', group.included ? 'false' : 'true');
            };
            yes.addEventListener('click', () => {
                enable(true);
                mark();
            });
            no.addEventListener('click', () => {
                enable(false);
                mark();
            });
            mark();
            const answers = create('div', 'tool-ceaorganizador-acciones');
            answers.append(yes, no);
            box.appendChild(answers);
        } else {
            const includeWrap = create('div', 'custom-control form-check custom-checkbox');
            const include = create('input', 'custom-control-input form-check-input');
            include.type = 'checkbox';
            include.id = `tool-ceaorganizador-dedupe-${counter}`;
            include.dataset.action = 'include';
            include.checked = group.included;
            include.addEventListener('change', () => enable(include.checked));
            const includeLabel = create('label', 'custom-control-label form-check-label', strings.dedupeinclude);
            includeLabel.htmlFor = include.id;
            includeWrap.append(include, includeLabel);
            box.appendChild(includeWrap);
        }
        box.appendChild(drawCourses(group, radios));
        return box;
    };

    const draw = (result) => {
        groupsBox.textContent = '';
        const prepare = (group, possible) => {
            const kept = group.courses.find((course) => course.keep) || group.courses[0];
            // A sure group is included by default; a possible one only when the user says so.
            return {...group, keep: kept.courseid, default: kept.courseid, included: !possible, possible};
        };
        const sure = result.groups.map((group) => prepare(group, false));
        const possible = (result.possible || []).map((group) => prepare(group, true));
        groups = [...sure, ...possible];
        sure.forEach((group) => groupsBox.appendChild(drawGroup(group)));
        if (possible.length > 0) {
            const title = create('h3', 'tool-ceaorganizador-sugerencias-titulo', strings.possible);
            title.dataset.region = 'possible';
            groupsBox.appendChild(title);
            possible.forEach((group) => groupsBox.appendChild(drawGroup(group)));
        }
        const folders = result.folders || [];
        folderLine.textContent = format(strings.dedupefolder, folders.map((folder) => decode(folder.path)).join('; '));
        folderLine.hidden = groups.length === 0;
        // An existing visible folder would leave the moved courses visible.
        folderWarning.hidden = groups.length === 0 || !folders.some((folder) => folder.visible);
        if (groups.length === 0) {
            say(strings.dedupenothing);
        } else {
            say(format(strings.dedupegroups, sure.length));
        }
    };

    // One apply at a time. A preview is never blocked: choosing other categories discards the answer of the
    // one in flight (token) and asks again.
    const serial = exclusive((task) => task());

    const loadTask = async(keepNotice = false) => {
        const wanted = chosen.slice();
        if (wanted.length === 0) {
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
                planids = result.roots || wanted;
                hash = result.hash;
                draw(result);
                if (keepNotice) {
                    say(strings.dedupestale);
                }
                if (focus === 'possible') {
                    const first = groupsBox.querySelector('[data-action="possibleyes"]');
                    if (first) {
                        first.focus();
                    }
                }
            }
        } catch (error) {
            onError(error);
        } finally {
            setBusy(false);
        }
    };

    function choose(ids) {
        chosen = ids.slice();
        now.textContent = chosen.length > 0
            ? format(strings.dedupecategorynow, chosen.map((id) => pathOf(id)).join('; '))
            : '';
        // A preview still in flight belongs to the old choice: its answer is discarded.
        token++;
        planids = [];
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
        if (busy || planids.length === 0 || movesOf(groups) === 0) {
            return;
        }
        setBusy(true);
        let result = null;
        try {
            result = await api.dedupeApply({
                categoryids: planids,
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
        planids = [];
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
    if (chosen.length > 0) {
        ready = choose(chosen);
        closeButton.focus();
    } else {
        search.focus();
    }
    return {close: dialog.close, element: root, ready};
};
