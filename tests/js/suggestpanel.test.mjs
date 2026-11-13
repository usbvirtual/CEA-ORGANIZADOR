import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { open } from 'tool_ceaorganizador/suggestpanel';
import { decodeEntities } from 'tool_ceaorganizador/text';

const strings = new Proxy({}, {
    get: (_, name) => ({
        suggestapply: 'Aplicar ({$a})',
        suggesttruncated: 'Hay {$a} en total',
        renamedone: '{$a} renombrados',
    })[name] || String(name),
});
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const rowOf = (type, id, old, nuevo, group = 'Facultad', reasons = ['tilde']) => ({ type, id, old, new: nuevo, group, reasons });

const setup = (result, overrides = {}) => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    globalThis.DOMParser = dom.window.DOMParser;
    const calls = { preview: [], apply: [], toasts: [], undo: [], reload: 0 };
    const api = {
        suggestPreview: async (args) => {
            calls.preview.push(args);
            return typeof result === 'function' ? result(args) : result;
        },
        renameApply: async (params) => {
            calls.apply.push(params);
            return { results: params.changes.map((c) => ({ type: c.type, id: c.id, status: 'ok', message: '' })), logid: 50 + calls.apply.length };
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
            return { ok: true, conflicts: [] };
        },
        reload: async () => {
            calls.reload += 1;
        },
        decode: decodeEntities,
        categoryid: overrides.categoryid === undefined ? 0 : overrides.categoryid,
        trigger: document.getElementById('t'),
        doc: document,
    });
    return { document, calls, panel };
};
const action = (document, name) => document.querySelector(`[data-action="${name}"]`);
const rowNode = (document, key) => document.querySelector(`[data-key="${key}"]`);

test('carga con el alcance y con cursos incluidos, y recarga al cambiar la casilla', async () => {
    const { document, calls } = setup({ rows: [], total: 0, truncated: false }, { categoryid: 7 });
    await flush();
    assert.deepEqual(calls.preview[0], { categoryid: 7, includecourses: true });
    assert.match(document.querySelector('[data-region="notice"]').textContent, /suggestnone/);
    const box = document.querySelector('[data-field="includecourses"]');
    box.checked = false;
    box.dispatchEvent(new document.defaultView.Event('change'));
    await flush();
    assert.deepEqual(calls.preview[1], { categoryid: 7, includecourses: false });
});

test('las filas desmarcadas no se envían y el botón cuenta solo las que cambian', async () => {
    const { document, calls } = setup({
        rows: [
            rowOf('category', 1, 'Ingenieria', 'Ingeniería'),
            rowOf('course', 2, 'Fisica', 'Física'),
            rowOf('course', 3, 'Algebra', 'Álgebra'),
        ],
        total: 3,
        truncated: false,
    });
    await flush();
    assert.equal(action(document, 'apply').textContent, 'Aplicar (3)');
    rowNode(document, 'course:2').querySelector('[data-field="selected"]').click();
    rowNode(document, 'course:3').querySelector('[data-field="new"]').value = '  ';
    rowNode(document, 'course:3').querySelector('[data-field="new"]').dispatchEvent(new document.defaultView.Event('input'));
    assert.equal(action(document, 'apply').textContent, 'Aplicar (1)');
    action(document, 'apply').click();
    await flush();
    assert.deepEqual(calls.apply[0].changes, [{ type: 'category', id: 1, old: 'Ingenieria', new: 'Ingeniería' }]);
    assert.equal(calls.reload, 1);
    assert.equal(calls.toasts.length, 1);
    // The panel loads again after the apply.
    assert.equal(calls.preview.length, 2);
    calls.toasts[0].options.onAction();
    await flush();
    assert.deepEqual(calls.undo, [51]);
});

test('el nombre editado a mano es el que se envía y nunca viaja un nombre corto', async () => {
    const { document, calls } = setup({ rows: [rowOf('course', 2, 'Fisica', 'Física')], total: 1, truncated: false });
    await flush();
    const input = rowNode(document, 'course:2').querySelector('[data-field="new"]');
    input.value = 'Física General';
    input.dispatchEvent(new document.defaultView.Event('input'));
    action(document, 'apply').click();
    await flush();
    assert.deepEqual(calls.apply[0].changes, [{ type: 'course', id: 2, old: 'Fisica', new: 'Física General' }]);
});

test('marcar o desmarcar todo alterna todas las casillas', async () => {
    const { document } = setup({
        rows: [rowOf('course', 2, 'a', 'b'), rowOf('course', 3, 'c', 'd')],
        total: 2,
        truncated: false,
    });
    await flush();
    action(document, 'toggleall').click();
    assert.deepEqual([...document.querySelectorAll('[data-field="selected"]')].map((c) => c.checked), [false, false]);
    assert.equal(action(document, 'apply').disabled, true);
    action(document, 'toggleall').click();
    assert.deepEqual([...document.querySelectorAll('[data-field="selected"]')].map((c) => c.checked), [true, true]);
});

