import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { createModel } from 'tool_ceaorganizador/treemodel';
import { createSelection } from 'tool_ceaorganizador/selection';
import { render } from 'tool_ceaorganizador/treeview';
import { run, treeButtons, fill } from 'tool_ceaorganizador/visibilitytree';
import { open as openHistory } from 'tool_ceaorganizador/historypanel';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const strings = new Proxy({
    hidetreeconfirm: 'Se ocultarán {$a->categories} categorías y {$a->courses} cursos',
    showtreeconfirm: 'Se mostrarán {$a->categories} categorías y {$a->courses} cursos',
    visibilitytreedone: 'Listo: {$a->categories} y {$a->courses}',
    visibilitytreepartial: 'Parcial: {$a->categories} y {$a->courses}',
}, { get: (target, name) => (name in target ? target[name] : String(name)) });

const page = () => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    globalThis.DOMParser = dom.window.DOMParser;
    return dom.window.document;
};

const setup = ({ preview = { categories: 12, courses: 340, hash: 'abc' }, apply, document = page() } = {}) => {
    const calls = { preview: [], apply: [], toasts: [], undo: [], reloads: 0, errors: [] };
    const options = {
        api: {
            visibilityTreePreview: async (categoryids, show) => {
                calls.preview.push([categoryids, show]);
                return preview;
            },
            visibilityTreeApply: async (categoryids, show, hash) => {
                calls.apply.push([categoryids, show, hash]);
                return apply || { status: 'ok', logid: 77, categories: 12, courses: 340 };
            },
        },
        strings,
        toast: { show: (message, opts = {}) => calls.toasts.push([message, opts]) },
        onError: (error) => calls.errors.push(error),
        undo: (logid) => calls.undo.push(logid),
        reload: async () => {
            calls.reloads += 1;
        },
        trigger: document.getElementById('t'),
        doc: document,
    };
    return { document, calls, options };
};

const dialogOf = (document) => document.querySelector('[data-region="visibilitytree"]');
const click = (document, action) => dialogOf(document).querySelector(`[data-action="${action}"]`).click();

test('fill reemplaza los marcadores {$a->nombre}', () => {
    assert.equal(fill('Se ocultarán {$a->categories} y {$a->courses}; {$a->categories}', { categories: 2, courses: 5 }),
        'Se ocultarán 2 y 5; 2');
});

test('Ocultar todo y Mostrar todo no se repiten en el menú de la categoría', async () => {
    const document = page();
    const container = document.createElement('div');
    document.body.appendChild(container);
    const actions = [];
    const model = createModel([{
        id: 1, parent: 0, name: 'A', path: 'A', depth: 1, sortorder: 1, visible: true, coursecount: 0, needsort: false,
    }]);
    render(container, model, {
        api: { getCourses: async () => ({ courses: [], total: 0 }) },
        strings,
        storage: null,
        selection: createSelection(),
        onAction: (action, item) => actions.push([action, Number(item.dataset.id)]),
    });
    const li = container.querySelector('[data-type="category"][data-id="1"]');
    li.querySelector('[data-action="menu"]').click();
    const found = [...li.querySelectorAll('[role="menuitem"]')].map((item) => item.dataset.action);
    // They live in the selection bar only.
    for (const action of ['hidetree', 'showtree', 'delete']) {
        assert.ok(!found.includes(action), action);
    }
    assert.deepEqual(actions, []);
});

test('confirma con los conteos, aplica con el hash y muestra el aviso con Deshacer, y recarga', async () => {
    const { document, calls, options } = setup();
    const pending = run({ ...options, categoryids: [4, 5], show: false });
    await flush();
    assert.deepEqual(calls.preview, [[[4, 5], false]]);
    const dialog = dialogOf(document);
    assert.equal(dialog.querySelector('h2').textContent, 'hidetree');
    assert.equal(dialog.querySelector('[data-region="counts"]').textContent, 'Se ocultarán 12 categorías y 340 cursos');
    assert.equal(calls.apply.length, 0);
    click(document, 'apply');
    const result = await pending;
    assert.equal(result.status, 'ok');
    assert.deepEqual(calls.apply, [[[4, 5], false, 'abc']]);
    assert.equal(dialogOf(document), null);
    assert.equal(calls.toasts.length, 1);
    assert.equal(calls.toasts[0][0], 'Listo: 12 y 340');
    assert.equal(calls.toasts[0][1].actionLabel, 'undo');
    calls.toasts[0][1].onAction();
    assert.deepEqual(calls.undo, [77]);
    assert.equal(calls.reloads, 1);
});

test('Mostrar todo usa su título y su texto', async () => {
    const { document, calls, options } = setup({ preview: { categories: 1, courses: 0, hash: 'h' } });
    const pending = run({ ...options, categoryids: [3], show: true });
    await flush();
    assert.equal(dialogOf(document).querySelector('h2').textContent, 'showtree');
    assert.equal(dialogOf(document).querySelector('[data-region="counts"]').textContent,
        'Se mostrarán 1 categorías y 0 cursos');
    click(document, 'apply');
    await pending;
    assert.deepEqual(calls.apply, [[[3], true, 'h']]);
});

