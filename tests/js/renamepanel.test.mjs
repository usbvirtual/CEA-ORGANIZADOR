import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { buildOps, splitBatches, open } from 'tool_ceaorganizador/renamepanel';

globalThis.DOMParser = new JSDOM('').window.DOMParser;

test('buildOps de reemplazar Posgrado por Posgrados, palabra completa, da la operación de la tarea 4', () => {
    const ops = buildOps({
        ops: [{ type: 'replace', search: 'Posgrado', replace: 'Posgrados', casesensitive: false, wholeword: true }],
        includeshortname: false,
    });
    assert.deepEqual(ops, [
        { type: 'replace', search: 'Posgrado', replace: 'Posgrados', casesensitive: false, wholeword: true },
    ]);
});

test('buildOps respeta el orden, cubre limpiar y afijos y omite las operaciones vacías', () => {
    const ops = buildOps({
        ops: [
            { type: 'affix', position: 'prefix', mode: 'add', text: '2026 ' },
            { type: 'replace', search: '', replace: 'x' },
            { type: 'affix', position: 'suffix', mode: 'remove', text: '' },
            { type: 'clean', case: 'title' },
        ],
    });
    assert.deepEqual(ops, [
        { type: 'affix', position: 'prefix', mode: 'add', text: '2026 ' },
        { type: 'clean', case: 'title' },
    ]);
});

test('splitBatches parte en lotes de 500', () => {
    const list = Array.from({ length: 1001 }, (_, i) => i);
    assert.deepEqual(splitBatches(list, 500).map((b) => b.length), [500, 500, 1]);
    assert.deepEqual(splitBatches([], 500), []);
});

const strings = new Proxy({}, { get: (_, name) => (name === 'batchsplit' ? 'lotes {$a}' : String(name)) });
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const setup = (rows, overrides = {}) => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    const calls = { preview: [], apply: [], toasts: [], undo: [], reload: 0 };
    const api = {
        renamePreview: async (params) => {
            calls.preview.push(params);
            return { rows };
        },
        renameApply: async (params) => {
            calls.apply.push(params);
            return { results: [], logid: 40 + calls.apply.length };
        },
    };
    const panel = open({
        api,
        strings,
        toast: { show: (message, options) => calls.toasts.push({ message, options }) },
        onError: (error) => {
            throw error;
        },
        undo: async (logid) => {
            calls.undo.push(logid);
            return overrides.undoResult === undefined ? { ok: true, conflicts: [] } : overrides.undoResult;
        },
        reload: async () => {
            calls.reload += 1;
        },
        items: overrides.items || [{ type: 'course', id: 5 }, { type: 'course', id: 6 }],
        trigger: document.getElementById('t'),
        doc: document,
    });
    return { document, calls, panel };
};
const field = (document, name) => document.querySelector(`[data-field="${name}"]`);
const click = (document, action) => document.querySelector(`[data-action="${action}"]`).click();

test('la vista previa envía las operaciones como JSON y una fila con error queda deshabilitada y fuera de renameApply', async () => {
    const { document, calls } = setup([
        { type: 'course', id: 5, old: 'Posgrado', new: 'Posgrados', error: null },
        { type: 'course', id: 6, old: 'Otro', new: 'Dos', error: 'shortnametaken', oldshort: 'O', newshort: 'D' },
    ]);
    field(document, 'search').value = 'Posgrado';
    field(document, 'replace').value = 'Posgrados';
    field(document, 'wholeword').checked = true;
    click(document, 'preview');
    await flush();
    assert.deepEqual(calls.preview[0].items, [{ type: 'course', id: 5 }, { type: 'course', id: 6 }]);
    assert.deepEqual(JSON.parse(calls.preview[0].ops), [
        { type: 'replace', search: 'Posgrado', replace: 'Posgrados', casesensitive: false, wholeword: true },
    ]);
    assert.equal(calls.preview[0].manual, '{}');
    assert.equal(calls.preview[0].includeshortname, false);

    const bad = document.querySelector('[data-key="course:6"]');
    assert.equal(bad.querySelector('input[data-field="new"]').disabled, true);
    assert.match(bad.textContent, /renameerror_shortnametaken/);

    click(document, 'apply');
    await flush();
    assert.equal(calls.apply.length, 1);
    assert.deepEqual(calls.apply[0].changes, [{ type: 'course', id: 5, old: 'Posgrado', new: 'Posgrados' }]);
    assert.equal(calls.reload, 1);
    assert.equal(calls.toasts.length, 1);
    calls.toasts[0].options.onAction();
    await flush();
    assert.deepEqual(calls.undo, [41]);
});

