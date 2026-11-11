import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import * as api from 'tool_ceaorganizador/api';
import { open } from 'tool_ceaorganizador/deletepanel';
import { open as openHistory } from 'tool_ceaorganizador/historypanel';
import { createModel } from 'tool_ceaorganizador/treemodel';
import { createSelection } from 'tool_ceaorganizador/selection';
import { render } from 'tool_ceaorganizador/treeview';
import { decodeEntities } from 'tool_ceaorganizador/text';

const strings = new Proxy({}, {
    get: (_, name) => ({
        deleteapply: 'Borrar ({$a})',
        deletedone: 'Borradas: {$a}',
        deleteskippedcount: 'Se conservaron: {$a}',
        deletereason_haschildren: 'Tiene subcategorías.',
        deletereason_hascourses: 'Tiene cursos.',
        deletereason_notfound: 'Ya no existe.',
        historypage: 'Página {$a}',
    })[name] || String(name),
});
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const preview = {
    delete: [
        { id: 3, path: 'Padre / &lt;b&gt;Hija&lt;/b&gt;' },
        { id: 2, path: 'Padre' },
    ],
    skipped: [
        { id: 4, name: 'Llena', path: 'Otra / Llena', reason: 'hascourses' },
        { id: 9, name: '', path: '', reason: 'notfound' },
    ],
};

const setup = (overrides = {}) => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    globalThis.DOMParser = dom.window.DOMParser;
    const calls = { preview: [], apply: [], toasts: [], undo: [], reload: 0, applied: 0, errors: [] };
    const panel = open({
        api: {
            deletePreview: async (ids) => {
                calls.preview.push(ids);
                return overrides.preview || preview;
            },
            deleteApply: async (ids) => {
                calls.apply.push(ids);
                return overrides.apply || { status: 'ok', logid: 12, deleted: 2, skipped: [] };
            },
        },
        strings,
        toast: { show: (message, options) => calls.toasts.push({ message, options }) },
        onError: (error) => calls.errors.push(error),
        undo: (logid) => calls.undo.push(logid),
        reload: async () => {
            calls.reload += 1;
        },
        ids: overrides.ids || [2, 3, 4, 9],
        onApplied: () => {
            calls.applied += 1;
        },
        decode: decodeEntities,
        trigger: document.getElementById('t'),
        doc: document,
    });
    return { document, calls, panel };
};
const region = (document, name) => document.querySelector(`[data-region="${name}"]`);

test('el diálogo lista lo que se borra y lo que se conserva, con el motivo, como texto', async () => {
    const { document, calls, panel } = setup();
    await panel.ready;
    assert.deepEqual(calls.preview, [[2, 3, 4, 9]]);
    const deleted = [...region(document, 'delete').querySelectorAll('li')];
    assert.deepEqual(deleted.map((li) => li.textContent), ['Padre / <b>Hija</b>', 'Padre']);
    // The decoded path is text, never markup.
    assert.equal(region(document, 'delete').querySelector('b'), null);
    const skipped = [...region(document, 'skipped').querySelectorAll('li')];
    assert.deepEqual(skipped.map((li) => li.dataset.reason), ['hascourses', 'notfound']);
    assert.equal(skipped[0].textContent, 'Otra / Llena · Tiene cursos.');
    assert.equal(skipped[1].textContent, '#9 · Ya no existe.');
    const apply = document.querySelector('[data-action="apply"]');
    assert.equal(apply.textContent, 'Borrar (2)');
    assert.equal(apply.disabled, false);
    assert.equal(region(document, 'notice').hidden, true);
});

test('aplicar envía los ids que se borran, cierra, recarga y el aviso ofrece Deshacer', async () => {
    const { document, calls, panel } = setup({
        apply: { status: 'ok', logid: 12, deleted: 2, skipped: [{ id: 3, name: '<b>Hija</b>', reason: 'haschildren' }] },
    });
    await panel.ready;
    document.querySelector('[data-action="apply"]').click();
    await flush();
    assert.deepEqual(calls.apply, [[3, 2]]);
    assert.equal(panel.element.isConnected, false);
    assert.equal(calls.reload, 1);
    assert.equal(calls.applied, 1);
    assert.equal(calls.toasts.length, 1);
    assert.equal(calls.toasts[0].message, 'Borradas: 2 Se conservaron: 1');
    assert.equal(calls.toasts[0].options.actionLabel, 'undo');
    calls.toasts[0].options.onAction();
    assert.deepEqual(calls.undo, [12]);
    assert.deepEqual(calls.errors, []);
});

