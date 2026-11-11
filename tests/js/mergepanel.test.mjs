import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { open } from 'tool_ceaorganizador/mergepanel';
import { open as openHistory } from 'tool_ceaorganizador/historypanel';
import { createModel } from 'tool_ceaorganizador/treemodel';
import { decodeEntities } from 'tool_ceaorganizador/text';

const strings = new Proxy({}, {
    get: (_, name) => ({
        mergeapply: 'Unificar ({$a})',
        mergedone: '{$a} unificados',
        mergetargetnow: 'Destino: {$a}',
        mergeseeddefault: 'Semillas',
        mergemove: 'Mover',
        mergeskip: 'Omitir',
        historypage: 'Página {$a}',
    })[name] || String(name),
});
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const tree = () => createModel([
    { id: 1, parent: 0, name: 'Facultades', sortorder: 1 },
    { id: 2, parent: 1, name: 'Ingeniería', sortorder: 1 },
    { id: 3, parent: 0, name: 'Semillas', sortorder: 2 },
    { id: 4, parent: 3, name: 'Programa', sortorder: 1 },
    { id: 5, parent: 0, name: 'Otras', sortorder: 3 },
]);

const row = (courseid, fullname, extra = {}) => ({
    courseid,
    fullname,
    from: 'Facultades / Ingeniería',
    to: 'Semillas / Ingeniería',
    newcategories: [],
    duplicate: false,
    action: 'move',
    ...extra,
});
const previewOf = (rows, emptied = []) => ({ rows, emptied, hash: 'abc123' });

const setup = (overrides = {}) => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    globalThis.DOMParser = dom.window.DOMParser;
    const calls = { preview: [], apply: [], create: [], toasts: [], undo: [], reload: 0, errors: [] };
    let model = overrides.model || tree();
    const api = overrides.api || {
        mergePreview: async (params) => {
            calls.preview.push(params);
            return typeof overrides.preview === 'function' ? overrides.preview(params) : overrides.preview;
        },
        mergeApply: async (params) => {
            calls.apply.push(params);
            return typeof overrides.apply === 'function' ? overrides.apply(params) : overrides.apply;
        },
        createSeedroot: async (params) => {
            calls.create.push(params);
            return overrides.create;
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
            if (overrides.reloaded) {
                model = overrides.reloaded;
            }
        },
        getModel: () => model,
        wwwroot: 'https://moodle.test',
        decode: decodeEntities,
        sourceid: overrides.sourceid || 0,
        trigger: document.getElementById('t'),
        doc: document,
    });
    return { document, calls, panel };
};
const action = (document, name) => document.querySelector(`[data-action="${name}"]`);
const pickTarget = (document, id) => document.querySelector(`[data-target-id="${id}"]`).click();
const sourceBox = (document, id) => document.querySelector(`[data-source-id="${id}"]`);
const check = (document, id, value) => {
    const box = sourceBox(document, id);
    box.checked = value;
    box.dispatchEvent(new document.defaultView.Event('change'));
};

test('la categoría del menú queda como origen y el destino y su subárbol no se ofrecen como origen', () => {
    const { document } = setup({ sourceid: 5 });
    assert.equal(sourceBox(document, 5).checked, true);
    assert.equal(sourceBox(document, 3).checked, false);
    assert.equal(action(document, 'preview').disabled, true);
    pickTarget(document, 3);
    assert.equal(sourceBox(document, 3), null);
    assert.equal(sourceBox(document, 4), null);
    assert.notEqual(sourceBox(document, 1), null);
    assert.equal(action(document, 'preview').disabled, false);
    assert.match(document.querySelector('[data-region="target"]').textContent, /^Destino: Semillas$/);
    // A category that contains the target cannot be a source either.
    pickTarget(document, 2);
    assert.equal(sourceBox(document, 1), null);
});

test('los orígenes anidados se envían solo por el externo', async () => {
    const { document, calls } = setup({ preview: previewOf([]) });
    pickTarget(document, 5);
    check(document, 1, true);
    check(document, 2, true);
    action(document, 'preview').click();
    await flush();
    assert.deepEqual(calls.preview, [{ targetid: 5, sourceids: [1] }]);
});

