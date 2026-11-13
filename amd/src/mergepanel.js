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
 * Merge panel ("Unificar categorías"): a target category, one or more source categories, a preview of the
 * courses that move and the apply step, which lists the categories that were left empty. Repeated courses come
 * in groups: for each course of a group the user chooses to keep it (move it to, or leave it in, the destination),
 * send it to the hidden «Repetidos» folder under the target, or leave it in the source; by default the most recent
 * one is kept and the others go to «Repetidos».
 *
 * Everything merge_preview, merge_apply and createSeedroot return is formatted (entities): it is decoded
 * with options.decode and written with textContent. Links are built with createElement/setAttribute and the
 * URL starts at options.wwwroot. The modules of Moodle arrive in the options, so this one runs in node:test.
 *
 * @module     tool_ceaorganizador/mergepanel
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {openDialog} from 'tool_ceaorganizador/dialog';
import {format} from 'tool_ceaorganizador/treeview';
import {exclusive} from 'tool_ceaorganizador/selection';

const SEPARATOR = ' / ';

let counter = 0;

/**
 * Opens the panel.
 *
 * @param {Object} options
 * @param {Object} options.api mergePreview, mergeApply and createSeedroot.
 * @param {Object} options.strings Strings of the panel.
 * @param {Object} options.toast Object with show(message, options).
 * @param {Function} options.onError Receives the exceptions of the web services.
 * @param {Function} options.undo Undoes a journal entry (async; resolves {ok} or nothing).
 * @param {Function} options.reload Reloads the tree from the server.
 * @param {Function} options.getModel Returns the live tree model (reload() replaces it).
 * @param {String} options.wwwroot Root URL of the site, for the links to the category management.
 * @param {Function} [options.decode] Turns a formatted string into plain text.
 * @param {Number} [options.sourceid] Category that starts selected as a source.
 * @param {Number} [options.targetid] Target that starts chosen; with sourceids, the preview is made at once.
 * @param {Number[]} [options.sourceids] Sources that start selected (the selection of the tree).
 * @param {Element} [options.trigger] Gets the focus back on close.
 * @param {Document} [options.doc]
 * @returns {{close: Function, element: Element}}
 */