test('sin categorías vacías no se puede aplicar y se avisa', async () => {
    const { document, calls, panel } = setup({
        ids: [4],
        preview: { delete: [], skipped: [{ id: 4, name: 'Llena', path: 'Llena', reason: 'hascourses' }] },
    });
    await panel.ready;
    const apply = document.querySelector('[data-action="apply"]');
    assert.equal(apply.disabled, true);
    assert.equal(region(document, 'delete').hidden, true);
    assert.equal(region(document, 'notice').textContent, 'deletenothing');
    apply.click();
    await flush();
    assert.deepEqual(calls.apply, []);
});

test('si al aplicar no se borra nada, no hay Deshacer ni recarga', async () => {
    const { document, calls, panel } = setup({
        apply: { status: 'ok', logid: 0, deleted: 0, skipped: [{ id: 2, name: 'Padre', reason: 'haschildren' }] },
    });
    await panel.ready;
    document.querySelector('[data-action="apply"]').click();
    await flush();
    assert.equal(calls.toasts[0].message, 'deletenothing Se conservaron: 1');
    assert.deepEqual(calls.toasts[0].options, {});
    assert.equal(calls.reload, 0);
    assert.equal(calls.applied, 0);
});

test('api: deletePreview y deleteApply envían los ids', async () => {
    const calls = [];
    api.setTransport(async (methodname, args) => {
        calls.push([methodname, args]);
        return {};
    });
    try {
        await api.deletePreview([4, 5]);
        await api.deleteApply([5]);
    } finally {
        api.setTransport();
    }
    assert.deepEqual(calls, [
        ['tool_ceaorganizador_delete_preview', { ids: [4, 5] }],
        ['tool_ceaorganizador_delete_apply', { ids: [5] }],
    ]);
});

test('el panel funciona con el api.js real', async () => {
    const dom = new JSDOM('<!doctype html><body></body>');
    globalThis.DOMParser = dom.window.DOMParser;
    const sent = [];
    api.setTransport(async (methodname, args) => {
        sent.push([methodname, args]);
        return methodname.endsWith('preview') ? preview : { status: 'ok', logid: 0, deleted: 0, skipped: [] };
    });
    try {
        const panel = open({
            api,
            strings,
            toast: { show: () => {} },
            onError: (error) => {
                throw error;
            },
            undo: () => {},
            reload: async () => {},
            ids: [2, 3],
            doc: dom.window.document,
        });
        await panel.ready;
        dom.window.document.querySelector('[data-action="apply"]').click();
        await flush();
    } finally {
        api.setTransport();
    }
    assert.deepEqual(sent, [
        ['tool_ceaorganizador_delete_preview', { ids: [2, 3] }],
        ['tool_ceaorganizador_delete_apply', { ids: [3, 2] }],
    ]);
});

test('el menú de una categoría ofrece Borrar', () => {
    const dom = new JSDOM('<!doctype html><div id="c"></div>');
    const { document } = dom.window;
    globalThis.DOMParser = dom.window.DOMParser;
    const container = document.getElementById('c');
    const actions = [];
    render(container, createModel([{
        id: 1, parent: 0, name: 'A', path: 'A', depth: 1, sortorder: 1, visible: true, coursecount: 0, needsort: false,
    }]), {
        api: { getCourses: async () => ({ courses: [], total: 0 }) },
        strings,
        storage: null,
        selection: createSelection(),
        onAction: (action, item) => actions.push([action, Number(item.dataset.id)]),
    });
    const li = container.querySelector('[data-type="category"][data-id="1"]');
    li.querySelector('[data-action="menu"]').click();
    const entry = li.querySelector('[role="menuitem"][data-action="delete"]');
    assert.equal(entry.textContent, 'deletecategory');
    entry.click();
    assert.deepEqual(actions, [['delete', 1]]);
});

test('una entrada de borrado del historial se puede deshacer', async () => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    openHistory({
        api: {
            history: async () => ({
                rows: [{ id: 8, action: 'delete', summary: 'Borrar: A', userfullname: '', timecreated: 1, undone: false }],
                total: 1,
                perpage: 20,
            }),
        },
        strings,
        onError: (error) => {
            throw error;
        },
        undo: async () => null,
        reload: async () => {},
        trigger: document.getElementById('t'),
        doc: document,
    });
    await flush();
    assert.ok(document.querySelector('[data-id="8"] [data-action="undo"]'));
});
