import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { open } from 'tool_ceaorganizador/clonepanel';
import { parsePeriod, suggestLabel, isValidLabel, periodOptions, dateToTimestamp } from 'tool_ceaorganizador/period';
import { decodeEntities } from 'tool_ceaorganizador/text';

globalThis.DOMParser = new JSDOM('').window.DOMParser;

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const strings = new Proxy({}, {
    get: (_, name) => ({ cloneapply: 'Clonar cursos ({$a})', clonetotal: '{$a} cursos' }[name] || String(name)),
});

test('parsePeriod reconoce 2027-01, «2026 -1» y «2026 II» y descarta «Facultades»', () => {
    assert.deepEqual(parsePeriod('2027-01'), [2027, 1]);
    assert.deepEqual(parsePeriod('2026 -1'), [2026, 1]);
    assert.deepEqual(parsePeriod('2026 II'), [2026, 2]);
    assert.deepEqual(parsePeriod('Semestre 2025_02 Pregrado'), [2025, 2]);
    assert.equal(parsePeriod('Facultades'), null);
    assert.equal(parsePeriod('2026-3'), null);
    assert.equal(parsePeriod('12026-1'), null);
    assert.equal(parsePeriod('2026-1b'), null);
});

test('suggestLabel y isValidLabel usan AAAA-PP', () => {
    assert.equal(suggestLabel('Periodo 2026 II'), '2026-02');
    assert.equal(suggestLabel('Facultades'), '');
    assert.equal(isValidLabel('2027-01'), true);
    assert.equal(isValidLabel('2027-02'), true);
    assert.equal(isValidLabel('2027-1'), false);
    assert.equal(isValidLabel('27-01'), false);
});

test('isValidLabel acepta solo lo que acepta el servidor: 20AA-01 o 20AA-02', () => {
    assert.equal(isValidLabel('2027-03'), false);
    assert.equal(isValidLabel('2027-00'), false);
    assert.equal(isValidLabel('2027-12'), false);
    assert.equal(isValidLabel('1999-01'), false);
    assert.equal(isValidLabel('3027-01'), false);
    assert.equal(isValidLabel(' 2027-01'), false);
});

test('periodOptions lista cualquier nivel, de la más reciente a la más antigua, con la ruta decodificada', () => {
    const rows = [
        { id: 1, name: 'Facultades', path: 'Facultades' },
        { id: 2, name: '2026-01', path: 'Pregrado / 2026-01' },
        { id: 3, name: '2027-01', path: 'Pregrado / A &amp; B / 2027-01' },
        { id: 4, name: '2026-02', path: '2026-02' },
    ];
    const list = periodOptions(rows, decodeEntities);
    assert.deepEqual(list.map((o) => o.id), [3, 4, 2]);
    assert.equal(list[0].text, 'Pregrado / A & B / 2027-01');
    assert.equal(list[0].label, '2027-01');
});

test('dateToTimestamp da segundos a medianoche local', () => {
    assert.equal(dateToTimestamp('2027-01-25'), new Date(2027, 0, 25).getTime() / 1000);
    assert.equal(dateToTimestamp(''), null);
});

const model = {
    snapshot: () => [
        { id: 1, name: 'Posgrado', path: 'Posgrado' },
        { id: 20, name: '2026-02', path: 'Posgrado / 2026-02' },
        { id: 21, name: '2027-01', path: 'Posgrado / 2027-01' },
    ],
};

const row = (courseid, extra = {}) => ({
    courseid,
    fullname: `Curso ${courseid}`,
    shortname: `C${courseid}`,
    destpath: 'Posgrado / 2027-01',
    destcategoryid: 21,
    newcategories: [],
    newfullname: `Curso ${courseid} 2027-01`,
    newshortname: `C${courseid}-2027-01`,
    error: null,
    ...extra,
});

const setup = (previews, applies, overrides = {}) => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    const calls = { preview: [], apply: [], toasts: [], progress: [] };
    let p = 0;
    let a = 0;
    const api = {
        clonePreview: async (params) => {
            calls.preview.push(params);
            return previews[Math.min(p++, previews.length - 1)];
        },
        cloneApply: async (params) => {
            calls.apply.push(params);
            return applies[Math.min(a++, applies.length - 1)];
        },
    };
    const panel = open({
        api,
        strings,
        toast: { show: (message) => calls.toasts.push(message) },
        onError: (error) => {
            throw error;
        },
        model,
        decode: decodeEntities,
        items: overrides.items || [{ type: 'category', id: 5 }],
        openProgress: (batchid) => calls.progress.push(batchid),
        trigger: document.getElementById('t'),
        doc: document,
    });
    return { document, calls, panel };
};
const field = (document, name) => document.querySelector(`[data-field="${name}"]`);
const click = (document, action) => document.querySelector(`[data-action="${action}"]`).click();
const fill = (document, start = '2027-01-25', end = '2027-05-30') => {
    field(document, 'start').value = start;
    field(document, 'end').value = end;
};
const typeIn = (document, input, value) => {
    input.value = value;
    input.dispatchEvent(new document.defaultView.Event('input'));
};
const ok = { status: 'ok', batchid: 9, queued: 1, skipped: 0 };