test('los nombres con <b> se escriben como texto y el grupo se decodifica', async () => {
    const { document } = setup({
        rows: [rowOf('course', 2, '<b>Fisica</b>', '<b>Física</b>', 'A &amp; B / &lt;i&gt;C&lt;/i&gt;')],
        total: 1,
        truncated: false,
    });
    await flush();
    const rows = document.querySelector('[data-region="rows"]');
    assert.equal(rows.querySelector('b'), null);
    assert.equal(rows.querySelector('i'), null);
    assert.match(rows.querySelector('h3').textContent, /^A & B \/ <i>C<\/i>$/);
    assert.match(rows.textContent, /<b>Fisica<\/b>/);
    assert.equal(rows.querySelector('[data-field="new"]').value, '<b>Física</b>');
    // A name with tags would lose them in PARAM_TEXT: it is not counted.
    assert.equal(action(document, 'apply').disabled, true);
});

test('las razones se muestran como etiquetas y las filas se agrupan', async () => {
    const { document } = setup({
        rows: [
            rowOf('category', 1, 'a', 'b', 'G1', ['formato', 'tilde']),
            rowOf('course', 2, 'c', 'd', 'G1', ['glosario']),
            rowOf('course', 3, 'e', 'f', 'G2', ['variante']),
        ],
        total: 3,
        truncated: false,
    });
    await flush();
    assert.deepEqual([...document.querySelectorAll('h3')].map((h) => h.textContent), ['G1', 'G2']);
    assert.deepEqual(
        [...rowNode(document, 'category:1').querySelectorAll('[data-reason]')].map((c) => c.textContent),
        ['reason_formato', 'reason_tilde']
    );
});

test('el aviso de truncado aparece solo cuando el servidor lo indica', async () => {
    const one = setup({ rows: [rowOf('course', 2, 'a', 'b')], total: 2500, truncated: true });
    await flush();
    const notice = one.document.querySelector('[data-region="notice"]');
    assert.equal(notice.hidden, false);
    assert.equal(notice.textContent, 'Hay 2500 en total');
    const two = setup({ rows: [rowOf('course', 2, 'a', 'b')], total: 1, truncated: false });
    await flush();
    assert.equal(two.document.querySelector('[data-region="notice"]').hidden, true);
});

test('más de 100 cambios se envían en lotes de 100, uno tras otro', async () => {
    const rows = Array.from({ length: 201 }, (_, i) => rowOf('course', i + 1, `n${i}`, `m${i}`));
    const { document, calls } = setup({ rows, total: 201, truncated: false });
    await flush();
    action(document, 'apply').click();
    await flush();
    assert.deepEqual(calls.apply.map((c) => c.changes.length), [100, 100, 1]);
    calls.toasts[0].options.onAction();
    await flush();
    assert.deepEqual(calls.undo, [53, 52, 51]);
});

test('con el api real, el panel llega a los servicios con los parámetros exactos y recarga tras deshacer', async () => {
    const real = await import('tool_ceaorganizador/api');
    const sent = [];
    real.setTransport(async (methodname, args) => {
        sent.push({ methodname, args });
        if (methodname === 'tool_ceaorganizador_suggest_preview') {
            return { rows: [rowOf('course', 2, 'Fisica', 'Física')], total: 1, truncated: false };
        }
        return { results: [{ type: 'course', id: 2, status: 'ok', message: '' }], logid: 9 };
    });
    try {
        const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
        const { document } = dom.window;
        globalThis.DOMParser = dom.window.DOMParser;
        const toasts = [];
        open({
            api: real,
            strings,
            toast: { show: (message, options) => toasts.push(options) },
            onError: (error) => {
                throw error;
            },
            undo: async () => ({ ok: true }),
            reload: async () => {},
            decode: decodeEntities,
            categoryid: 7,
            trigger: document.getElementById('t'),
            doc: document,
        });
        await flush();
        assert.deepEqual(sent[0], {
            methodname: 'tool_ceaorganizador_suggest_preview',
            args: { categoryid: 7, includecourses: true },
        });
        const box = document.querySelector('[data-field="includecourses"]');
        box.checked = false;
        box.dispatchEvent(new document.defaultView.Event('change'));
        await flush();
        assert.deepEqual(sent[1].args, { categoryid: 7, includecourses: false });
        action(document, 'apply').click();
        await flush();
        const apply = sent.find((call) => call.methodname === 'tool_ceaorganizador_rename_apply');
        assert.deepEqual(apply.args, { changes: [{ type: 'course', id: 2, old: 'Fisica', new: 'Física' }] });
        // After the apply the panel loaded again; an undo loads it once more while it is open.
        const loads = () => sent.filter((call) => call.methodname === 'tool_ceaorganizador_suggest_preview').length;
        assert.equal(loads(), 3);
        toasts[0].onAction();
        await flush();
        assert.equal(loads(), 4);
    } finally {
        real.setTransport();
    }
});