test('cancelar no aplica nada; sin nada que cambiar no se pregunta', async () => {
    const one = setup();
    const pending = run({ ...one.options, categoryids: [4], show: false });
    await flush();
    click(one.document, 'cancel');
    assert.equal(await pending, null);
    assert.equal(one.calls.apply.length, 0);
    assert.equal(one.calls.toasts.length, 0);

    const two = setup({ preview: { categories: 0, courses: 0, hash: 'x' } });
    assert.equal(await run({ ...two.options, categoryids: [4], show: true }), null);
    assert.equal(dialogOf(two.document), null);
    assert.deepEqual(two.calls.toasts, [['visibilitytreenothing', {}]]);
    assert.equal(two.calls.apply.length, 0);
    assert.equal(await run({ ...two.options, categoryids: [], show: true }), null);
    assert.equal(two.calls.preview.length, 1);
});

test('con solo arreglos silenciosos de visibleold (total > 0) también se pregunta y se aplica', async () => {
    const { document, calls, options } = setup({ preview: { categories: 0, courses: 0, total: 2, hash: 's' } });
    const pending = run({ ...options, categoryids: [4], show: false });
    await flush();
    assert.equal(dialogOf(document).querySelector('[data-region="counts"]').textContent,
        'Se ocultarán 0 categorías y 0 cursos');
    click(document, 'apply');
    await pending;
    assert.deepEqual(calls.apply, [[[4], false, 's']]);
    const none = setup({ preview: { categories: 0, courses: 0, total: 0, hash: 'n' } });
    assert.equal(await run({ ...none.options, categoryids: [4], show: false }), null);
    assert.deepEqual(none.calls.toasts, [['visibilitytreenothing', {}]]);
});

test('si cambió desde la vista previa se avisa sin Deshacer; si se interrumpió se ofrece Deshacer', async () => {
    const stale = setup({ apply: { status: 'stale', logid: 0, categories: 0, courses: 0 } });
    let pending = run({ ...stale.options, categoryids: [4], show: false });
    await flush();
    click(stale.document, 'apply');
    await pending;
    assert.deepEqual(stale.calls.toasts, [['visibilitytreestale', {}]]);
    assert.equal(stale.calls.reloads, 0);

    const partial = setup({ apply: { status: 'partial', logid: 9, categories: 3, courses: 200 } });
    pending = run({ ...partial.options, categoryids: [4], show: false });
    await flush();
    click(partial.document, 'apply');
    await pending;
    assert.equal(partial.calls.toasts[0][0], 'Parcial: 3 y 200');
    partial.calls.toasts[0][1].onAction();
    assert.deepEqual(partial.calls.undo, [9]);
    assert.equal(partial.calls.reloads, 1);
});

test('un error del servidor (por ejemplo, demasiados elementos) va a onError y no pregunta', async () => {
    const { document, calls, options } = setup();
    options.api.visibilityTreePreview = async () => {
        throw new Error('error_toomanyvisibilitytree');
    };
    assert.equal(await run({ ...options, categoryids: [4], show: false }), null);
    assert.equal(calls.errors[0].message, 'error_toomanyvisibilitytree');
    assert.equal(dialogOf(document), null);
});

test('los botones de la barra de selección envían las categorías y se desactivan sin categorías', () => {
    const document = page();
    let ids = [];
    const sent = [];
    const bar = treeButtons({
        strings,
        categoryIds: () => ids,
        apply: (button, categoryids, show) => sent.push([button.dataset.action, categoryids, show]),
        doc: document,
    });
    assert.equal(bar.hide.textContent, 'hidetreeselected');
    assert.equal(bar.show.textContent, 'showtreeselected');
    assert.equal(bar.hide.disabled, true);
    assert.equal(bar.show.disabled, true);
    ids = [2, 7];
    bar.sync();
    assert.equal(bar.hide.disabled, false);
    bar.hide.click();
    bar.show.click();
    assert.deepEqual(sent, [['hidetreeselection', [2, 7], false], ['showtreeselection', [2, 7], true]]);
});

test('con el api real llega a los servicios con los parámetros exactos', async () => {
    const real = await import('tool_ceaorganizador/api');
    const sent = [];
    real.setTransport(async (methodname, args) => {
        sent.push([methodname, args]);
        if (methodname === 'tool_ceaorganizador_visibility_tree_preview') {
            return { categories: 2, courses: 3, hash: 'f00' };
        }
        return { status: 'ok', logid: 5, categories: 2, courses: 3 };
    });
    try {
        const { document, options } = setup();
        const pending = run({ ...options, api: real, categoryids: [8], show: true });
        await flush();
        click(document, 'apply');
        await pending;
        assert.deepEqual(sent, [
            ['tool_ceaorganizador_visibility_tree_preview', { categoryids: [8], show: true }],
            ['tool_ceaorganizador_visibility_tree_apply', { categoryids: [8], show: true, hash: 'f00' }],
        ]);
    } finally {
        real.setTransport();
    }
});

test('una entrada de Ocultar todo del historial se puede deshacer', async () => {
    const document = page();
    openHistory({
        api: {
            history: async () => ({
                rows: [{ id: 6, action: 'visibilitytree', summary: 'Ocultar todo: A', userfullname: '', timecreated: 1, undone: false }],
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
    assert.ok(document.querySelector('[data-id="6"] [data-action="undo"]'));
});