test('el selector arranca en el periodo más reciente con la etiqueta sugerida y las fechas se validan', async () => {
    const { document, calls } = setup([{ rows: [row(1)], total: 1, hash: 'h1' }], [ok]);
    assert.equal(field(document, 'period').value, '21');
    assert.equal(field(document, 'label').value, '2027-01');
    click(document, 'preview');
    await flush();
    assert.equal(calls.preview.length, 0);
    assert.match(document.querySelector('[data-region="notice"]').textContent, /cloneerror_dates/);

    fill(document, '2027-05-30', '2027-01-25');
    click(document, 'preview');
    await flush();
    assert.equal(calls.preview.length, 0);

    fill(document);
    typeIn(document, field(document, 'label'), '2027-1');
    click(document, 'preview');
    await flush();
    assert.equal(calls.preview.length, 0);
    assert.match(document.querySelector('[data-region="notice"]').textContent, /cloneerror_label/);

    typeIn(document, field(document, 'label'), '2027-01');
    click(document, 'preview');
    await flush();
    assert.deepEqual(calls.preview[0], {
        items: [{ type: 'category', id: 5 }],
        periodid: 21,
        periodlabel: '2027-01',
        startdate: new Date(2027, 0, 25).getTime() / 1000,
        enddate: new Date(2027, 4, 30).getTime() / 1000,
    });
});

test('las filas con error quedan deshabilitadas, no viajan y el botón cuenta solo las demás', async () => {
    const { document, calls } = setup([{
        rows: [row(1), row(2, { error: 'shortnametaken' }), row(3, { newcategories: ['Posgrado / 2027-01'] })],
        total: 3,
        hash: 'h1',
    }], [ok]);
    fill(document);
    click(document, 'preview');
    await flush();
    const bad = document.querySelector('[data-courseid="2"]');
    assert.equal(bad.querySelector('input').disabled, true);
    assert.match(bad.textContent, /cloneerror_shortnametaken/);
    assert.match(document.querySelector('[data-courseid="3"]').textContent, /clonenew/);
    assert.equal(document.querySelector('[data-region="total"]').textContent, '3 cursos');
    assert.equal(document.querySelector('[data-action="apply"]').textContent, 'Clonar cursos (2)');

    click(document, 'apply');
    await flush();
    assert.deepEqual(calls.apply[0].rows.map((r) => r.courseid), [1, 3]);
    assert.equal(calls.apply[0].hash, 'h1');
    assert.equal(calls.apply[0].periodid, 21);
    assert.deepEqual(calls.progress, [9]);
    assert.equal(document.querySelector('[role="dialog"]'), null);
});

test('con 0 filas elegibles el botón queda deshabilitado', async () => {
    const { document } = setup([{ rows: [row(1, { error: 'alreadycloned' })], total: 1, hash: 'h' }], [ok]);
    fill(document);
    click(document, 'preview');
    await flush();
    const apply = document.querySelector('[data-action="apply"]');
    assert.equal(apply.disabled, true);
    assert.equal(apply.textContent, 'Clonar cursos (0)');
});

test('las ediciones viajan crudas en rows y un nombre vacío deja la fila fuera', async () => {
    const { document, calls } = setup([{ rows: [row(1), row(2)], total: 2, hash: 'h1' }], [ok]);
    fill(document);
    click(document, 'preview');
    await flush();
    typeIn(document, document.querySelector('[data-courseid="1"] [data-field="newfullname"]'), 'A &amp; <i>B</i>');
    typeIn(document, document.querySelector('[data-courseid="1"] [data-field="newshortname"]'), 'AB-1');
    typeIn(document, document.querySelector('[data-courseid="2"] [data-field="newshortname"]'), '  ');
    assert.equal(document.querySelector('[data-action="apply"]').textContent, 'Clonar cursos (1)');
    click(document, 'apply');
    await flush();
    assert.deepEqual(calls.apply[0].rows, [{ courseid: 1, newfullname: 'A &amp; <i>B</i>', newshortname: 'AB-1' }]);
});

test('stale muestra el aviso y recarga la vista previa sin abrir el progreso', async () => {
    const { document, calls } = setup(
        [{ rows: [row(1)], total: 1, hash: 'h1' }, { rows: [row(1), row(2)], total: 2, hash: 'h2' }],
        [{ status: 'stale', batchid: 0, queued: 0, skipped: 0 }, ok]
    );
    fill(document);
    click(document, 'preview');
    await flush();
    click(document, 'apply');
    await flush();
    assert.equal(calls.preview.length, 2);
    assert.deepEqual(calls.preview[1], calls.preview[0]);
    assert.equal(document.querySelector('[data-region="notice"]').textContent, 'clonestale');
    assert.equal(document.querySelectorAll('[data-courseid]').length, 2);
    assert.deepEqual(calls.progress, []);
    click(document, 'apply');
    await flush();
    assert.equal(calls.apply[1].hash, 'h2');
    assert.deepEqual(calls.progress, [9]);
});

