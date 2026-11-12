import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { quickDedupe, quickMerge, fromSelection, outerIds, fill } from 'tool_ceaorganizador/oneclick';
import { createModel } from 'tool_ceaorganizador/treemodel';

const strings = new Proxy({}, {
    get: (_, name) => ({
        dedupeconfirm: 'Mover {$a->moves} en {$a->categories}',
        dedupedone: 'Movidos: {$a}',
        mergeconfirm: 'A Semillas {$a->moves}, a Repetidos {$a->repeated}',
        mergedone: '{$a} unificados',
        mergedonerepeated: 'A Repetidos: {$a}',
        possiblecount: 'Posibles: {$a}',
        partialdone: 'Interrumpido: {$a}',
        mergeseeddefault: 'Semillas',
    })[name] || String(name),
});
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

// Facultades (1) › Derecho (2); Semillas (3) › Programa (4); Otra (5).
const tree = () => createModel([
    { id: 1, parent: 0, name: 'Facultades', sortorder: 1 },
    { id: 2, parent: 1, name: 'Derecho', sortorder: 1 },
    { id: 3, parent: 0, name: 'Semillas', sortorder: 2 },
    { id: 4, parent: 3, name: 'Programa', sortorder: 1 },
    { id: 5, parent: 0, name: 'Otra', sortorder: 3 },
]);

const course = (courseid, keep = false) => ({
    courseid, fullname: `C${courseid}`, shortname: `S${courseid}`, timemodified: 1, visible: true, activities: 0,
    reasons: ['name'], keep,
});
const dedupePreview = (extra = {}) => ({
    roots: [2, 5],
    groups: [
        { rootid: 2, categoryid: 2, path: 'Facultades / Derecho', key: '10-11', courses: [course(10), course(11, true)] },
        { rootid: 5, categoryid: 5, path: 'Otra', key: '20-21-22', courses: [course(20, true), course(21), course(22)] },
    ],
    possible: [],
    folders: [],
    hash: 'h1',
    ...extra,
});

const setup = (overrides = {}) => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    globalThis.DOMParser = dom.window.DOMParser;
    const calls = { preview: [], apply: [], toasts: [], undo: [], reload: 0, panel: [], merge: [], errors: [] };
    const api = overrides.api || {
        dedupePreview: async (ids) => {
            calls.preview.push(ids);
            return overrides.dedupe || dedupePreview();
        },
        dedupeApply: async (params) => {
            calls.apply.push(params);
            return overrides.applied || { status: 'ok', logid: 9, moved: 3 };
        },
        createSeedroot: async (params) => {
            calls.seed = params;
            return { id: 3, name: 'Semillas', created: false };
        },
        mergePreview: async (params) => {
            calls.preview.push(params);
            return overrides.merge;
        },
        mergeApply: async (params) => {
            calls.apply.push(params);
            return { status: 'ok', logid: 12, moved: 2, repeated: 1, skipped: 0, emptied: [] };
        },
    };
    const options = {
        api,
        strings,
        toast: { show: (message, opts) => calls.toasts.push({ message, opts }) },
        onError: (error) => calls.errors.push(error),
        undo: async (logid) => calls.undo.push(logid),
        reload: async () => {
            calls.reload += 1;
        },
        getModel: () => tree(),
        openPanel: (opts) => calls.panel.push(opts),
        openDedupePanel: (opts) => calls.panel.push(opts),
        openMergePanel: (opts) => calls.merge.push(opts),
        trigger: document.getElementById('t'),
        doc: document,
    };
    return { document, calls, options };
};
const click = (document, action) => document.querySelector(`[data-region="oneclick"] [data-action="${action}"]`).click();
const question = (document) => document.querySelector('[data-region="oneclick"]');

test('fill y outerIds: marcadores con nombre y solo las categorías exteriores', () => {
    assert.equal(fill('{$a->moves} de {$a->total}', { moves: 2, total: 5 }), '2 de 5');
    assert.deepEqual(outerIds(tree(), [1, 2, 4, 5]), [1, 4, 5]);
});

test('sin selección el botón abre el panel; con selección corre el flujo de un clic', () => {
    const seen = [];
    fromSelection({ categoryids: [], quick: () => seen.push('quick'), panel: () => seen.push('panel') });
    fromSelection({ categoryids: [2], quick: (ids) => seen.push(ids), panel: () => seen.push('panel') });
    assert.deepEqual(seen, ['panel', [2]]);
});

test('Quitar repetidos con selección: una pregunta, Aplicar con los ganadores por defecto y aviso con Deshacer', async () => {
    const { document, calls, options } = setup();
    const done = quickDedupe({ ...options, categoryids: [2, 5] });
    await flush();
    assert.deepEqual(calls.preview, [[2, 5]]);
    assert.match(question(document).textContent, /Mover 3 en 2/);
    assert.equal(question(document).querySelector('[data-action="review"]'), null);
    click(document, 'apply');
    assert.equal(await done, 'apply');
    assert.deepEqual(calls.apply, [{
        categoryids: [2, 5],
        groups: [{ key: '10-11', keep: 11 }, { key: '20-21-22', keep: 20 }],
        hash: 'h1',
    }]);
    assert.equal(calls.toasts[0].message, 'Movidos: 3');
    assert.equal(calls.reload, 1);
    calls.toasts[0].opts.onAction();
    assert.deepEqual(calls.undo, [9]);
    assert.equal(question(document), null);
});

