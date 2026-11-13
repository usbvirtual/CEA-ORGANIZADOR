import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { createModel } from 'tool_ceaorganizador/treemodel';
import { render, siteDuplicates, showDupSummary, dupDescription, MAX_REVEAL } from 'tool_ceaorganizador/treeview';

globalThis.DOMParser = new JSDOM('').window.DOMParser;

const row = (id, parent, name, sortorder, extra = {}) => ({
    id, parent, name, path: name, depth: 1, sortorder, visible: true, coursecount: 2, needsort: false,
    dupcount: 0, dupcounttotal: 0, ...extra,
});
const strings = {
    needsort: 'Te recomiendo auto ordenar',
    hidden: 'Oculta',
    loadmore: 'Cargar más',
    coursecount: '{$a} cursos',
    dupbase: 'Base',
    dupbasetitle: 'Curso base ({$a})',
    duprepeated: 'Repetido',
    duprepeatedtitle: 'Repetido de «{$a->base}» ({$a->reasons})',
    duppossible: '¿Repetido?',
    duppossibletitle: 'Posible repetido de «{$a->base}»: {$a->reasons}',
    dupcount: '{$a} repetidos',
    dupcountone: '1 repetido',
    dupcounttitle: 'Abre el camino hasta los repetidos',
    dupsummary: '{$a} cursos repetidos en el sitio',
    dupsummaryone: '1 curso repetido en el sitio',
    dedupereason_name: 'mismo nombre',
    dedupereason_key: 'mismo nombre sin copia, periodo ni grupo',
    dedupereason_shortname: 'mismo nombre corto',
    possiblereason_similar: 'nombres casi iguales',
};
const course = (id, fullname, extra = {}) => ({
    id, category: 1, fullname, shortname: `C${id}`, sortorder: id, visible: true, path: 'A',
    dup: '', dupreasons: [], dupbase: '', ...extra,
});
const setup = (rows, courses = []) => {
    const dom = new JSDOM('<!doctype html><div id="c"></div>');
    const container = dom.window.document.getElementById('c');
    const calls = [];
    const api = {
        getCourses: async (...args) => {
            calls.push(args);
            return { courses: args[0] === 1 ? courses : [], total: args[0] === 1 ? courses.length : 0, perpage: 100 };
        },
    };
    const store = new Map();
    const storage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v) };
    const state = { api, strings, storage };
    return { model: createModel(rows), container, calls, state, doc: dom.window.document };
};
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const badgeOf = (container, id) => container.querySelector(`[data-type="course"][data-id="${id}"] [data-region="dup"]`);

test('los cursos repetidos llevan Base, Repetido o ¿Repetido? con el motivo y el curso base', async () => {
    const { model, container, state, doc } = setup([row(1, 0, 'A', 1)], [
        course(10, 'Cálculo &amp; más', { dup: 'base', dupreasons: ['name', 'shortname'] }),
        course(11, 'Cálculo (copia)', { dup: 'repeated', dupreasons: ['key'], dupbase: 'Cálculo &amp; más' }),
        course(12, 'Física', { dup: 'possible', dupreasons: ['similar'], dupbase: '&lt;b&gt;Fisica&lt;/b&gt;' }),
        course(13, 'Química', { visible: false }),
    ]);
    render(container, model, state);
    container.querySelector('[data-id="1"] > .tool-ceaorganizador-fila').click();
    await flush();

    const base = badgeOf(container, 10);
    assert.equal(base.dataset.dup, 'base');
    assert.equal(base.firstChild.textContent, 'Base');
    assert.equal(base.title, 'Curso base (mismo nombre; mismo nombre corto)');
    assert.ok(base.classList.contains('badge-success') && base.classList.contains('bg-success'));

    const repeated = badgeOf(container, 11);
    assert.equal(repeated.firstChild.textContent, 'Repetido');
    assert.equal(repeated.title, 'Repetido de «Cálculo & más» (mismo nombre sin copia, periodo ni grupo)');
    assert.ok(repeated.classList.contains('badge-warning') && repeated.classList.contains('bg-warning'));
    const hidden = repeated.querySelector('.sr-only.visually-hidden');
    assert.equal(hidden.textContent, ` ${repeated.title}`);

    const possible = badgeOf(container, 12);
    assert.equal(possible.firstChild.textContent, '¿Repetido?');
    assert.equal(possible.title, 'Posible repetido de «<b>Fisica</b>»: nombres casi iguales');
    assert.equal(container.querySelector('b'), null);

    assert.equal(badgeOf(container, 13), null);
    // The badge describes the treeitem, after the «Oculta» label when there is one.
    const li = container.querySelector('[data-type="course"][data-id="11"]');
    assert.equal(doc.getElementById(li.getAttribute('aria-describedby')), repeated);
    assert.equal(container.querySelector('[data-type="course"][data-id="13"]').getAttribute('aria-describedby'),
        'tool-ceaorganizador-course-13-hidden');
});

