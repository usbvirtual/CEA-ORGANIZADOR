import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { open } from 'tool_ceaorganizador/historypanel';
import { decodeEntities } from 'tool_ceaorganizador/text';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const strings = new Proxy({}, { get: (_, name) => (name === 'historypage' ? 'Página {$a}' : String(name)) });

test('los conflictos de un deshacer se decodifican y se escriben como texto', async () => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    globalThis.DOMParser = dom.window.DOMParser;
    const api = {
        history: async () => ({
            rows: [{ id: 3, action: 'sort', summary: 'Auto ordenar «A &amp; B»', userfullname: '', timecreated: 1, undone: false }],
            total: 1,
            perpage: 20,
        }),
    };
    open({
        api,
        strings,
        onError: (error) => {
            throw error;
        },
        undo: async () => ({ ok: false, conflicts: ['«A &amp; B» cambió', '&lt;b&gt;x&lt;/b&gt;'] }),
        reload: async () => {},
        decode: decodeEntities,
        trigger: document.getElementById('t'),
        doc: document,
    });
    await flush();
    assert.match(document.querySelector('[data-region="rows"]').textContent, /«A & B»/);
    document.querySelector('[data-action="undo"]').click();
    await flush();
    const region = document.querySelector('[data-region="conflicts"]');
    assert.equal(region.hidden, false);
    const items = [...region.querySelectorAll('li')].map((li) => li.textContent);
    assert.deepEqual(items, ['«A & B» cambió', '<b>x</b>']);
    assert.equal(region.querySelector('b'), null);
});

test('una entrada de clonado no tiene botón Deshacer', async () => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    globalThis.DOMParser = dom.window.DOMParser;
    const api = {
        history: async () => ({
            rows: [
                { id: 4, action: 'clone', summary: 'Clonado a 2027-01 · 3 cursos', userfullname: '', timecreated: 1, undone: false },
                { id: 3, action: 'sort', summary: 'Auto ordenar', userfullname: '', timecreated: 1, undone: false },
            ],
            total: 2,
            perpage: 20,
        }),
    };
    open({
        api,
        strings,
        onError: (error) => {
            throw error;
        },
        undo: async () => null,
        reload: async () => {},
        decode: decodeEntities,
        trigger: document.getElementById('t'),
        doc: document,
    });
    await flush();
    assert.equal(document.querySelector('[data-id="4"] [data-action="undo"]'), null);
    assert.ok(document.querySelector('[data-id="3"] [data-action="undo"]'));
});

test('una entrada de clonado tiene «Ver avance», que cierra el historial y abre el progreso del lote', async () => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    globalThis.DOMParser = dom.window.DOMParser;
    const api = {
        history: async () => ({
            rows: [
                { id: 4, action: 'clone', summary: 'Clonado a 2027-01 · cursos: 3', userfullname: '', timecreated: 1, undone: false },
                { id: 3, action: 'sort', summary: 'Auto ordenar', userfullname: '', timecreated: 1, undone: false },
            ],
            total: 2,
            perpage: 20,
        }),
    };
    const opened = [];
    open({
        api,
        strings,
        onError: (error) => {
            throw error;
        },
        undo: async () => null,
        reload: async () => {},
        viewProgress: (batchid) => opened.push(batchid),
        decode: decodeEntities,
        trigger: document.getElementById('t'),
        doc: document,
    });
    await flush();
    const button = document.querySelector('[data-id="4"] [data-action="viewprogress"]');
    assert.ok(button);
    assert.equal(button.textContent, 'cloneviewprogress');
    assert.equal(document.querySelector('[data-id="3"] [data-action="viewprogress"]'), null);
    button.click();
    assert.deepEqual(opened, [4]);
    assert.equal(document.querySelector('[role="dialog"]'), null);
});
