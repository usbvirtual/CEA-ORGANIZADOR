import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createModel } from 'tool_ceaorganizador/treemodel';
import { zoneFor, canDrop, destinationFor, scrollDelta } from 'tool_ceaorganizador/dropzones';

const row = (id, parent, sortorder) => ({ id, parent, name: `N${id}`, sortorder });
// 1 { 11, 12 }, 2, 3
const model = () => createModel([row(1, 0, 1), row(2, 0, 2), row(3, 0, 3), row(11, 1, 1), row(12, 1, 2)]);
const cat = (id) => ({ type: 'category', id });
const course = (id, category) => ({ type: 'course', id, category });

test('zoneFor divide en tercios las categorías y en mitades los cursos', () => {
    assert.equal(zoneFor(5, 30, 'category'), 'before');
    assert.equal(zoneFor(15, 30, 'category'), 'inside');
    assert.equal(zoneFor(25, 30, 'category'), 'after');
    assert.equal(zoneFor(5, 30, 'course'), 'before');
    assert.equal(zoneFor(25, 30, 'course'), 'after');
});

test('canDrop rechaza una categoría sobre sí misma o su descendiente', () => {
    const m = model();
    assert.equal(canDrop(m, cat(1), cat(1), 'inside'), false);
    assert.equal(canDrop(m, cat(1), cat(11), 'inside'), false);
    assert.equal(canDrop(m, cat(1), cat(11), 'after'), false);
    assert.equal(canDrop(m, cat(11), cat(1), 'inside'), true);
    assert.equal(canDrop(m, cat(2), cat(3), 'before'), true);
});

test('canDrop rechaza un curso en la raíz, dentro de un curso o una categoría sobre un curso', () => {
    const m = model();
    assert.equal(canDrop(m, course(100, 2), cat(1), 'before'), false);
    assert.equal(canDrop(m, course(100, 2), cat(3), 'after'), false);
    assert.equal(canDrop(m, course(100, 2), cat(1), 'inside'), true);
    assert.equal(canDrop(m, course(100, 2), cat(11), 'before'), true);
    assert.equal(canDrop(m, course(100, 2), course(101, 1), 'inside'), false);
    assert.equal(canDrop(m, course(100, 2), course(101, 1), 'after'), true);
    assert.equal(canDrop(m, cat(2), course(101, 1), 'after'), false);
});

test('destinationFor de categorías: before la primera hermana da afterid 0, after X da X', () => {
    const m = model();
    assert.deepEqual(destinationFor(m, cat(3), cat(1), 'before'), { parent: 0, afterid: 0 });
    assert.deepEqual(destinationFor(m, cat(3), cat(1), 'after'), { parent: 0, afterid: 1 });
    assert.deepEqual(destinationFor(m, cat(1), cat(3), 'before'), { parent: 0, afterid: 2 });
    assert.deepEqual(destinationFor(m, cat(3), cat(2), 'inside'), { parent: 2, afterid: 0 });
    assert.deepEqual(destinationFor(m, cat(3), cat(12), 'before'), { parent: 1, afterid: 11 });
});

test('destinationFor devuelve null cuando el nodo ya está en esa posición', () => {
    const m = model();
    assert.equal(destinationFor(m, cat(2), cat(3), 'before'), null);
    assert.equal(destinationFor(m, cat(2), cat(1), 'after'), null);
    assert.equal(destinationFor(m, cat(1), cat(2), 'before'), null);
    assert.equal(destinationFor(m, cat(11), cat(1), 'inside'), null);
});

test('destinationFor de cursos usa la lista de hermanos del destino', () => {
    const m = model();
    const siblingsOf = (id) => (id === 2 ? [20, 21, 22] : []);
    assert.deepEqual(destinationFor(m, course(30, 3), cat(2), 'inside', siblingsOf), { category: 2, afterid: 0 });
    assert.deepEqual(destinationFor(m, course(30, 3), course(21, 2), 'after', siblingsOf), { category: 2, afterid: 21 });
    assert.deepEqual(destinationFor(m, course(30, 3), course(20, 2), 'before', siblingsOf), { category: 2, afterid: 0 });
    assert.deepEqual(destinationFor(m, course(22, 2), course(20, 2), 'before', siblingsOf), { category: 2, afterid: 0 });
    assert.equal(destinationFor(m, course(21, 2), course(20, 2), 'after', siblingsOf), null);
    assert.equal(destinationFor(m, course(20, 2), cat(2), 'inside', siblingsOf), null);
});

test('scrollDelta es 0 lejos del borde y proporcional cerca de él', () => {
    assert.equal(scrollDelta(300, 0, 600), 0);
    assert.ok(scrollDelta(10, 0, 600) < 0);
    assert.ok(scrollDelta(590, 0, 600) > 0);
    assert.ok(scrollDelta(1, 0, 600) < scrollDelta(30, 0, 600));
});
