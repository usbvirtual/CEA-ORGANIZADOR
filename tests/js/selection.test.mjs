import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createModel } from 'tool_ceaorganizador/treemodel';
import { createSelection, loadSubtreeCourses } from 'tool_ceaorganizador/selection';

const row = (id, parent, name, sortorder) => ({ id, parent, name, sortorder });
const model = () => createModel([
    row(1, 0, 'Pregrado', 1), row(2, 0, 'Posgrado', 2), row(11, 1, 'Medicina', 1), row(111, 11, 'Cohorte', 1),
]);
const key = (item) => `${item.type}:${item.id}`;

test('toggle marca y desmarca, y count e items lo reflejan', () => {
    const selection = createSelection();
    selection.toggle('category', 1);
    selection.toggle('course', 7);
    assert.equal(selection.count(), 2);
    assert.deepEqual(selection.items().map(key), ['category:1', 'course:7']);
    selection.toggle('category', 1);
    assert.deepEqual(selection.items().map(key), ['course:7']);
    assert.equal(selection.has('course', 7), true);
    assert.equal(selection.has('category', 1), false);
});

test('addCategoryContents incluye a los descendientes y los cursos cargados, sin duplicar', () => {
    const selection = createSelection();
    selection.toggle('category', 11);
    selection.addCategoryContents(model(), 1, [
        { id: 100, category: 1 }, { id: 101, category: 111 }, { id: 200, category: 2 },
    ]);
    selection.addCategoryContents(model(), 1, [{ id: 100, category: 1 }]);
    assert.deepEqual(selection.items().map(key).sort(), [
        'category:1', 'category:11', 'category:111', 'course:100', 'course:101',
    ]);
    assert.equal(selection.count(), 5);
});

test('addSearchResults agrega cursos y clear vacía; onChange avisa', () => {
    const selection = createSelection();
    let calls = 0;
    selection.onChange(() => {
        calls += 1;
    });
    selection.addSearchResults([{ id: 5 }, { id: 6 }, { id: 5 }]);
    assert.deepEqual(selection.items().map(key), ['course:5', 'course:6']);
    selection.clear();
    assert.equal(selection.count(), 0);
    assert.equal(calls, 2);
});

test('loadSubtreeCourses recorre todas las páginas y los descendientes (más de 100 cursos)', async () => {
    const calls = [];
    const api = {
        getCourses: async (categoryid, search, page) => {
            calls.push([categoryid, page]);
            if (categoryid === 1) {
                const start = page * 100;
                const count = Math.min(100, 150 - start);
                return { total: 150, courses: Array.from({ length: count }, (_, i) => ({ id: 1000 + start + i })) };
            }
            return { total: categoryid === 11 ? 1 : 0, courses: categoryid === 11 ? [{ id: 5 }] : [] };
        },
    };
    const courses = await loadSubtreeCourses(api, model(), 1);
    assert.equal(courses.length, 151);
    assert.deepEqual(calls.filter(([id]) => id === 1), [[1, 0], [1, 1]]);
    const selection = createSelection();
    selection.addCategoryContents(model(), 1, courses);
    assert.equal(selection.items().filter((item) => item.type === 'course').length, 151);
});