test('Ver detalle abre el panel con las categorías; Cancelar no aplica nada', async () => {
    const { document, calls, options } = setup();
    let done = quickDedupe({ ...options, categoryids: [2, 5] });
    await flush();
    click(document, 'detail');
    assert.equal(await done, 'detail');
    assert.deepEqual(calls.panel, [{ categoryids: [2, 5], focus: '' }]);
    done = quickDedupe({ ...options, categoryids: [2, 5] });
    await flush();
    click(document, 'cancel');
    assert.equal(await done, 'cancel');
    assert.deepEqual(calls.apply, []);
});

test('sin repetidos solo avisa, sin preguntar', async () => {
    const { document, calls, options } = setup({ dedupe: dedupePreview({ groups: [], possible: [] }) });
    assert.equal(await quickDedupe({ ...options, categoryids: [2] }), 'none');
    assert.equal(calls.toasts[0].message, 'dedupenone');
    assert.equal(question(document), null);
});

test('los posibles repetidos se anuncian; Revisar abre el panel sobre ellos y Aplicar solo envía los seguros', async () => {
    const possible = [{ rootid: 2, categoryid: 2, path: 'Derecho', key: '30-31', reason: 'group', courses: [course(30, true), course(31)] }];
    const { document, calls, options } = setup({ dedupe: dedupePreview({ possible }) });
    let done = quickDedupe({ ...options, categoryids: [2, 5] });
    await flush();
    assert.match(question(document).textContent, /Posibles: 1/);
    click(document, 'review');
    assert.equal(await done, 'review');
    assert.deepEqual(calls.panel, [{ categoryids: [2, 5], focus: 'possible' }]);
    done = quickDedupe({ ...options, categoryids: [2, 5] });
    await flush();
    click(document, 'apply');
    await done;
    assert.deepEqual(calls.apply[0].groups.map((group) => group.key), ['10-11', '20-21-22']);
});

test('Unificar con selección: Semillas como destino, las de dentro se dejan y se aplica de un clic', async () => {
    const merge = {
        rows: [
            { courseid: 40, action: 'move', existing: false },
            { courseid: 41, action: 'repeated', existing: false },
            { courseid: 42, action: 'move', existing: true },
        ],
        emptied: [],
        folder: { id: 0, visible: false, path: 'Semillas / Repetidos' },
        possible: [],
        hash: 'm1',
    };
    const { document, calls, options } = setup({ merge });
    const done = quickMerge({ ...options, categoryids: [1, 2, 4] });
    await flush();
    await flush();
    assert.deepEqual(calls.seed, { name: 'Semillas' });
    assert.deepEqual(calls.preview, [{ targetid: 3, sourceids: [1] }]);
    assert.match(question(document).textContent, /A Semillas 1, a Repetidos 1/);
    assert.match(question(document).textContent, /mergeinsideleft/);
    click(document, 'apply');
    assert.equal(await done, 'apply');
    assert.deepEqual(calls.apply, [{
        targetid: 3,
        sourceids: [1],
        rows: [{ courseid: 40, action: 'move' }, { courseid: 41, action: 'repeated' }, { courseid: 42, action: 'move' }],
        hash: 'm1',
        confirmed: [],
    }]);
    assert.equal(calls.toasts[0].message, '2 unificados · A Repetidos: 1');
    assert.equal(calls.reload, 1);
});

test('Unificar con todo dentro de Semillas busca repetidos dentro y lo dice', async () => {
    const { document, calls, options } = setup();
    const done = quickMerge({ ...options, categoryids: [4] });
    await flush();
    await flush();
    assert.deepEqual(calls.preview, [[4]]);
    assert.match(question(document).textContent, /mergealreadyinseeds/);
    click(document, 'detail');
    assert.equal(await done, 'detail');
    assert.deepEqual(calls.panel, [{ categoryids: [2, 5], focus: '' }]);
});

test('con el api real, los servicios reciben el nombre y los parámetros exactos', async () => {
    const real = await import('tool_ceaorganizador/api');
    const sent = [];
    real.setTransport(async (methodname, args) => {
        sent.push({ methodname, args });
        if (methodname === 'tool_ceaorganizador_dedupe_preview') {
            return dedupePreview({ roots: args.categoryids });
        }
        if (methodname === 'tool_ceaorganizador_create_seedroot') {
            return { id: 3, name: 'Semillas', created: false };
        }
        if (methodname === 'tool_ceaorganizador_merge_preview') {
            return { rows: [{ courseid: 40, action: 'move', existing: false }], emptied: [], possible: [], hash: 'm1' };
        }
        return { status: 'ok', logid: 1, moved: 1, repeated: 0, skipped: 0, emptied: [] };
    });
    try {
        const { document, options } = setup({ api: real });
        let done = quickDedupe({ ...options, categoryids: [2, 5] });
        await flush();
        click(document, 'apply');
        await done;
        done = quickMerge({ ...options, categoryids: [1] });
        await flush();
        await flush();
        click(document, 'apply');
        await done;
        assert.deepEqual(sent.map((entry) => entry.methodname), [
            'tool_ceaorganizador_dedupe_preview',
            'tool_ceaorganizador_dedupe_apply',
            'tool_ceaorganizador_create_seedroot',
            'tool_ceaorganizador_merge_preview',
            'tool_ceaorganizador_merge_apply',
        ]);
        assert.deepEqual(sent[0].args, { categoryids: [2, 5] });
        assert.deepEqual(sent[1].args, {
            categoryids: [2, 5],
            groups: [{ key: '10-11', keep: 11 }, { key: '20-21-22', keep: 20 }],
            hash: 'h1',
        });
        assert.deepEqual(sent[3].args, { targetid: 3, sourceids: [1] });
        assert.deepEqual(sent[4].args, {
            targetid: 3,
            sourceids: [1],
            rows: [{ courseid: 40, action: 'move' }],
            hash: 'm1',
            confirmed: [],
        });
    } finally {
        real.setTransport();
    }
});