test('un doble clic en aplicar solo aplica una vez', async () => {
    const { document, calls } = setup([{ rows: [row(1)], total: 1, hash: 'h1' }], [ok]);
    fill(document);
    click(document, 'preview');
    await flush();
    const apply = document.querySelector('[data-action="apply"]');
    apply.click();
    apply.click();
    await flush();
    assert.equal(calls.apply.length, 1);
});

test('con batchid 0 no se abre el progreso y se avisa que no quedó nada', async () => {
    const { document, calls } = setup([{ rows: [row(1)], total: 1, hash: 'h1' }], [
        { status: 'ok', batchid: 0, queued: 0, skipped: 1 },
    ]);
    fill(document);
    click(document, 'preview');
    await flush();
    click(document, 'apply');
    await flush();
    assert.deepEqual(calls.progress, []);
    assert.deepEqual(calls.toasts, ['clonenothing']);
});

test('cambiar el formulario tras la vista previa bloquea aplicar hasta una nueva', async () => {
    const { document } = setup([{ rows: [row(1)], total: 1, hash: 'h1' }], [ok]);
    fill(document);
    click(document, 'preview');
    await flush();
    assert.equal(document.querySelector('[data-action="apply"]').disabled, false);
    typeIn(document, field(document, 'end'), '2027-06-30');
    assert.equal(document.querySelector('[data-action="apply"]').disabled, true);
});

test('los nombres formateados se decodifican y un <b> crudo se muestra como texto, sin elementos', async () => {
    const { document } = setup([{
        rows: [row(1, {
            fullname: 'A &amp; B',
            destpath: 'Posgrado / Nueva &amp; Co',
            newfullname: '<b>Crudo</b> &amp; Co',
            newshortname: '<i>x</i>',
        })],
        total: 1,
        hash: 'h',
    }], [ok]);
    fill(document);
    click(document, 'preview');
    await flush();
    const tr = document.querySelector('[data-courseid="1"]');
    assert.equal(tr.querySelector('b'), null);
    assert.equal(tr.querySelector('i'), null);
    assert.match(tr.textContent, /A & B/);
    assert.match(tr.textContent, /Nueva & Co/);
    assert.equal(tr.querySelector('[data-field="newfullname"]').value, '<b>Crudo</b> &amp; Co');
});

test('el botón cuenta exactamente las filas que se envían, con sus valores actuales', async () => {
    const { document, calls } = setup([{
        rows: [row(1), row(2), row(3, { error: 'alreadycloned' }), row(4)],
        total: 4,
        hash: 'h1',
    }], [ok]);
    fill(document);
    click(document, 'preview');
    await flush();
    typeIn(document, document.querySelector('[data-courseid="2"] [data-field="newfullname"]'), '');
    typeIn(document, document.querySelector('[data-courseid="4"] [data-field="newshortname"]'), 'C4-NUEVO');
    assert.equal(document.querySelector('[data-action="apply"]').textContent, 'Clonar cursos (2)');
    click(document, 'apply');
    await flush();
    assert.deepEqual(calls.apply[0].rows, [
        { courseid: 1, newfullname: 'Curso 1 2027-01', newshortname: 'C1-2027-01' },
        { courseid: 4, newfullname: 'Curso 4 2027-01', newshortname: 'C4-NUEVO' },
    ]);
});

test('los campos de nombre tienen etiquetas accesibles propias', async () => {
    const { document } = setup([{ rows: [row(1)], total: 1, hash: 'h1' }], [ok]);
    fill(document);
    click(document, 'preview');
    await flush();
    const tr = document.querySelector('[data-courseid="1"]');
    assert.equal(tr.querySelector('[data-field="newfullname"]').getAttribute('aria-label'), 'clonenewfullname: Curso 1');
    assert.equal(tr.querySelector('[data-field="newshortname"]').getAttribute('aria-label'), 'clonenewshortname: Curso 1');
});

test('las categorías nuevas del tooltip también están en texto oculto para lectores de pantalla', async () => {
    const { document } = setup([{
        rows: [row(1, { newcategories: ['Posgrado / 2027-01 / A &amp; B', 'Posgrado / 2027-01 / A &amp; B / C'] })],
        total: 1,
        hash: 'h1',
    }], [ok]);
    fill(document);
    click(document, 'preview');
    await flush();
    const tr = document.querySelector('[data-courseid="1"]');
    const tag = tr.querySelector('.tool-ceaorganizador-etiqueta');
    assert.equal(tag.title, ['Posgrado / 2027-01 / A & B', 'Posgrado / 2027-01 / A & B / C'].join(String.fromCharCode(10)));
    const hidden = tr.querySelector('[data-region="newcategories"]');
    assert.equal(hidden.className, 'sr-only visually-hidden');
    assert.equal(hidden.textContent, ' Posgrado / 2027-01 / A & B; Posgrado / 2027-01 / A & B / C');
});
