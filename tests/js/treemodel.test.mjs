import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createModel } from 'tool_ceaorganizador/treemodel';

const row = (id, parent, name, sortorder, extra = {}) => ({
    id, parent, name, path: name, depth: 1, sortorder, visible: true, coursecount: 0, needsort: false, ...extra,
});
const rows = () => [
    row(1, 0, 'A', 10),
    row(2, 1, 'B', 20),
    row(3, 2, 'Semestre 2025-1', 30),
    row(4, 1, 'Educación', 15),
    row(5, 0, 'Z', 5),
];

test('children se ordena por sortorder y 0 es la raíz', () => {
    const m = createModel(rows());
    assert.deepEqual(m.children(0).map((r) => r.id), [5, 1]);
    assert.deepEqual(m.children(1).map((r) => r.id), [4, 2]);
    assert.deepEqual(m.children(3), []);
    assert.equal(m.get(2).name, 'B');
    assert.equal(m.get(99), null);
});

test('pathOf devuelve los nombres desde la raíz', () => {
    const m = createModel(rows());
    assert.deepEqual(m.pathOf(2), ['A', 'B']);
    assert.deepEqual(m.pathOf(1), ['A']);
});

test('isDescendant(A, B) es verdadero cuando B está dentro de A', () => {
    const m = createModel(rows());
    assert.equal(m.isDescendant(1, 2), true);
    assert.equal(m.isDescendant(1, 3), true);
    assert.equal(m.isDescendant(2, 1), false);
    assert.equal(m.isDescendant(1, 1), false);
    assert.equal(m.isDescendant(5, 2), false);
});

test('filter incluye coincidencias y ancestros, sin distinguir tildes ni mayúsculas', () => {
    const m = createModel(rows());
    assert.deepEqual([...m.filter('semestre')].sort(), [1, 2, 3]);
    assert.deepEqual([...m.filter('EDUCACION')].sort(), [1, 4]);
    // También coincide con la ruta: todo lo que cuelga de «A / B».
    assert.deepEqual([...m.filter('a / b')].sort(), [1, 2, 3]);
    assert.equal(m.filter('nada').size, 0);
    assert.equal(m.filter('').size, 5);
});

test('applyMoveCategory seguido de su inversa deja el modelo igual', () => {
    const m = createModel(rows());
    const before = JSON.stringify(m.snapshot());
    const inverse = m.applyMoveCategory(2, 0, 5);
    assert.deepEqual(m.children(0).map((r) => r.id), [5, 2, 1]);
    assert.deepEqual(m.pathOf(3), ['B', 'Semestre 2025-1']);
    assert.notEqual(JSON.stringify(m.snapshot()), before);
    m.applyMoveCategory(inverse.id, inverse.newparent, inverse.afterid);
    assert.equal(JSON.stringify(m.snapshot()), before);
    assert.deepEqual(m.pathOf(3), ['A', 'B', 'Semestre 2025-1']);
});

test('applyMoveCategory con afterid 0 deja la categoría de primera', () => {
    const m = createModel(rows());
    const inverse = m.applyMoveCategory(2, 5, 0);
    assert.deepEqual(m.children(5).map((r) => r.id), [2]);
    assert.deepEqual(inverse, { id: 2, newparent: 1, afterid: 4 });
});