test('el nombre editado a mano viaja en changes y en manual; con nombre corto se envía oldshort', async () => {
    const { document, calls } = setup([
        { type: 'course', id: 5, old: 'A', new: 'B', error: null, oldshort: 'a', newshort: 'b' },
    ], { items: [{ type: 'course', id: 5 }] });
    field(document, 'includeshortname').checked = true;
    click(document, 'preview');
    await flush();
    assert.equal(calls.preview[0].includeshortname, true);
    const input = document.querySelector('[data-key="course:5"] input[data-field="new"]');
    input.value = 'Manual';
    input.dispatchEvent(new document.defaultView.Event('input'));
    click(document, 'preview');
    await flush();
    assert.equal(calls.preview[1].manual, '{"course:5":"Manual"}');
    click(document, 'apply');
    await flush();
    assert.deepEqual(calls.apply[0].changes, [
        { type: 'course', id: 5, old: 'A', new: 'Manual', oldshort: 'a', newshort: 'b' },
    ]);
});

test('un nombre con <b> crudo se muestra como texto y no crea elementos', async () => {
    const { document } = setup([
        { type: 'category', id: 9, old: '<b>Crudo</b> & Co', new: '<i>Nuevo</i>', error: null },
    ], { items: [{ type: 'category', id: 9 }] });
    click(document, 'preview');
    await flush();
    const tr = document.querySelector('[data-key="category:9"]');
    assert.equal(tr.querySelector('b'), null);
    assert.equal(tr.querySelector('i'), null);
    assert.match(tr.textContent, /<b>Crudo<\/b> & Co/);
    assert.equal(tr.querySelector('input[data-field="new"]').value, '<i>Nuevo</i>');
});

test('más de 500 filas se aplican en lotes secuenciales con aviso y un solo Deshacer que deshace todos', async () => {
    const rows = Array.from({ length: 501 }, (_, i) => ({
        type: 'category', id: i + 1, old: `a${i}`, new: `b${i}`, error: null,
    }));
    const { document, calls } = setup(rows, { items: rows.map((r) => ({ type: 'category', id: r.id })) });
    click(document, 'preview');
    await flush();
    assert.match(document.querySelector('[data-region="notice"]').textContent, /lotes 2/);
    click(document, 'apply');
    await flush();
    assert.deepEqual(calls.apply.map((c) => c.changes.length), [500, 1]);
    assert.equal(calls.toasts.length, 1);
    calls.toasts[0].options.onAction();
    await flush();
    assert.deepEqual(calls.undo, [42, 41]);
});

test('el Deshacer de varios lotes se detiene si un deshacer falla o no responde', async () => {
    for (const undoResult of [{ ok: false, conflicts: ['x'] }, null]) {
        const rows = Array.from({ length: 501 }, (_, i) => ({
            type: 'category', id: i + 1, old: `a${i}`, new: `b${i}`, error: null,
        }));
        const { document, calls } = setup(rows, { items: rows.map((r) => ({ type: 'category', id: r.id })), undoResult });
        click(document, 'preview');
        await flush();
        click(document, 'apply');
        await flush();
        calls.toasts[0].options.onAction();
        await flush();
        assert.deepEqual(calls.undo, [42]);
    }
});
