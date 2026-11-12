import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { open, movesOf } from 'tool_ceaorganizador/dedupepanel';
import { open as openHistory } from 'tool_ceaorganizador/historypanel';
import { createModel } from 'tool_ceaorganizador/treemodel';
import { decodeEntities } from 'tool_ceaorganizador/text';

const strings = new Proxy({}, {
    get: (_, name) => ({
        dedupeapply: 'Mover {$a} repetidos a Repetidos',
        dedupecategorynow: 'Categoría: {$a}',
        dedupedone: 'Movidos: {$a}',
        dedupefolder: 'Van a: {$a}',
        activities: '{$a} actividades',
        possiblecount: 'Posibles: {$a}',
        dedupeconfirm: 'Mover {$a->moves} en {$a->categories}',
        mergeconfirm: 'A Semillas {$a->moves}, a Repetidos {$a->repeated}',
        mergedone: '{$a} unificados',
        dedupegroups: 'Grupos: {$a}',
        dedupemodified: 'Modificado: {$a}',
        dedupereason_key: 'sin copia',
        dedupereason_name: 'mismo nombre',
        historypage: 'Página {$a}',
    })[name] || String(name),
});
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const tree = () => createModel([
    { id: 1, parent: 0, name: 'Facultad', sortorder: 1 },
    { id: 2, parent: 1, name: 'Programa &amp; A', sortorder: 1 },
    { id: 3, parent: 0, name: 'Otra', sortorder: 2 },
]);

const course = (courseid, fullname, extra = {}) => ({
    courseid,
    fullname,
    shortname: `S${courseid}`,
    timemodified: 1000,
    reasons: ['name'],
    keep: false,
    ...extra,
});
const preview = () => ({
    groups: [
        {
            categoryid: 2,
            path: 'Facultad / Programa &amp; A',
            key: '10-11-12',
            courses: [
                course(10, 'Cálculo &amp; <b>I</b>', { keep: true, timemodified: 3000 }),
                course(11, 'Cálculo I copia 1', { reasons: ['key'], activities: 3 }),
                course(12, 'Cálculo I (2025-2)', { reasons: ['key'] }),
            ],
        },
        {
            categoryid: 1,
            path: 'Facultad',
            key: '20-21',
            courses: [course(20, 'Física'), course(21, 'Física', { keep: true })],
        },
    ],
    roots: [1],
    possible: [],
    folders: [{ rootid: 1, id: 0, visible: false, path: 'Facultad / Repetidos' }],
    hash: 'h1',
});

const setup = (overrides = {}) => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    globalThis.DOMParser = dom.window.DOMParser;
    const calls = { preview: [], apply: [], toasts: [], undo: [], reload: 0, errors: [] };
    const api = overrides.api || {
        dedupePreview: async (categoryids) => {
            calls.preview.push(categoryids);
            const result = typeof overrides.preview === 'function' ? overrides.preview(categoryids) : (overrides.preview || preview());
            return { ...result, roots: categoryids };
        },
        dedupeApply: async (params) => {
            calls.apply.push(params);
            return overrides.apply || { status: 'ok', logid: 9, moved: 3 };
        },
    };
    const panel = open({
        api,
        strings,
        toast: { show: (message, options) => calls.toasts.push({ message, options }) },
        onError: (error) => calls.errors.push(error),
        undo: async (logid) => {
            calls.undo.push(logid);
            return { ok: true, conflicts: [] };
        },
        reload: async () => {
            calls.reload += 1;
        },
        getModel: () => overrides.model || tree(),
        decode: decodeEntities,
        categoryid: overrides.categoryid === undefined ? 1 : overrides.categoryid,
        trigger: document.getElementById('t'),
        doc: document,
    });
    return { document, calls, panel };
};
const action = (document, name) => document.querySelector(`[data-action="${name}"]`);
const group = (document, key) => document.querySelector(`[data-group-key="${key}"]`);

test('movesOf cuenta todos menos el que se conserva, solo en los grupos incluidos', () => {
    assert.equal(movesOf([
        { included: true, courses: [1, 2, 3] },
        { included: false, courses: [1, 2] },
        { included: true, courses: [1, 2] },
    ]), 3);
    assert.equal(movesOf([]), 0);
});