export const open = ({
    api,
    strings,
    toast,
    onError,
    undo,
    reload,
    getModel,
    wwwroot,
    decode = (text) => text,
    sourceid = 0,
    targetid: initialtarget = 0,
    sourceids: initialsources = [],
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
    const search = (label) => {
        const node = create('input', 'form-control');
        node.type = 'search';
        node.setAttribute('aria-label', label);
        return node;
    };

    let closed = false;
    const dialog = openDialog({
        doc,
        title: strings.merge,
        trigger,
        className: 'tool-ceaorganizador-dialogo-ancho',
        onClose: () => {
            closed = true;
        },
    });
    const root = dialog.element;

    // Target: a searchable list and, next to it, the creation of a seed category.
    const targetSearch = search(strings.mergetarget);
    const targetList = create('ul', 'tool-ceaorganizador-dialogo-lista');
    targetList.dataset.region = 'targets';
    const targetNow = create('p', 'tool-ceaorganizador-ruta');
    targetNow.dataset.region = 'target';
    targetNow.setAttribute('role', 'status');
    const seedName = create('input', 'form-control');
    seedName.type = 'text';
    seedName.value = strings.mergeseeddefault;
    seedName.dataset.field = 'seedname';
    seedName.setAttribute('aria-label', strings.mergeseedname);
    const createButton = button('createseed', strings.mergecreate);
    const seedBox = create('div', 'tool-ceaorganizador-acciones');
    seedBox.append(seedName, createButton);
    const targetBox = create('section', 'tool-ceaorganizador-fusion-bloque');
    targetBox.append(create('h3', 'tool-ceaorganizador-sugerencias-titulo', strings.mergetarget),
        targetSearch, targetNow, targetList, seedBox);

    // Sources: a searchable list of checkboxes.
    const sourceSearch = search(strings.mergesources);
    const sourceList = create('ul', 'tool-ceaorganizador-dialogo-lista');
    sourceList.dataset.region = 'sources';
    const sourceBox = create('section', 'tool-ceaorganizador-fusion-bloque');
    sourceBox.append(create('h3', 'tool-ceaorganizador-sugerencias-titulo', strings.mergesources), sourceSearch, sourceList);

    const notice = create('p', 'tool-ceaorganizador-aviso');
    notice.dataset.region = 'notice';
    notice.setAttribute('role', 'status');
    notice.hidden = true;
    const previewButton = button('preview', strings.mergepreview);
    const applyButton = button('apply', format(strings.mergeapply, 0), 'btn btn-primary');
    applyButton.disabled = true;
    const closeButton = button('close', strings.close, 'btn btn-link');
    closeButton.addEventListener('click', () => dialog.close());
    const actions = create('div', 'tool-ceaorganizador-acciones');
    actions.append(previewButton, applyButton, closeButton);

    const headrow = create('tr');
    [strings.mergecourse, strings.mergefrom, strings.mergeto, strings.mergeaction]
        .forEach((text) => headrow.appendChild(create('th', '', text)));
    const thead = create('thead');
    thead.appendChild(headrow);
    const tbody = create('tbody');
    const table = create('table', 'table table-sm tool-ceaorganizador-tabla');
    table.append(thead, tbody);
    const tablewrap = create('div', 'tool-ceaorganizador-tabla-contenedor');
    tablewrap.dataset.region = 'preview';
    tablewrap.appendChild(table);
    tablewrap.hidden = true;
    const emptied = create('div', 'tool-ceaorganizador-aviso');
    emptied.dataset.region = 'emptied';
    emptied.hidden = true;
    const folderLine = create('p', 'tool-ceaorganizador-ruta');
    folderLine.dataset.region = 'folder';
    folderLine.hidden = true;
    const folderWarning = create('p', 'tool-ceaorganizador-aviso', strings.repeatedfoldervisible);
    folderWarning.dataset.region = 'foldervisible';
    folderWarning.setAttribute('role', 'status');
    folderWarning.hidden = true;
    const groupNotice = create('p', 'tool-ceaorganizador-aviso');
    groupNotice.dataset.region = 'groupnotice';
    groupNotice.setAttribute('role', 'alert');
    groupNotice.hidden = true;
    root.append(targetBox, sourceBox, actions, notice, folderLine, folderWarning, groupNotice, tablewrap, emptied);

    let targetid = 0;
    const sources = new Set(sourceid > 0 ? [sourceid] : initialsources.map(Number));
    // «Posibles repetidos» by key: the user confirms them one by one; until then nothing of them changes.
    let possibles = new Map();
    // What the preview was made with: the hash only matches these values.
    let plan = null;
    let rows = [];
    let hash = '';
    let busy = false;
    let token = 0;

    const say = (text) => {
        notice.textContent = text;
        notice.hidden = text === '';
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
    // A source cannot be the target, inside it or contain it; the server rejects those.
    const allowedSource = (id) => {
        const model = getModel();
        return id !== targetid && (targetid === 0
            || (!model.isDescendant(targetid, id) && !model.isDescendant(id, targetid)));
    };
    // A source that lies inside another chosen source is covered by it, so only the outer ones are sent.
    const sourceIds = () => {
        const model = getModel();
        const chosen = [...sources].filter((id) => allowedSource(id) && model.get(id));
        return chosen.filter((id) => !chosen.some((other) => other !== id && model.isDescendant(other, id)));
    };

    // Courses that the apply writes: a source course that does not stay in the source, or a target course that
    // goes to «Repetidos» (one that is kept is already where it belongs).
    const pending = () => rows.filter((row) => (row.existing ? row.action === 'repeated' : row.action !== 'skip')).length;

    // A group with courses in the target must leave one of them in the destination (mergerunner checks it too).
    const lostGroup = () => {
        const groups = new Map();
        rows.filter((row) => row.group).forEach((row) => {
            groups.set(row.group, [...(groups.get(row.group) || []), row]);
        });
        for (const members of groups.values()) {
            const intarget = members.some((row) => row.existing);
            const kept = members.some((row) => (row.existing ? row.action !== 'repeated' : row.action === 'move'));
            if (intarget && !kept) {
                return (members.find((row) => row.winner) || members[0]).fullname;
            }
        }
        return null;
    };

    const refresh = () => {
        const count = pending();
        const lost = lostGroup();
        applyButton.textContent = format(strings.mergeapply, count);
        applyButton.disabled = busy || plan === null || count === 0 || lost !== null;
        if (plan !== null) {
            groupNotice.textContent = lost === null ? '' : format(strings.error_groupwithoutwinner, decode(lost));
            groupNotice.hidden = lost === null;
        }
        previewButton.disabled = busy || targetid === 0 || sourceIds().length === 0;
        createButton.disabled = busy;
    };

    // Changing the target or the sources makes the preview obsolete.
    const invalidate = () => {
        // A preview still in flight belongs to the old choice: its answer is discarded.
        token++;
        plan = null;
        rows = [];
        tbody.textContent = '';
        tablewrap.hidden = true;
        possibles = new Map();
        possibleBox.hidden = true;
        folderLine.hidden = true;
        folderWarning.hidden = true;
        groupNotice.hidden = true;
        refresh();
    };

    const drawTargets = () => {
        const model = getModel();
        const shown = model.filter(targetSearch.value);
        targetList.textContent = '';
        ordered().filter((id) => shown.has(id)).forEach((id) => {
            const item = create('li');
            const pick = create('button', 'tool-ceaorganizador-dialogo-opcion btn btn-link', pathOf(id));
            pick.type = 'button';
            pick.dataset.targetId = String(id);
            pick.setAttribute('aria-pressed', id === targetid ? 'true' : 'false');
            pick.addEventListener('click', () => chooseTarget(id));
            item.appendChild(pick);
            targetList.appendChild(item);
        });
    };

    const drawSources = () => {
        const model = getModel();
        const shown = model.filter(sourceSearch.value);
        sourceList.textContent = '';
        ordered().filter((id) => shown.has(id) && allowedSource(id)).forEach((id) => {
            counter += 1;
            const item = create('li');
            const wrap = create('div', 'custom-control form-check custom-checkbox');
            const input = create('input', 'custom-control-input form-check-input');
            input.type = 'checkbox';
            input.id = `tool-ceaorganizador-merge-${counter}`;
            input.dataset.sourceId = String(id);
            input.checked = sources.has(id);
            input.addEventListener('change', () => {
                if (input.checked) {
                    sources.add(id);
                } else {
                    sources.delete(id);
                }
                invalidate();
            });
            const label = create('label', 'custom-control-label form-check-label', pathOf(id));
            label.htmlFor = input.id;
            wrap.append(input, label);
            item.appendChild(wrap);
            sourceList.appendChild(item);
        });
    };

    function chooseTarget(id) {
        targetid = id;
        targetNow.textContent = id > 0 ? format(strings.mergetargetnow, pathOf(id)) : '';
        drawTargets();
        drawSources();
        invalidate();
    }

    // The choice of a repeated course: keep it, send it to «Repetidos» or, for a source course, leave it there.
    const choiceOf = (row) => {
        const select = create('select', 'custom-select form-select');
        select.dataset.action = 'choice';
        select.setAttribute('aria-label', `${strings.mergeduplicate}: ${decode(row.fullname)}`);
        const choices = [['move', strings.mergekeep], ['repeated', strings.mergerepeated]];
        if (!row.existing) {
            choices.push(['skip', strings.mergeskip]);
        }
        choices.forEach(([value, label]) => {
            const option = create('option', '', label);
            option.value = value;
            select.appendChild(option);
        });
        select.value = row.action;
        select.addEventListener('change', () => {
            row.action = select.value;
            refresh();
        });
        return select;
    };

    // Yes or no for a possible group: yes makes it a group like the sure ones (winner kept, others to Repetidos),
    // no puts every course back to its default.
    const confirmPossible = (key, yes) => {
        const possible = possibles.get(key);
        possible.confirmed = yes;
        const keep = new Map(possible.courses.map((course) => [course.courseid, course.keep]));
        rows.filter((row) => row.possible === key).forEach((row) => {
            const winner = Boolean(keep.get(row.courseid));
            row.group = yes ? key : '';
            row.duplicate = yes;
            row.winner = yes && winner;
            row.action = yes && !winner ? 'repeated' : 'move';
        });
        // eslint-disable-next-line no-use-before-define
        drawPossibles();
        // eslint-disable-next-line no-use-before-define
        drawRows();
        refresh();
    };

    const possibleBox = create('section', 'tool-ceaorganizador-fusion-bloque');
    possibleBox.dataset.region = 'possible';
    possibleBox.hidden = true;
    root.insertBefore(possibleBox, tablewrap);

    const drawPossibles = () => {
        possibleBox.textContent = '';
        possibleBox.hidden = possibles.size === 0;
        if (possibles.size === 0) {
            return;
        }
        possibleBox.appendChild(create('h3', 'tool-ceaorganizador-sugerencias-titulo', strings.possible));
        possibles.forEach((possible) => {
            const item = create('div');
            item.dataset.possibleKey = possible.key;
            const names = possible.courses.map((course) => {
                const text = decode(course.fullname);
                return course.existing ? `${text} (${strings.mergeexisting})` : text;
            });
            item.appendChild(create('p', 'tool-ceaorganizador-nombre', `${strings.possibleask} ${names.join(' · ')}`));
            const reason = create('p', possible.reason === 'group' ? 'tool-ceaorganizador-aviso' : 'tool-ceaorganizador-ruta',
                strings[`possiblereason_${possible.reason}`] || possible.reason);
            reason.dataset.region = 'reason';
            item.appendChild(reason);
            const yes = button('possibleyes', strings.possibleyes);
            yes.setAttribute('aria-pressed', possible.confirmed ? 'true' : 'false');
            yes.addEventListener('click', () => confirmPossible(possible.key, true));
            const no = button('possibleno', strings.possibleno);
            no.setAttribute('aria-pressed', possible.confirmed ? 'false' : 'true');
            no.addEventListener('click', () => confirmPossible(possible.key, false));
            const answers = create('div', 'tool-ceaorganizador-acciones');
            answers.append(yes, no);
            item.appendChild(answers);
            possibleBox.appendChild(item);
        });
    };

    const drawRows = () => {
        tbody.textContent = '';
        rows.forEach((row) => {
            const tr = create('tr');
            tr.dataset.courseid = String(row.courseid);
            tr.dataset.duplicate = row.duplicate ? '1' : '0';
            if (row.group) {
                tr.dataset.group = row.group;
            }
            const name = create('td', 'tool-ceaorganizador-nombre', decode(row.fullname));
            if (row.existing) {
                name.appendChild(create('span', 'tool-ceaorganizador-etiqueta', strings.mergeexisting));
            }
            if (row.possible) {
                tr.dataset.possible = row.possible;
            }
            if (row.duplicate || row.possible) {
                name.appendChild(create('span', 'tool-ceaorganizador-ruta', ` · ${format(strings.activities, row.activities || 0)}`));
            }
            tr.appendChild(name);
            tr.appendChild(create('td', 'tool-ceaorganizador-ruta', decode(row.from)));
            const to = create('td');
            to.appendChild(create('div', 'tool-ceaorganizador-ruta', decode(row.to)));
            if (row.newcategories.length > 0) {
                const paths = row.newcategories.map((path) => decode(path));
                const tag = create('span', 'tool-ceaorganizador-etiqueta', strings.clonenew);
                tag.title = paths.join('\n');
                to.appendChild(tag);
                // Screen readers do not reach the tooltip: the same paths, visually hidden.
                const hidden = create('span', 'sr-only visually-hidden', ` ${paths.join('; ')}`);
                hidden.dataset.region = 'newcategories';
                to.appendChild(hidden);
            }
            tr.appendChild(to);
            const state = create('td');
            if (row.duplicate) {
                state.appendChild(create('span', 'tool-ceaorganizador-insignia', strings.mergeduplicate));
                if (row.winner) {
                    state.appendChild(create('span', 'tool-ceaorganizador-insignia', strings.mergewinner));
                    if (row.visible === false) {
                        const warning = create('span', 'tool-ceaorganizador-insignia', strings.winnerhidden);
                        warning.dataset.region = 'winnerhidden';
                        state.appendChild(warning);
                    }
                }
                const reasons = (row.reasons || []).map((reason) => strings[`dedupereason_${reason}`] || reason);
                if (reasons.length > 0) {
                    state.appendChild(create('span', 'tool-ceaorganizador-ruta', ` ${reasons.join(', ')} `));
                }
                state.appendChild(choiceOf(row));
            } else if (row.possible) {
                state.appendChild(create('span', 'tool-ceaorganizador-insignia', strings.possible));
            } else {
                state.textContent = strings.mergemove;
            }
            tr.appendChild(state);
            tbody.appendChild(tr);
        });
        tablewrap.hidden = rows.length === 0;
        if (rows.length === 0) {
            say(strings.mergenothing);
        }
    };

    const showFolder = (folder) => {
        const any = rows.some((row) => row.duplicate);
        folderLine.textContent = any && folder ? format(strings.mergerepeatedto, decode(folder.path)) : '';
        folderLine.hidden = !any || !folder;
        // An existing visible folder would leave the moved courses visible.
        folderWarning.hidden = !any || !folder || !folder.visible;
    };

    // One task at a time across the panel: the preview, the apply and the creation share the lock.
    const serial = exclusive((task) => task());

    const setBusy = (value) => {
        busy = value;
        root.setAttribute('aria-busy', value ? 'true' : 'false');
        refresh();
    };

    // Loads the preview of the current choice.
    const loadTask = async(keepNotice = false) => {
        const wanted = {targetid, sourceids: sourceIds()};
        if (wanted.targetid === 0 || wanted.sourceids.length === 0) {
            return;
        }
        const mine = ++token;
        if (!keepNotice) {
            say('');
        }
        emptied.hidden = true;
        emptied.textContent = '';
        setBusy(true);
        try {
            const result = await api.mergePreview(wanted);
            if (mine === token) {
                plan = wanted;
                hash = result.hash;
                rows = result.rows.map((row) => ({...row}));
                possibles = new Map((result.possible || []).map((possible) => [possible.key, {...possible, confirmed: false}]));
                drawPossibles();
                drawRows();
                showFolder(result.folder);
            }
        } catch (error) {
            onError(error);
        } finally {
            setBusy(false);
        }
    };
    const load = (keepNotice) => serial(() => loadTask(keepNotice));

    const showEmptied = (list) => {
        emptied.textContent = '';
        emptied.hidden = list.length === 0;
        if (list.length === 0) {
            return;
        }
        emptied.appendChild(create('strong', '', strings.mergeemptied));
        const ul = create('ul');
        list.forEach((category) => {
            const li = create('li');
            const link = doc.createElement('a');
            link.setAttribute('href', `${wwwroot}/course/management.php?categoryid=${Number(category.id)}`);
            link.setAttribute('target', '_blank');
            link.setAttribute('rel', 'noopener');
            link.textContent = decode(category.path);
            li.appendChild(link);
            ul.appendChild(li);
        });
        emptied.appendChild(ul);
    };

    const undoMerge = async(logid) => {
        const result = await undo(logid);
        if (result && result.ok && !closed) {
            emptied.hidden = true;
            emptied.textContent = '';
            await load();
        }
    };

    const applyTask = async() => {
        if (plan === null || pending() === 0) {
            return;
        }
        const wanted = plan;
        setBusy(true);
        let result = null;
        try {
            result = await api.mergeApply({
                targetid: wanted.targetid,
                sourceids: wanted.sourceids,
                rows: rows.map((row) => ({courseid: row.courseid, action: row.action})),
                confirmed: [...possibles.values()].filter((possible) => possible.confirmed).map((possible) => possible.key),
                hash,
            });
        } catch (error) {
            setBusy(false);
            onError(error);
            return;
        }
        if (result.status === 'stale') {
            say(strings.mergestale);
            setBusy(false);
            await loadTask(true);
            return;
        }
        // The preview is spent: whatever moved or stayed, a new one is made when it is wanted.
        plan = null;
        rows = [];
        tbody.textContent = '';
        tablewrap.hidden = true;
        possibles = new Map();
        possibleBox.hidden = true;
        folderLine.hidden = true;
        folderWarning.hidden = true;
        groupNotice.hidden = true;
        if (result.status === 'partial') {
            // It stopped halfway: what moved is recorded and can be undone.
            toast.show(format(strings.partialdone, result.moved), {
                actionLabel: strings.undo,
                onAction: () => undoMerge(result.logid),
            });
        } else if (result.logid > 0) {
            let message = format(strings.mergedone, result.moved);
            if (result.repeated > 0) {
                message += ` · ${format(strings.mergedonerepeated, result.repeated)}`;
            }
            toast.show(message, {
                actionLabel: strings.undo,
                onAction: () => undoMerge(result.logid),
            });
        } else {
            toast.show(strings.mergenothing);
        }
        let failure = null;
        try {
            if (result.logid > 0) {
                await reload();
            }
        } catch (error) {
            failure = error;
        }
        setBusy(false);
        if (!closed) {
            drawTargets();
            drawSources();
            showEmptied(result.emptied);
        }
        if (failure) {
            onError(failure);
        }
    };
    const apply = () => serial(applyTask);

    const createSeed = () => serial(async() => {
        const name = seedName.value.trim();
        if (name === '') {
            return;
        }
        setBusy(true);
        try {
            const result = await api.createSeedroot({name});
            if (result.created) {
                await reload();
            }
            say(result.created ? '' : strings.mergeseedexists);
            if (!closed) {
                chooseTarget(result.id);
            }
        } catch (error) {
            onError(error);
        } finally {
            setBusy(false);
        }
    });

    targetSearch.addEventListener('input', drawTargets);
    sourceSearch.addEventListener('input', drawSources);
    previewButton.addEventListener('click', () => load());
    applyButton.addEventListener('click', apply);
    createButton.addEventListener('click', createSeed);

    drawTargets();
    drawSources();
    refresh();
    targetSearch.focus();
    // From the selection of the tree: target and sources are given, and the preview is made at once.
    let ready = Promise.resolve();
    if (initialtarget > 0) {
        chooseTarget(Number(initialtarget));
        if (sourceIds().length > 0) {
            ready = load() || Promise.resolve();
        }
    }
    return {close: dialog.close, element: root, ready};
};