test('la vista previa muestra Nueva con texto oculto y Repetido con interruptor; N cuenta los que se mueven', async () => {
    const { document, calls } = setup({
        sourceid: 1,
        preview: previewOf([
            row(10, 'Cálculo', { newcategories: ['Semillas / Ingeniería'] }),
            row(11, 'Física', { duplicate: true, action: 'skip' }),
            row(12, 'Álgebra'),
        ]),
        apply: { status: 'ok', logid: 7, moved: 2, skipped: 1, emptied: [] },
    });
    pickTarget(document, 3);
    action(document, 'preview').click();
    await flush();
    assert.deepEqual(calls.preview, [{ targetid: 3, sourceids: [1] }]);
    const first = document.querySelector('tr[data-courseid="10"]');
    assert.match(first.querySelector('.tool-ceaorganizador-etiqueta').textContent, /clonenew/);
    assert.match(first.querySelector('[data-region="newcategories"]').textContent, /Semillas \/ Ingeniería/);
    assert.equal(document.querySelector('tr[data-courseid="12"] .tool-ceaorganizador-etiqueta'), null);
    assert.equal(action(document, 'apply').textContent, 'Unificar (2)');
    const toggle = document.querySelector('tr[data-courseid="11"] [data-action="toggle"]');
    assert.equal(toggle.textContent, 'Omitir');
    assert.equal(document.querySelector('tr[data-courseid="10"] [data-action="toggle"]'), null);
    toggle.click();
    assert.equal(toggle.textContent, 'Mover');
    assert.equal(action(document, 'apply').textContent, 'Unificar (3)');
    toggle.click();
    assert.equal(action(document, 'apply').textContent, 'Unificar (2)');
    action(document, 'apply').click();
    await flush();
    // The skipped row travels as skip.
    assert.deepEqual(calls.apply[0], {
        targetid: 3,
        sourceids: [1],
        rows: [
            { courseid: 10, action: 'move' },
            { courseid: 11, action: 'skip' },
            { courseid: 12, action: 'move' },
        ],
        hash: 'abc123',
    });
    assert.equal(calls.reload, 1);
    assert.equal(calls.toasts[0].message, '2 unificados');
    calls.toasts[0].options.onAction();
    await flush();
    assert.deepEqual(calls.undo, [7]);
});

test('con el api real, los tres servicios reciben el nombre y los parámetros exactos', async () => {
    const real = await import('tool_ceaorganizador/api');
    const sent = [];
    real.setTransport(async (methodname, args) => {
        sent.push({ methodname, args });
        if (methodname === 'tool_ceaorganizador_create_seedroot') {
            return { id: 9, name: args.name, created: true };
        }
        if (methodname === 'tool_ceaorganizador_merge_preview') {
            return previewOf([row(10, 'Cálculo'), row(11, 'Física', { duplicate: true, action: 'skip' })]);
        }
        return { status: 'ok', logid: 4, moved: 1, skipped: 1, emptied: [{ id: 2, path: 'Facultades / Ingeniería' }] };
    });
    try {
        const { document, calls } = setup({
            api: real,
            sourceid: 1,
            reloaded: createModel([
                { id: 1, parent: 0, name: 'Facultades', sortorder: 1 },
                { id: 9, parent: 0, name: 'Semillas', sortorder: 2 },
            ]),
        });
        document.querySelector('[data-field="seedname"]').value = 'Mis semillas';
        action(document, 'createseed').click();
        await flush();
        assert.deepEqual(sent[0], { methodname: 'tool_ceaorganizador_create_seedroot', args: { name: 'Mis semillas' } });
        assert.equal(calls.reload, 1);
        // The result is chosen as the target.
        assert.equal(document.querySelector('[data-target-id="9"]').getAttribute('aria-pressed'), 'true');
        action(document, 'preview').click();
        await flush();
        assert.deepEqual(sent[1], { methodname: 'tool_ceaorganizador_merge_preview', args: { targetid: 9, sourceids: [1] } });
        action(document, 'apply').click();
        await flush();
        assert.deepEqual(sent[2], {
            methodname: 'tool_ceaorganizador_merge_apply',
            args: {
                targetid: 9,
                sourceids: [1],
                rows: [{ courseid: 10, action: 'move' }, { courseid: 11, action: 'skip' }],
                hash: 'abc123',
            },
        });
        assert.equal(calls.errors.length, 0);
    } finally {
        real.setTransport();
    }
});

