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
        mergeskip: 'Dejar en el origen',
        mergerepeatedto: 'A Repetidos: {$a}',
        mergedonerepeated: 'A Repetidos: {$a}',
        error_groupwithoutwinner: 'Sin ganador: {$a}',
        partialdone: 'Interrumpido: {$a}',
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
    group: '',
    existing: false,
    winner: false,
    reasons: [],
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

test('la vista previa muestra Nueva y los grupos de repetidos con su elección; N cuenta lo que se escribe', async () => {
    const { document, calls } = setup({
        sourceid: 1,
        preview: {
            ...previewOf([
                row(10, 'Cálculo', { newcategories: ['Semillas / Ingeniería'] }),
                row(11, 'Física', { duplicate: true, action: 'repeated', group: '11-13', reasons: ['key'] }),
                row(13, 'Física 2026-1', {
                    duplicate: true, action: 'move', group: '11-13', existing: true, winner: true, reasons: ['key'],
                    from: 'Semillas / &lt;b&gt;Ingeniería&lt;/b&gt;',
                }),
                row(12, 'Álgebra'),
            ]),
            folder: { id: 0, path: 'Semillas / Repetidos' },
        },
        apply: { status: 'ok', logid: 7, moved: 2, repeated: 1, skipped: 1, emptied: [] },
    });
    pickTarget(document, 3);
    action(document, 'preview').click();
    await flush();
    assert.deepEqual(calls.preview, [{ targetid: 3, sourceids: [1] }]);
    const first = document.querySelector('tr[data-courseid="10"]');
    assert.match(first.querySelector('.tool-ceaorganizador-etiqueta').textContent, /clonenew/);
    assert.match(first.querySelector('[data-region="newcategories"]').textContent, /Semillas \/ Ingeniería/);
    assert.equal(document.querySelector('tr[data-courseid="12"] .tool-ceaorganizador-etiqueta'), null);
    assert.equal(document.querySelector('[data-region="folder"]').textContent, 'A Repetidos: Semillas / Repetidos');
    assert.equal(document.querySelector('[data-region="folder"]').hidden, false);
    // The target course that stays does not count; the repeated source course does.
    assert.equal(action(document, 'apply').textContent, 'Unificar (3)');
    const existing = document.querySelector('tr[data-courseid="13"]');
    assert.equal(existing.dataset.group, '11-13');
    assert.match(existing.textContent, /mergeexisting/);
    assert.match(existing.textContent, /mergewinner/);
    assert.match(existing.textContent, /Semillas \/ <b>Ingeniería<\/b>/);
    assert.equal(existing.querySelector('b'), null);
    // A target course can stay or go to Repetidos, never «stay in the source».
    const existingChoice = existing.querySelector('[data-action="choice"]');
    assert.deepEqual([...existingChoice.options].map((o) => o.value), ['move', 'repeated']);
    const choice = document.querySelector('tr[data-courseid="11"] [data-action="choice"]');
    assert.deepEqual([...choice.options].map((o) => o.value), ['move', 'repeated', 'skip']);
    assert.equal(choice.value, 'repeated');
    assert.equal(document.querySelector('tr[data-courseid="10"] [data-action="choice"]'), null);
    const pick = (select, value) => {
        select.value = value;
        select.dispatchEvent(new document.defaultView.Event('change'));
    };
    pick(choice, 'skip');
    assert.equal(action(document, 'apply').textContent, 'Unificar (2)');
    // Nobody of the group would stay in the target: the apply is blocked and the winner is named, as text.
    pick(existingChoice, 'repeated');
    assert.equal(action(document, 'apply').disabled, true);
    const groupNotice = document.querySelector('[data-region="groupnotice"]');
    assert.equal(groupNotice.hidden, false);
    assert.equal(groupNotice.textContent, 'Sin ganador: Física 2026-1');
    pick(choice, 'move');
    assert.equal(groupNotice.hidden, true);
    assert.equal(action(document, 'apply').disabled, false);
    assert.equal(action(document, 'apply').textContent, 'Unificar (4)');
    action(document, 'apply').click();
    await flush();
    assert.deepEqual(calls.apply[0], {
        targetid: 3,
        sourceids: [1],
        rows: [
            { courseid: 10, action: 'move' },
            { courseid: 11, action: 'move' },
            { courseid: 13, action: 'repeated' },
            { courseid: 12, action: 'move' },
        ],
        hash: 'abc123',
        confirmed: [],
    });
    assert.equal(calls.reload, 1);
    assert.equal(calls.toasts[0].message, '2 unificados · A Repetidos: 1');
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
                confirmed: [],
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

test('una vista previa en vuelo se descarta si la selección cambia antes de que responda', async () => {
    let release;
    const { document } = setup({
        sourceid: 1,
        preview: () => new Promise((resolve) => {
            release = () => resolve(previewOf([row(10, 'Cálculo')]));
        }),
    });
    pickTarget(document, 3);
    action(document, 'preview').click();
    await flush();
    check(document, 5, true);
    release();
    await flush();
    assert.equal(document.querySelector('[data-region="preview"]').hidden, true);
    assert.equal(document.querySelectorAll('tbody tr').length, 0);
    assert.equal(action(document, 'apply').disabled, true);
});

test('un ganador oculto y una carpeta Repetidos visible se avisan; un resultado parcial ofrece Deshacer', async () => {
    const { document, calls } = setup({
        sourceid: 1,
        preview: {
            ...previewOf([
                row(11, 'Física', { duplicate: true, action: 'move', group: '11-13', winner: true, visible: false }),
                row(13, 'Física', { duplicate: true, action: 'repeated', group: '11-13', existing: true, visible: true }),
            ]),
            folder: { id: 8, path: 'Semillas / Repetidos', visible: true },
        },
        apply: { status: 'partial', logid: 21, moved: 1, repeated: 0, skipped: 0, emptied: [] },
    });
    pickTarget(document, 3);
    action(document, 'preview').click();
    await flush();
    assert.notEqual(document.querySelector('tr[data-courseid="11"] [data-region="winnerhidden"]'), null);
    assert.equal(document.querySelector('tr[data-courseid="13"] [data-region="winnerhidden"]'), null);
    assert.equal(document.querySelector('[data-region="foldervisible"]').hidden, false);
    action(document, 'apply').click();
    await flush();
    assert.equal(calls.toasts[0].message, 'Interrumpido: 1');
    calls.toasts[0].options.onAction();
    await flush();
    assert.deepEqual(calls.undo, [21]);
    assert.equal(calls.reload, 1);
});

test('un posible repetido del destino pregunta Sí o No; con Sí entra al grupo y se envía como confirmado', async () => {
    const { document, calls } = setup({
        sourceid: 1,
        preview: {
            ...previewOf([
                row(11, 'Física general', { possible: '11-13', activities: 4 }),
                row(13, 'Física', { possible: '11-13', existing: true }),
            ]),
            folder: { id: 0, path: 'Semillas / Repetidos', visible: false },
            possible: [{
                key: '11-13',
                path: 'Semillas / Ingeniería',
                reason: 'extra',
                courses: [
                    { courseid: 11, fullname: 'Física general', existing: false, visible: true, activities: 4, keep: true },
                    { courseid: 13, fullname: 'Física', existing: true, visible: true, activities: 0, keep: false },
                ],
            }],
        },
        apply: { status: 'ok', logid: 7, moved: 2, repeated: 1, skipped: 0, emptied: [] },
    });
    pickTarget(document, 3);
    action(document, 'preview').click();
    await flush();
    const box = document.querySelector('[data-region="possible"]');
    assert.equal(box.hidden, false);
    assert.match(box.textContent, /possiblereason_extra/);
    // By default nothing of it changes: the target course stays.
    assert.equal(action(document, 'apply').textContent, 'Unificar (1)');
    assert.equal(document.querySelector('tr[data-courseid="13"] [data-action="choice"]'), null);
    box.querySelector('[data-action="possibleyes"]').click();
    assert.equal(action(document, 'apply').textContent, 'Unificar (2)');
    assert.equal(document.querySelector('tr[data-courseid="13"] [data-action="choice"]').value, 'repeated');
    action(document, 'apply').click();
    await flush();
    assert.deepEqual(calls.apply[0].rows, [{ courseid: 11, action: 'move' }, { courseid: 13, action: 'repeated' }]);
    assert.deepEqual(calls.apply[0].confirmed, ['11-13']);
});

test('con destino y orígenes dados (desde la selección) la vista previa se pide sola', async () => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    globalThis.DOMParser = dom.window.DOMParser;
    const asked = [];
    const panel = open({
        api: { mergePreview: async (params) => {
            asked.push(params);
            return previewOf([row(10, 'Cálculo')]);
        } },
        strings,
        toast: { show: () => null },
        onError: (error) => {
            throw error;
        },
        undo: async () => null,
        reload: async () => null,
        getModel: () => tree(),
        wwwroot: 'https://moodle.test',
        decode: decodeEntities,
        targetid: 3,
        sourceids: [1, 5],
        trigger: document.getElementById('t'),
        doc: document,
    });
    await panel.ready;
    await flush();
    assert.deepEqual(asked, [{ targetid: 3, sourceids: [1, 5] }]);
    assert.equal(document.querySelector('[data-source-id="1"]').checked, true);
});