test('dupDescription traduce los motivos y deja el nombre base como texto plano', () => {
    const text = dupDescription({ dup: 'repeated', dupreasons: ['name', 'otro'], dupbase: 'A &amp; B' }, strings);
    assert.equal(text, 'Repetido de «A & B» (mismo nombre; otro)');
});

test('la categoría muestra «N repetidos» con su subárbol, en singular con uno, y nada con cero', () => {
    const { model, container, state, doc } = setup([
        row(1, 0, 'A', 1, { dupcounttotal: 3 }),
        row(2, 0, 'B', 2, { dupcounttotal: 1, dupcount: 1 }),
        row(3, 0, 'C', 3),
    ]);
    render(container, model, state);
    const a = container.querySelector('[data-id="1"] [data-region="dupcount"]');
    assert.equal(a.textContent, '3 repetidos');
    assert.equal(a.tagName, 'BUTTON');
    assert.equal(a.title, 'Abre el camino hasta los repetidos');
    assert.ok(a.classList.contains('tool-ceaorganizador-insignia'));
    assert.ok(a.classList.contains('tool-ceaorganizador-insignia-repetidos'));
    assert.equal(container.querySelector('[data-id="2"] [data-region="dupcount"]').textContent, '1 repetido');
    assert.equal(container.querySelector('[data-id="3"] [data-region="dupcount"]'), null);
    const described = container.querySelector('[data-id="1"]').getAttribute('aria-describedby').split(' ');
    assert.equal(doc.getElementById(described[described.length - 1]), a);
});

test('clic en «N repetidos» abre la categoría y las de abajo con repetidos, sin cerrarla al repetir', async () => {
    const { model, container, state, calls } = setup([
        row(1, 0, 'Facultad', 1, { dupcounttotal: 2 }),
        row(2, 1, 'Programa', 1, { dupcounttotal: 2 }),
        row(3, 2, 'Semestre I', 1, { dupcounttotal: 2, dupcount: 2 }),
        row(4, 2, 'Semestre II', 2),
        row(5, 1, 'Otro', 2),
    ]);
    render(container, model, state);
    const badge = () => container.querySelector('[data-id="1"] > .tool-ceaorganizador-fila [data-region="dupcount"]');
    badge().click();
    await flush();
    const open = (id) => container.querySelector(`[data-type="category"][data-id="${id}"]`).getAttribute('aria-expanded');
    assert.equal(open(1), 'true');
    assert.equal(open(2), 'true');
    assert.equal(open(3), 'true');
    assert.equal(open(4), 'false');
    assert.equal(open(5), 'false');
    assert.deepEqual(new Set(calls.map((call) => call[0])), new Set([1, 2, 3]));
    assert.deepEqual(new Set(state.expanded), new Set([1, 2, 3]));

    badge().click();
    await flush();
    assert.equal(open(1), 'true');
});

test('el clic abre como máximo MAX_REVEAL categorías', async () => {
    const rows = [row(1, 0, 'Raíz', 1, { dupcounttotal: 40 })];
    for (let i = 0; i < 40; i++) {
        rows.push(row(100 + i, 1, `Hija ${i}`, i + 1, { dupcounttotal: 1, dupcount: 1 }));
    }
    const { model, container, state } = setup(rows);
    render(container, model, state);
    container.querySelector('[data-id="1"] [data-region="dupcount"]').click();
    await flush();
    assert.equal(state.expanded.size, MAX_REVEAL);
    assert.equal(container.querySelectorAll('[data-type="category"][aria-expanded="true"]').length, MAX_REVEAL);
});

test('la barra suma los repetidos del sitio y Revisar abre las categorías superiores con repetidos', async () => {
    const rows = [
        row(1, 0, 'A', 1, { dupcounttotal: 2 }),
        row(2, 1, 'A1', 1, { dupcounttotal: 2, dupcount: 2 }),
        row(3, 0, 'B', 2),
        row(4, 0, 'C', 3, { dupcounttotal: 1, dupcount: 1 }),
    ];
    assert.equal(siteDuplicates(rows), 3);

    const { model, container, state, doc } = setup(rows);
    const region = doc.createElement('div');
    region.hidden = true;
    region.innerHTML = '<span data-region="dupsummarytext"></span>';
    showDupSummary(region, siteDuplicates(rows), strings);
    assert.equal(region.hidden, false);
    assert.equal(region.textContent, '3 cursos repetidos en el sitio');
    showDupSummary(region, 1, strings);
    assert.equal(region.textContent, '1 curso repetido en el sitio');
    showDupSummary(region, 0, strings);
    assert.equal(region.hidden, true);

    const view = render(container, model, state);
    assert.equal(view.revealDuplicates(), 2);
    await flush();
    const open = (id) => container.querySelector(`[data-type="category"][data-id="${id}"]`).getAttribute('aria-expanded');
    assert.equal(open(1), 'true');
    assert.equal(open(3), 'false');
    assert.equal(open(4), 'true');
    assert.equal(doc.activeElement, container.querySelector('[data-id="1"]'));
});