test('si algo cambió, la vista previa se vuelve a generar con el aviso', async () => {
    let applied = 0;
    const { document, calls } = setup({
        sourceid: 1,
        preview: previewOf([row(10, 'Cálculo')]),
        apply: () => {
            applied += 1;
            return { status: 'stale', logid: 0, moved: 0, skipped: 0, emptied: [] };
        },
    });
    pickTarget(document, 3);
    action(document, 'preview').click();
    await flush();
    action(document, 'apply').click();
    await flush();
    assert.equal(applied, 1);
    assert.equal(calls.preview.length, 2);
    assert.equal(calls.toasts.length, 0);
    assert.equal(document.querySelector('[data-region="notice"]').textContent, 'mergestale');
    assert.equal(action(document, 'apply').disabled, false);
});

test('las categorías vacías se listan con enlaces a la gestión de Moodle, sin HTML', async () => {
    const { document, calls } = setup({
        sourceid: 1,
        preview: previewOf([row(10, 'Cálculo')]),
        apply: {
            status: 'ok',
            logid: 4,
            moved: 1,
            skipped: 0,
            emptied: [
                { id: 2, path: 'Facultades / A &amp; B' },
                { id: 1, path: '&lt;b&gt;Facultades&lt;/b&gt;' },
            ],
        },
    });
    pickTarget(document, 3);
    action(document, 'preview').click();
    await flush();
    action(document, 'apply').click();
    await flush();
    const region = document.querySelector('[data-region="emptied"]');
    assert.equal(region.hidden, false);
    const links = [...region.querySelectorAll('a')];
    assert.deepEqual(links.map((a) => a.getAttribute('href')), [
        'https://moodle.test/course/management.php?categoryid=2',
        'https://moodle.test/course/management.php?categoryid=1',
    ]);
    assert.deepEqual(links.map((a) => a.textContent), ['Facultades / A & B', '<b>Facultades</b>']);
    assert.equal(region.querySelector('b'), null);
    assert.equal(calls.reload, 1);
});

test('los nombres con <b> se escriben como texto en la tabla y en las listas', async () => {
    const model = createModel([
        { id: 1, parent: 0, name: '&lt;b&gt;Origen&lt;/b&gt;', sortorder: 1 },
        { id: 3, parent: 0, name: 'Semillas', sortorder: 2 },
    ]);
    const { document } = setup({
        model,
        sourceid: 1,
        preview: previewOf([row(10, '&lt;b&gt;Cálculo&lt;/b&gt;', { from: '&lt;i&gt;x&lt;/i&gt;' })]),
    });
    pickTarget(document, 3);
    action(document, 'preview').click();
    await flush();
    const dialog = document.querySelector('[role="dialog"]');
    assert.equal(dialog.querySelector('b'), null);
    assert.equal(dialog.querySelector('i'), null);
    assert.match(document.querySelector('tr[data-courseid="10"]').textContent, /<b>Cálculo<\/b>.*<i>x<\/i>/);
    assert.match(document.querySelector('[data-region="sources"]').textContent, /<b>Origen<\/b>/);
});

test('crear una categoría que ya existía avisa y la elige sin recargar el árbol', async () => {
    const { document, calls } = setup({ create: { id: 3, name: 'Semillas', created: false } });
    action(document, 'createseed').click();
    await flush();
    assert.deepEqual(calls.create, [{ name: 'Semillas' }]);
    assert.equal(calls.reload, 0);
    assert.equal(document.querySelector('[data-region="notice"]').textContent, 'mergeseedexists');
    assert.equal(document.querySelector('[data-target-id="3"]').getAttribute('aria-pressed'), 'true');
});

test('el historial ofrece Deshacer en una entrada merge', async () => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    globalThis.DOMParser = dom.window.DOMParser;
    const undone = [];
    openHistory({
        api: {
            history: async () => ({
                rows: [{ id: 8, action: 'merge', summary: 'Unificar en Semillas', userfullname: '', timecreated: 1, undone: false }],
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
    assert.deepEqual(undone, [8]);
});
