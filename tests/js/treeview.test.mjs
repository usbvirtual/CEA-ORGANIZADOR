import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { createModel } from 'tool_ceaorganizador/treemodel';
import { render } from 'tool_ceaorganizador/treeview';

const row = (id, parent, name, sortorder, extra = {}) => ({
    id, parent, name, path: name, depth: 1, sortorder, visible: true, coursecount: 2, needsort: false, ...extra,
});
const strings = {
    needsort: 'Te recomiendo auto ordenar',
    hidden: 'Oculta',
    loadmore: 'Cargar más',
    coursecount: '{$a} cursos',
};
const setup = (rows) => {
    const dom = new JSDOM('<!doctype html><div id="c"></div>');
    const container = dom.window.document.getElementById('c');
    const calls = [];
    const api = {
        getCourses: async (...args) => {
            calls.push(args);
            return {
                courses: [{
                    id: 100, category: 1, fullname: '<b>Curso</b>', shortname: 'C', sortorder: 1, visible: true, path: 'A',
                }],
                total: 1,
                perpage: 100,
            };
        },
    };
    const store = new Map();
    const storage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v) };
    const state = { api, strings, storage };
    return { model: createModel(rows), container, calls, store, state };
};
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const rowOf = (container, id) => container.querySelector(`[data-id="${id}"] > .tool-ceaorganizador-fila`);

test('con 3 categorías crea 3 treeitem dentro de un role=tree', () => {
    const { model, container, state } = setup([row(1, 0, 'A', 1), row(2, 0, 'B', 2), row(3, 0, 'C', 3)]);
    render(container, model, state);
    assert.equal(container.getAttribute('role'), 'tree');
    const items = container.querySelectorAll('[role="treeitem"]');
    assert.equal(items.length, 3);
    assert.equal(items[0].getAttribute('aria-level'), '1');
    assert.equal(items[0].getAttribute('aria-expanded'), 'false');
    assert.equal(items[0].dataset.type, 'category');
    assert.equal(items[0].dataset.id, '1');
});

test('un nodo con needsort muestra la insignia y uno oculto la etiqueta', () => {
    const { model, container, state } = setup([row(1, 0, 'A', 1, { needsort: true }), row(2, 0, 'B', 2, { visible: false })]);
    render(container, model, state);
    const badges = container.querySelectorAll('[data-region="needsort"]');
    assert.equal(badges.length, 1);
    assert.equal(badges[0].textContent, 'Te recomiendo auto ordenar');
    assert.ok(container.querySelector('[data-id="1"] [data-region="needsort"]'));
    assert.ok(container.querySelector('[data-id="2"]').textContent.includes('Oculta'));
});

test('expandir pide los cursos de la categoría y los pinta como texto', async () => {
    const { model, container, calls, state, store } = setup([row(1, 0, 'A', 1), row(2, 1, 'Sub', 1)]);
    render(container, model, state);
    rowOf(container, 1).click();
    await flush();
    assert.deepEqual(calls, [[1, '', 0]]);
    const item = container.querySelector('[data-id="1"]');
    assert.equal(item.getAttribute('aria-expanded'), 'true');
    assert.equal(item.querySelector('[role="group"]').getAttribute('role'), 'group');
    const course = container.querySelector('[data-type="course"]');
    assert.equal(course.textContent.includes('<b>Curso</b>'), true);
    assert.equal(course.querySelector('b'), null);
    assert.equal(course.getAttribute('aria-level'), '2');
    assert.ok(container.querySelector('[data-id="2"][data-type="category"]'));
    assert.equal(container.querySelector('[data-action="loadmore"]'), null);
    assert.deepEqual(JSON.parse(store.get('tool_ceaorganizador_expanded')), [1]);
});

test('Cargar más aparece mientras falten cursos y pide la página siguiente', async () => {
    const { model, container, state } = setup([row(1, 0, 'A', 1)]);
    const calls = [];
    state.api.getCourses = async (id, search, page) => {
        calls.push([id, search, page]);
        return {
            courses: [{
                id: 100 + page, category: 1, fullname: `C${page}`, shortname: 'c', sortorder: page, visible: true, path: 'A',
            }],
            total: 2,
            perpage: 1,
        };
    };
    render(container, model, state);
    rowOf(container, 1).click();
    await flush();
    const more = container.querySelector('[data-action="loadmore"]');
    assert.equal(more.textContent, 'Cargar más');
    more.click();
    await flush();
    assert.deepEqual(calls, [[1, '', 0], [1, '', 1]]);
    assert.equal(container.querySelectorAll('[data-type="course"]').length, 2);
    assert.equal(container.querySelector('[data-action="loadmore"]'), null);
});

test('restaura lo expandido desde localStorage y funciona sin almacenamiento', async () => {
    const a = setup([row(1, 0, 'A', 1)]);
    a.store.set('tool_ceaorganizador_expanded', '[1]');
    render(a.container, a.model, a.state);
    await flush();
    assert.equal(a.container.querySelector('[data-id="1"]').getAttribute('aria-expanded'), 'true');
    assert.equal(a.calls.length, 1);

    const b = setup([row(1, 0, 'A', 1)]);
    const blocked = () => {
        throw new Error('bloqueado');
    };
    b.state.storage = { getItem: blocked, setItem: blocked };
    render(b.container, b.model, b.state);
    assert.equal(b.container.querySelector('[data-id="1"]').getAttribute('aria-expanded'), 'false');
    rowOf(b.container, 1).click();
    await flush();
    assert.equal(b.container.querySelector('[data-id="1"]').getAttribute('aria-expanded'), 'true');
});

test('con un filtro solo muestra las categorías visibles', () => {
    const { model, container, state } = setup([row(1, 0, 'A', 1), row(2, 1, 'B', 1), row(3, 0, 'C', 2)]);
    state.visible = model.filter('b');
    render(container, model, state);
    assert.equal(container.querySelector('[data-id="3"]'), null);
    assert.ok(container.querySelector('[data-id="2"]'));
    assert.equal(container.querySelector('[data-id="1"]').getAttribute('aria-expanded'), 'true');
});

test('el nombre de una categoría se pinta como texto', () => {
    const { model, container, state } = setup([row(1, 0, '<img src=x onerror=alert(1)>', 1)]);
    render(container, model, state);
    assert.equal(container.querySelector('img'), null);
});
