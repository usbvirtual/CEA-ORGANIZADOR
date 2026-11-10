import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as api from 'tool_ceaorganizador/api';

test('cada método envía la función web y los parámetros exactos', async () => {
    const calls = [];
    api.setTransport(async (methodname, args) => {
        calls.push([methodname, args]);
        return { ok: true };
    });
    await api.getTree();
    await api.getCourses(3, 'mat', 1);
    await api.moveCategory(4, 0, 2);
    await api.moveCourse(9, 4, 0);
    await api.sortPreview({ categoryid: 1 });
    await api.sortApply({ categoryid: 1, hash: 'abc' });
    await api.renamePreview({ items: [], ops: '[]' });
    await api.renameApply({ changes: [] });
    await api.undo(7);
    await api.history(2);
    assert.deepEqual(calls, [
        ['tool_ceaorganizador_get_tree', {}],
        ['tool_ceaorganizador_get_courses', { categoryid: 3, search: 'mat', page: 1 }],
        ['tool_ceaorganizador_move_category', { id: 4, newparent: 0, afterid: 2 }],
        ['tool_ceaorganizador_move_course', { id: 9, categoryid: 4, afterid: 0 }],
        ['tool_ceaorganizador_sort_preview', { categoryid: 1 }],
        ['tool_ceaorganizador_sort_apply', { categoryid: 1, hash: 'abc' }],
        ['tool_ceaorganizador_rename_preview', { items: [], ops: '[]' }],
        ['tool_ceaorganizador_rename_apply', { changes: [] }],
        ['tool_ceaorganizador_undo', { logid: 7 }],
        ['tool_ceaorganizador_history', { page: 2 }],
    ]);
});
