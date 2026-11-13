import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { createModel } from 'tool_ceaorganizador/treemodel';
import { createSelection } from 'tool_ceaorganizador/selection';
import { render } from 'tool_ceaorganizador/treeview';
import { createVisibility, selectedCourseIds, visibilityTargets, loadedCategoryOf } from 'tool_ceaorganizador/visibility';
import { open as openHistory } from 'tool_ceaorganizador/historypanel';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const strings = new Proxy({}, { get: (_, name) => String(name) });

const setup = async () => {
    const dom = new JSDOM('<!doctype html><div id="c"></div>');
    globalThis.DOMParser = dom.window.DOMParser;
    const { document } = dom.window;
    const container = document.getElementById('c');
    const actions = [];
    const state = {
        api: {
            getCourses: async () => ({
                courses: [
                    { id: 10, fullname: 'Visible', visible: true },
                    { id: 11, fullname: 'Escondido', visible: false },
                ],
                total: 2,
            }),
        },
        strings,
        storage: null,
        expanded: new Set([1]),
        selection: createSelection(),
        onAction: (action, item) => actions.push([action, Number(item.dataset.id)]),
    };
    const model = createModel([{
        id: 1, parent: 0, name: 'A', path: 'A', depth: 1, sortorder: 1, visible: true, coursecount: 2, needsort: false,
    }]);
    render(container, model, state);
    await flush();
    return { dom, document, container, actions, state };
};

const course = (container, id) => container.querySelector(`[data-type="course"][data-id="${id}"]`);
test('los cursos no tienen menú: Ocultar y Mostrar están en la barra de la selección', async () => {
    const { container } = await setup();
    for (const id of [10, 11]) {
        const li = course(container, id);
        assert.equal(li.querySelector('[data-action="menu"]'), null);
        assert.equal(li.getAttribute('aria-keyshortcuts'), 'Space S');
    }
});

test('un solo Ocultar/Mostrar: categorías exteriores y los cursos que no están dentro de ellas', () => {
    // A (1) › B (2) › C (3); D (4).
    const model = createModel([
        { id: 1, parent: 0, name: 'A', sortorder: 1 },
        { id: 2, parent: 1, name: 'B', sortorder: 1 },
        { id: 3, parent: 2, name: 'C', sortorder: 1 },
        { id: 4, parent: 0, name: 'D', sortorder: 2 },
    ]);
    const cache = new Map([[3, { items: [{ id: 30 }] }], [4, { items: [{ id: 40 }] }]]);
    const items = [
        { type: 'category', id: 2 },
        { type: 'category', id: 3 },
        { type: 'course', id: 30 },
        { type: 'course', id: 40 },
        { type: 'course', id: 99 },
    ];
    assert.deepEqual(visibilityTargets(items, model, loadedCategoryOf(cache)), { categoryids: [2], courseids: [40, 99] });
    assert.deepEqual(visibilityTargets([{ type: 'course', id: 40 }], model, loadedCategoryOf(cache)),
        { categoryids: [], courseids: [40] });
    assert.equal(loadedCategoryOf(cache)(12345), undefined);
});

test('la barra envía solo los ids de los cursos seleccionados', async () => {
    const items = [{ type: 'category', id: 1 }, { type: 'course', id: 10 }, { type: 'course', id: 11 }];
    assert.deepEqual(selectedCourseIds(items), [10, 11]);
    assert.deepEqual(selectedCourseIds([{ type: 'category', id: 1 }]), []);

    const { container, state } = await setup();
    const sent = [];
    const visibility = createVisibility({
        api: { setVisibility: async (ids, visible) => { sent.push([ids, visible]); return { changed: 0, skipped: 2, logid: 0 }; } },
        state,
        container,
        strings,
        toast: { show: () => {} },
        undo: () => {},
        onError: (error) => { throw error; },
    });
    assert.equal(visibility.apply([], false), null);
    await visibility.apply(selectedCourseIds(items), false);
    assert.deepEqual(sent, [[[10, 11], false]]);
});