test('desde el menú analiza la categoría; los nombres crudos van como texto y las rutas se decodifican', async () => {
    const { document, calls, panel } = setup();
    await panel.ready;
    assert.deepEqual(calls.preview, [[1]]);
    const first = group(document, '10-11-12');
    // The path is formatted: decoded. The course name is raw: never decoded, never HTML.
    assert.equal(first.querySelector('h3').textContent, 'Facultad / Programa & A');
    const name = first.querySelector('li[data-courseid="10"] .tool-ceaorganizador-nombre');
    assert.equal(name.textContent, 'Cálculo &amp; <b>I</b>');
    assert.equal(first.querySelector('b'), null);
    assert.match(first.querySelector('li[data-courseid="11"]').textContent, /S11 · 3 actividades · Modificado: .* · sin copia/);
    assert.match(first.querySelector('li[data-courseid="10"]').textContent, /dedupekeep/);
    assert.equal(first.querySelector('input[type="radio"]:checked').value, '10');
    assert.equal(group(document, '20-21').querySelector('input[type="radio"]:checked').value, '21');
    assert.equal(document.querySelector('[data-region="folder"]').textContent, 'Van a: Facultad / Repetidos');
    assert.equal(document.querySelector('[data-region="notice"]').textContent, 'Grupos: 2');
    assert.equal(action(document, 'apply').textContent, 'Mover 3 repetidos a Repetidos');
    assert.equal(action(document, 'apply').disabled, false);
});

test('se elige otro que se conserva, se excluye un grupo y se aplica con Deshacer', async () => {
    const { document, calls, panel } = setup();
    await panel.ready;
    const second = group(document, '20-21');
    const include = second.querySelector('[data-action="include"]');
    include.checked = false;
    include.dispatchEvent(new document.defaultView.Event('change'));
    assert.equal(action(document, 'apply').textContent, 'Mover 2 repetidos a Repetidos');
    assert.equal(second.querySelector('input[type="radio"]').disabled, true);
    const radio = group(document, '10-11-12').querySelector('input[value="12"]');
    radio.checked = true;
    radio.dispatchEvent(new document.defaultView.Event('change'));
    action(document, 'apply').click();
    await flush();
    await flush();
    assert.deepEqual(calls.apply, [{ categoryids: [1], groups: [{ key: '10-11-12', keep: 12 }], hash: 'h1' }]);
    assert.equal(calls.reload, 1);
    assert.equal(calls.toasts[0].message, 'Movidos: 3');
    // A new preview follows the apply.
    assert.deepEqual(calls.preview, [[1], [1]]);
    calls.toasts[0].options.onAction();
    await flush();
    await flush();
    assert.deepEqual(calls.undo, [9]);
    assert.deepEqual(calls.preview, [[1], [1], [1]]);
});

test('sin categoría pide una; si algo cambió avisa y regenera la vista previa', async () => {
    const { document, calls } = setup({
        categoryid: 0,
        apply: { status: 'stale', logid: 0, moved: 0 },
    });
    assert.deepEqual(calls.preview, []);
    assert.equal(action(document, 'apply').disabled, true);
    const pick = document.querySelector('[data-category-id="2"]');
    assert.equal(pick.textContent, 'Facultad / Programa & A');
    pick.click();
    await flush();
    assert.deepEqual(calls.preview, [[2]]);
    assert.equal(document.querySelector('[data-region="category"]').textContent, 'Categoría: Facultad / Programa & A');
    action(document, 'apply').click();
    await flush();
    await flush();
    assert.equal(calls.apply.length, 1);
    assert.deepEqual(calls.preview, [[2], [2]]);
    assert.equal(calls.toasts.length, 0);
    assert.equal(document.querySelector('[data-region="notice"]').textContent, 'dedupestale');
});

test('sin repetidos lo dice y no deja aplicar', async () => {
    const { document, panel } = setup({ preview: { groups: [], possible: [], folders: [{ rootid: 1, id: 0, visible: false, path: 'Facultad / Repetidos' }], hash: 'h' } });
    await panel.ready;
    assert.equal(document.querySelector('[data-region="notice"]').textContent, 'dedupenothing');
    assert.equal(document.querySelector('[data-region="folder"]').hidden, true);
    assert.equal(action(document, 'apply').disabled, true);
});

