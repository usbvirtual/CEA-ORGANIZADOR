import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createModel } from 'tool_ceaorganizador/treemodel';
import { createMover } from 'tool_ceaorganizador/mover';

const row = (id, parent, sortorder) => ({ id, parent, name: `N${id}`, sortorder, coursecount: 0 });
const setup = (apiOverrides = {}) => {
    const model = createModel([row(1, 0, 1), row(2, 0, 2), row(3, 0, 3)]);
    model.get(2).coursecount = 2;
    const toasts = [];
    const errors = [];
    const calls = [];
    const cache = new Map([[2, { id: 2, items: [{ id: 20 }, { id: 21 }], total: 2, page: 1 }]]);
    const api = {
        moveCategory: async (...args) => { calls.push(['moveCategory', ...args]); return { logid: 7 }; },
        moveCourse: async (...args) => { calls.push(['moveCourse', ...args]); return { logid: 8 }; },
        undo: async (logid) => { calls.push(['undo', logid]); return { ok: true, conflicts: [] }; },
        ...apiOverrides,
    };
    const state = { cache };
    const ctx = {
        model,
        api,
        state,
        view: { refresh: () => { ctx.refreshed = (ctx.refreshed || 0) + 1; } },
        strings: { moved: 'Movido', undo: 'Deshacer', error_generic: 'Error', invaliddrop: 'No' },
        toast: { show: (message, options) => toasts.push({ message, options }) },
        onError: (e) => errors.push(e),
        reload: async () => { calls.push(['reload']); },
    };
    return { ctx, model, toasts, errors, calls, cache, mover: createMover(ctx) };
};
const order = (model, parent) => model.children(parent).map((r) => r.id);

test('un movimiento correcto aplica el cambio, llama a la API y muestra Movido con Deshacer', async () => {
    const { mover, model, toasts, calls } = setup();
    await mover.move({ type: 'category', id: 3 }, { parent: 0, afterid: 0 });
    assert.deepEqual(order(model, 0), [3, 1, 2]);
    assert.deepEqual(calls[0], ['moveCategory', 3, 0, 0]);
    assert.equal(toasts.length, 1);
    assert.equal(toasts[0].message, 'Movido');
    assert.equal(toasts[0].options.actionLabel, 'Deshacer');
});

test('Deshacer llama a undo con el logid y recarga el árbol si salió bien', async () => {
    const { mover, toasts, calls } = setup();
    await mover.move({ type: 'category', id: 3 }, { parent: 1, afterid: 0 });
    await toasts[0].options.onAction();
    assert.deepEqual(calls.slice(1), [['undo', 7], ['reload']]);
});

test('si undo devuelve conflictos los muestra y no recarga', async () => {
    const { mover, toasts, calls } = setup({ undo: async () => ({ ok: false, conflicts: ['uno', 'dos'] }) });
    await mover.move({ type: 'category', id: 3 }, { parent: 1, afterid: 0 });
    await toasts[0].options.onAction();
    assert.equal(toasts.length, 2);
    assert.equal(toasts[1].message, 'uno dos');
    assert.ok(!calls.some((c) => c[0] === 'reload'));
});

test('los conflictos de undo se decodifican antes de mostrarse (texto formateado)', async () => {
    const { ctx, mover, toasts } = setup({ undo: async () => ({ ok: false, conflicts: ['«A &amp; B» cambió', 'otro'] }) });
    ctx.decode = (text) => text.replace(/&amp;/g, '&');
    await mover.move({ type: 'category', id: 3 }, { parent: 1, afterid: 0 });
    await toasts[0].options.onAction();
    assert.equal(toasts[1].message, '«A & B» cambió otro');
});

test('si la API falla revierte el modelo y entrega el error a onError', async () => {
    const boom = new Error('fallo');
    const { mover, model, toasts, errors } = setup({ moveCategory: async () => { throw boom; } });
    await mover.move({ type: 'category', id: 3 }, { parent: 1, afterid: 0 });
    assert.deepEqual(order(model, 0), [1, 2, 3]);
    assert.deepEqual(order(model, 1), []);
    assert.deepEqual(errors, [boom]);
    assert.equal(toasts.length, 0);
});

test('un curso se mueve en la caché y los contadores, y se revierte si falla', async () => {
    const ok = setup();
    await ok.mover.move({ type: 'course', id: 20, category: 2 }, { category: 3, afterid: 0 });
    assert.deepEqual(ok.cache.get(2).items.map((c) => c.id), [21]);
    assert.equal(ok.model.get(2).coursecount, 1);
    assert.equal(ok.model.get(3).coursecount, 1);
    assert.deepEqual(ok.calls[0], ['moveCourse', 20, 3, 0]);

    const bad = setup({ moveCourse: async () => { throw new Error('x'); } });
    await bad.mover.move({ type: 'course', id: 21, category: 2 }, { category: 3, afterid: 0 });
    assert.deepEqual(bad.cache.get(2).items.map((c) => c.id), [20, 21]);
    assert.equal(bad.model.get(2).coursecount, 2);
    assert.equal(bad.model.get(3).coursecount, 0);
    assert.equal(bad.errors.length, 1);
});

test('moveInside mueve a la primera posición y no hace nada si es inválido o ya está ahí', async () => {
    const { mover, model, calls } = setup();
    await mover.moveInside({ type: 'category', id: 3 }, 2);
    assert.deepEqual(order(model, 2), [3]);
    await mover.moveInside({ type: 'category', id: 2 }, 3);
    assert.equal(calls.filter((c) => c[0] === 'moveCategory').length, 1);
});

test('mientras hay una petición en vuelo se ignora otro movimiento y luego se libera', async () => {
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    const { mover, model, toasts, calls } = setup({
        moveCategory: async (...args) => { calls.push(['moveCategory', ...args]); await gate; return { logid: 1 }; },
    });
    const first = mover.move({ type: 'category', id: 3 }, { parent: 0, afterid: 0 });
    const second = await mover.move({ type: 'category', id: 2 }, { parent: 0, afterid: 0 });
    assert.equal(second, null);
    assert.equal(toasts.length, 1);
    assert.equal(toasts[0].message, 'No');
    assert.deepEqual(order(model, 0), [3, 1, 2]);
    release();
    await first;
    await mover.move({ type: 'category', id: 2 }, { parent: 0, afterid: 0 });
    assert.deepEqual(order(model, 0), [2, 3, 1]);
});

test('el flag busy se libera también cuando la petición falla', async () => {
    let fail = true;
    const { mover, model } = setup({
        moveCategory: async () => { if (fail) { throw new Error('x'); } return { logid: 2 }; },
    });
    await mover.move({ type: 'category', id: 3 }, { parent: 0, afterid: 0 });
    fail = false;
    await mover.move({ type: 'category', id: 3 }, { parent: 0, afterid: 0 });
    assert.deepEqual(order(model, 0), [3, 1, 2]);
});

test('moveToRoot sube una subcategoría a la primera posición y no hace nada si ya está ahí', async () => {
    const { mover, model, calls } = setup();
    await mover.moveInside({ type: 'category', id: 3 }, 2);
    await mover.moveToRoot({ type: 'category', id: 3 });
    assert.deepEqual(order(model, 0), [3, 1, 2]);
    assert.deepEqual(calls.filter((c) => c[0] === 'moveCategory').pop(), ['moveCategory', 3, 0, 0]);
    const before = calls.length;
    assert.equal(await mover.moveToRoot({ type: 'category', id: 3 }), null);
    assert.equal(calls.length, before);
});