test('tras aplicar se actualiza la etiqueta Oculta en su lugar y el aviso ofrece Deshacer', async () => {
    const { document, container, state } = await setup();
    const toasts = [];
    const undone = [];
    const visibility = createVisibility({
        api: { setVisibility: async () => ({ changed: 2, skipped: 0, logid: 7 }) },
        state,
        container,
        strings,
        toast: { show: (message, options) => toasts.push([message, options]) },
        undo: (logid) => undone.push(logid),
        onError: (error) => { throw error; },
    });
    const before = course(container, 10);

    await visibility.apply([10, 11], false);

    // Same node: no redraw.
    assert.equal(course(container, 10), before);
    for (const id of [10, 11]) {
        const li = course(container, id);
        const labels = li.querySelectorAll('.tool-ceaorganizador-oculta');
        assert.equal(labels.length, 1);
        assert.equal(labels[0].textContent, 'hidden');
        assert.equal(li.getAttribute('aria-describedby'), labels[0].id);
        assert.equal(document.getElementById(labels[0].id), labels[0]);
        assert.equal(li.dataset.visible, '0');
    }
    assert.equal(course(container, 10).dataset.visible, '0');
    assert.equal(state.cache.get(1).items.find((item) => item.id === 10).visible, false);
    assert.equal(toasts.length, 1);
    assert.equal(toasts[0][0], 'visibilitychanged');
    assert.equal(toasts[0][1].actionLabel, 'undo');
    toasts[0][1].onAction();
    assert.deepEqual(undone, [7]);

    await visibility.apply([11], true);
    const shown = course(container, 11);
    assert.equal(shown.querySelector('.tool-ceaorganizador-oculta'), null);
    assert.equal(shown.hasAttribute('aria-describedby'), false);
    assert.equal(shown.dataset.visible, '1');
});

test('sin cambios no hay Deshacer, y con otra escritura en curso no se envía nada', async () => {
    const { container, state } = await setup();
    const toasts = [];
    let calls = 0;
    const options = {
        api: { setVisibility: async () => { calls += 1; return { changed: 0, skipped: 1, logid: 0 }; } },
        state,
        container,
        strings,
        toast: { show: (message, opts) => toasts.push(opts) },
        undo: () => {},
        onError: (error) => { throw error; },
    };
    await createVisibility(options).apply([10], true);
    assert.deepEqual(toasts, [{}]);
    assert.equal(createVisibility({ ...options, isBusy: () => true }).apply([10], false), null);
    const visibility = createVisibility(options);
    const first = visibility.apply([10], false);
    assert.equal(visibility.apply([10], false), null);
    await first;
    assert.equal(calls, 2);
});

test('una entrada de visibilidad del historial se puede deshacer', async () => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    openHistory({
        api: {
            history: async () => ({
                rows: [{ id: 5, action: 'visibility', summary: 'Ocultar: A', userfullname: '', timecreated: 1, undone: false }],
                total: 1,
                perpage: 20,
            }),
        },
        strings,
        onError: (error) => { throw error; },
        undo: async () => null,
        reload: async () => {},
        trigger: document.getElementById('t'),
        doc: document,
    });
    await flush();
    assert.ok(document.querySelector('[data-id="5"] [data-action="undo"]'));
});

test('más de 100 cursos se envían en lotes de 100, con un solo aviso y un Deshacer que deshace todos', async () => {
    const { container, state } = await setup();
    let reloads = 0;
    const sent = [];
    const toasts = [];
    const undone = [];
    const visibility = createVisibility({
        api: {
            setVisibility: async (ids, visible) => {
                sent.push([ids.length, visible]);
                return { changed: ids.length, skipped: 0, logid: 20 + sent.length };
            },
        },
        state,
        container,
        strings,
        toast: { show: (message, options) => toasts.push([message, options]) },
        undo: async (logid, options) => {
            undone.push([logid, options]);
            return { ok: true, conflicts: [] };
        },
        reload: async () => {
            reloads += 1;
        },
        onError: (error) => { throw error; },
    });
    const ids = Array.from({ length: 201 }, (_, i) => i + 100);
    const result = await visibility.apply(ids, false);
    assert.deepEqual(sent, [[100, false], [100, false], [1, false]]);
    assert.deepEqual(result.logids, [21, 22, 23]);
    assert.equal(toasts.length, 1);
    toasts[0][1].onAction();
    await flush();
    // Every batch undone without its own reload, and the tree reloaded once at the end.
    assert.deepEqual(undone, [[23, { reload: false }], [22, { reload: false }], [21, { reload: false }]]);
    assert.equal(reloads, 1);
});

test('si un lote falla, los anteriores quedan con su etiqueta y su Deshacer, y se informa el error', async () => {
    const { container, state } = await setup();
    const toasts = [];
    const errors = [];
    let calls = 0;
    const visibility = createVisibility({
        api: {
            setVisibility: async () => {
                calls += 1;
                if (calls === 2) {
                    throw new Error('falló');
                }
                return { changed: 100, skipped: 0, logid: 30 };
            },
        },
        state,
        container,
        strings,
        toast: { show: (message, options) => toasts.push(options) },
        undo: async () => ({ ok: true, conflicts: [] }),
        onError: (error) => errors.push(error.message),
    });
    const ids = [10, ...Array.from({ length: 150 }, (_, i) => i + 100)];
    const result = await visibility.apply(ids, false);
    assert.equal(result, null);
    assert.deepEqual(errors, ['falló']);
    assert.equal(toasts.length, 1);
    assert.equal(toasts[0].actionLabel, 'undo');
    // The course of the first batch shows its label; the second batch was not applied.
    assert.equal(course(container, 10).dataset.visible, '0');
});