test('con el api real, los dos servicios reciben el nombre y los parámetros exactos', async () => {
    const real = await import('tool_ceaorganizador/api');
    const sent = [];
    real.setTransport(async (methodname, args) => {
        sent.push({ methodname, args });
        if (methodname === 'tool_ceaorganizador_dedupe_preview') {
            return preview();
        }
        return { status: 'ok', logid: 4, moved: 3 };
    });
    try {
        const { document, calls, panel } = setup({ api: real });
        await panel.ready;
        assert.deepEqual(sent[0], { methodname: 'tool_ceaorganizador_dedupe_preview', args: { categoryids: [1] } });
        action(document, 'apply').click();
        await flush();
        await flush();
        assert.deepEqual(sent[1], {
            methodname: 'tool_ceaorganizador_dedupe_apply',
            args: {
                categoryids: [1],
                groups: [{ key: '10-11-12', keep: 10 }, { key: '20-21', keep: 21 }],
                hash: 'h1',
            },
        });
        assert.equal(calls.errors.length, 0);
    } finally {
        real.setTransport();
    }
});

test('el historial ofrece Deshacer en una entrada dedupe', async () => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    globalThis.DOMParser = dom.window.DOMParser;
    const undone = [];
    openHistory({
        api: {
            history: async () => ({
                rows: [{ id: 5, action: 'dedupe', summary: 'Quitar repetidos en X', userfullname: '', timecreated: 1, undone: false }],
                total: 1,
                perpage: 20,
            }),
        },
        strings,
        onError: (error) => {
            throw error;
        },
        undo: async (logid) => {
            undone.push(logid);
            return { ok: true, conflicts: [] };
        },
        reload: async () => {},
        decode: decodeEntities,
        trigger: document.getElementById('t'),
        doc: document,
    });
    await flush();
    document.querySelector('[data-action="undo"]').click();
    await flush();
    assert.deepEqual(undone, [5]);
});

test('un ganador oculto y una carpeta Repetidos visible se avisan; un resultado parcial ofrece Deshacer', async () => {
    const hidden = preview();
    hidden.groups[0].courses[0].visible = false;
    hidden.groups[1].courses[1].visible = true;
    hidden.folders = [{ rootid: 1, id: 7, path: 'Facultad / Repetidos', visible: true }];
    const { document, calls, panel } = setup({ preview: hidden, apply: { status: 'partial', logid: 30, moved: 2 } });
    await panel.ready;
    assert.notEqual(group(document, '10-11-12').querySelector('[data-region="winnerhidden"]'), null);
    assert.equal(group(document, '20-21').querySelector('[data-region="winnerhidden"]'), null);
    assert.equal(document.querySelector('[data-region="foldervisible"]').hidden, false);
    action(document, 'apply').click();
    await flush();
    await flush();
    assert.equal(calls.toasts[0].message, 'partialdone');
    calls.toasts[0].options.onAction();
    await flush();
    assert.deepEqual(calls.undo, [30]);
    assert.equal(calls.reload, 1);
});

test('un posible repetido pregunta Sí o No: por defecto no se mueve; con Sí entra al lote con su ganador', async () => {
    const withPossible = preview();
    withPossible.possible = [{
        rootid: 1,
        categoryid: 2,
        path: 'Facultad / Programa',
        key: '30-31',
        reason: 'group',
        courses: [course(30, 'Física G1', { activities: 5, keep: true }), course(31, 'Física G2')],
    }];
    const { document, calls, panel } = setup({ preview: withPossible });
    await panel.ready;
    const box = group(document, '30-31');
    assert.equal(box.dataset.possible, '1');
    assert.match(box.querySelector('[data-region="reason"]').textContent, /possiblereason_group/);
    assert.match(box.textContent, /5 actividades/);
    // Sure groups only: 2 + 1.
    assert.equal(action(document, 'apply').textContent, 'Mover 3 repetidos a Repetidos');
    assert.equal(box.querySelector('input[type="radio"]').disabled, true);
    box.querySelector('[data-action="possibleyes"]').click();
    assert.equal(action(document, 'apply').textContent, 'Mover 4 repetidos a Repetidos');
    assert.equal(box.querySelector('input[type="radio"]').disabled, false);
    // The winner can still be changed.
    const other = box.querySelector('input[value="31"]');
    other.checked = true;
    other.dispatchEvent(new document.defaultView.Event('change'));
    box.querySelector('[data-action="possibleno"]').click();
    assert.equal(action(document, 'apply').textContent, 'Mover 3 repetidos a Repetidos');
    box.querySelector('[data-action="possibleyes"]').click();
    action(document, 'apply').click();
    await flush();
    await flush();
    assert.deepEqual(calls.apply[0].groups, [
        { key: '10-11-12', keep: 10 },
        { key: '20-21', keep: 21 },
        { key: '30-31', keep: 31 },
    ]);
});
