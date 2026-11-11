import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { open } from 'tool_ceaorganizador/sortpanel';

globalThis.DOMParser = new JSDOM('').window.DOMParser;

const strings = new Proxy({}, { get: (_, name) => (name === 'sortselectedcount' ? '{$a} categorías seleccionadas' : String(name)) });
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const setup = (options) => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    const calls = { preview: [], apply: [], toasts: [] };
    const api = {
        sortPreview: async (params) => {
            calls.preview.push(params);
            return { changes: [{ kind: 'categories', parentid: 3, parentpath: 'P', before: [{ id: 1, name: 'B' }], after: [{ id: 1, name: 'B' }] }], hash: 'h1' };
        },
        sortApply: async (params) => {
            calls.apply.push(params);
            return { status: 'ok', logid: 9 };
        },
    };
    open({
        api,
        strings,
        toast: { show: (message, opts) => calls.toasts.push({ message, opts }) },
        onError: (error) => {
            throw error;
        },
        undo: async () => ({ ok: true, conflicts: [] }),
        reload: async () => {},
        trigger: document.getElementById('t'),
        doc: document,
        ...options,
    });
    return { document, calls };
};

test('con categoryids, el panel muestra el conteo y envía categoryids en la vista previa y al aplicar', async () => {
    const { document, calls } = setup({ categoryids: [4, 5, 6], paths: ['A', 'A / B', 'C'] });
    await flush();
    const count = document.querySelector('[data-region="selectedcount"]');
    assert.equal(count.firstChild.textContent, '3 categorías seleccionadas');
    // The list of the tooltip is also in visually hidden text, for screen readers.
    assert.equal(count.title, 'A | A / B | C');
    const hidden = count.querySelector('[data-region="selectedpaths"]');
    assert.equal(hidden.className, 'sr-only visually-hidden');
    assert.equal(hidden.textContent, ' A | A / B | C');
    assert.deepEqual(calls.preview[0], { categoryid: 0, categoryids: [4, 5, 6], scope: 'both', recursive: false });
    document.querySelector('[data-action="confirm"]').click();
    await flush();
    assert.deepEqual(calls.apply[0], { categoryid: 0, categoryids: [4, 5, 6], scope: 'both', recursive: false, hash: 'h1' });
});

test('con una sola categoría sigue enviando categoryid y no categoryids', async () => {
    const { calls } = setup({ categoryid: 7, path: 'P' });
    await flush();
    assert.deepEqual(calls.preview[0], { categoryid: 7, scope: 'both', recursive: false });
});
