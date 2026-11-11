import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createModel } from 'tool_ceaorganizador/treemodel';
import { createSelection, loadSubtreeCourses, loadAllPages, exclusive, selectedCategoryIds } from 'tool_ceaorganizador/selection';

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

test('loadSubtreeCourses no pide las categorías con coursecount 0 y sí las que no traen el dato', async () => {
    const calls = [];
    const counted = createModel([
        { id: 1, parent: 0, name: 'Pregrado', sortorder: 1, coursecount: 2 },
        { id: 11, parent: 1, name: 'Vacía', sortorder: 1, coursecount: 0 },
        { id: 12, parent: 1, name: 'Sin dato', sortorder: 2 },
        { id: 121, parent: 12, name: 'Texto cero', sortorder: 1, coursecount: '0' },
    ]);
    const api = {
        getCourses: async (categoryid, search, page) => {
            calls.push([categoryid, search, page]);
            if (categoryid === 1) {
                return { total: 2, courses: [{ id: 10 }, { id: 11 }] };
            }
            return { total: 1, courses: [{ id: 50 }] };
        },
    };
    const courses = await loadSubtreeCourses(api, counted, 1);
    assert.deepEqual(calls, [[1, '', 0], [12, '', 0]]);
    assert.deepEqual(courses, [{ id: 10, category: 1 }, { id: 11, category: 1 }, { id: 50, category: 12 }]);
});

test('loadAllPages trae todas las páginas de una búsqueda (más de 100 resultados) y avisa el avance', async () => {
    const calls = [];
    const progress = [];
    const api = {
        getCourses: async (categoryid, search, page) => {
            calls.push([categoryid, search, page]);
            const start = page * 100;
            const count = Math.max(0, Math.min(100, 250 - start));
            return { total: 250, courses: Array.from({ length: count }, (_, i) => ({ id: start + i + 1 })) };
        },
    };
    const result = await loadAllPages(api, 0, 'calc', (loaded, total) => progress.push([loaded, total]));
    assert.equal(result.total, 250);
    assert.equal(result.courses.length, 250);
    assert.deepEqual(calls, [[0, 'calc', 0], [0, 'calc', 1], [0, 'calc', 2]]);
    assert.deepEqual(progress, [[100, 250], [200, 250], [250, 250]]);
    const selection = createSelection();
    selection.addSearchResults(result.courses);
    assert.equal(selection.count(), 250);
});

test('loadAllPages se detiene si el servidor devuelve una página vacía antes del total', async () => {
    let calls = 0;
    const api = {
        getCourses: async () => {
            calls += 1;
            return calls === 1 ? { total: 300, courses: [{ id: 1 }] } : { total: 300, courses: [] };
        },
    };
    const result = await loadAllPages(api, 4);
    assert.equal(calls, 2);
    assert.deepEqual(result.courses, [{ id: 1 }]);
});

test('exclusive ignora una segunda llamada mientras la primera corre, y deja correr después', async () => {
    let runs = 0;
    let release;
    const run = exclusive(() => {
        runs += 1;
        return new Promise((resolve) => {
            release = resolve;
        });
    });
    const first = run();
    assert.ok(first);
    assert.equal(run(), null);
    await Promise.resolve();
    assert.equal(run.running(), true);
    release('listo');
    assert.equal(await first, 'listo');
    assert.equal(run.running(), false);
    const again = run();
    assert.ok(again);
    // The task starts on the next microtask; wait for it before releasing it.
    await Promise.resolve();
    release('otra');
    assert.equal(await again, 'otra');
    assert.equal(runs, 2);
});

test('exclusive libera el candado cuando la tarea falla', async () => {
    const run = exclusive(async () => {
        throw new Error('fallo');
    });
    await assert.rejects(run(), /fallo/);
    assert.equal(run.running(), false);
    await assert.rejects(run(), /fallo/);
});

test('selectedCategoryIds deja solo las categorías: sin ellas el botón de ordenar queda deshabilitado', () => {
    const selection = createSelection();
    selection.toggle('course', 7);
    assert.deepEqual(selectedCategoryIds(selection.items()), []);
    selection.toggle('category', 4);
    selection.toggle('course', 8);
    selection.toggle('category', 2);
    assert.deepEqual(selectedCategoryIds(selection.items()), [4, 2]);
});
