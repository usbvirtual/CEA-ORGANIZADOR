import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { createModel } from 'tool_ceaorganizador/treemodel';
import { createSelection } from 'tool_ceaorganizador/selection';
import { render } from 'tool_ceaorganizador/treeview';
import { openDialog } from 'tool_ceaorganizador/dialog';

globalThis.DOMParser = new JSDOM('').window.DOMParser;

const strings = new Proxy({}, { get: (_, name) => String(name) });
const row = (id, name) => ({
    id, parent: 0, name, path: name, depth: 1, sortorder: id, visible: true, coursecount: 0, needsort: false,
});
const setup = () => {
    const dom = new JSDOM('<!doctype html><div id="c"></div>');
    const { document } = dom.window;
    const container = document.getElementById('c');
    const actions = [];
    const state = {
        api: { getCourses: async () => ({ courses: [], total: 0 }) },
        strings,
        storage: null,
        selection: createSelection(),
        onMoveTo: () => {},
        onAction: (...args) => actions.push(args[0]),
        wwwroot: 'https://example.org',
    };
    const model = createModel([row(1, 'A'), row(2, 'B')]);
    render(container, model, state);
    return { dom, document, container, actions, state, model };
};
const press = (dom, target, init) => target.dispatchEvent(new dom.window.KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init }));

test('Shift+F10 y la tecla de menú contextual abren el menú del nodo y Esc devuelve el foco al nodo', () => {
    for (const init of [{ key: 'F10', shiftKey: true }, { key: 'ContextMenu' }]) {
        const { dom, document, container } = setup();
        const item = container.querySelector('[data-id="1"]');
        item.focus();
        press(dom, item, init);
        const menu = item.querySelector('[role="menu"]');
        assert.ok(menu);
        assert.equal(document.activeElement.getAttribute('role'), 'menuitem');
        assert.equal(document.activeElement.dataset.action, 'moveto');
        press(dom, document.activeElement, { key: 'Escape' });
        assert.equal(item.querySelector('[role="menu"]'), null);
        assert.equal(document.activeElement, item);
    }
});

test('F10 sin Shift no abre el menú y los nodos anuncian sus atajos', () => {
    const { dom, container } = setup();
    const item = container.querySelector('[data-id="1"]');
    item.focus();
    press(dom, item, { key: 'F10' });
    assert.equal(item.querySelector('[role="menu"]'), null);
    assert.equal(item.getAttribute('aria-keyshortcuts'), 'Space M S Shift+F10');
});

test('al volver a dibujar el árbol el foco vuelve al mismo nodo', () => {
    const { container, document, state, model } = setup();
    container.querySelector('[data-id="2"]').focus();
    render(container, model, state);
    assert.equal(document.activeElement, container.querySelector('[data-id="2"]'));
});

test('si el disparador ya no está en el documento, el foco va al nodo que lo reemplazó', () => {
    const { container, document, state, model } = setup();
    const trigger = container.querySelector('[data-id="2"] [data-action="moveto"]');
    trigger.focus();
    const dialog = openDialog({ doc: document, title: 'T', trigger });
    // Redraw without the focus in the tree, as a reload would while the dialog is open.
    dialog.element.appendChild(Object.assign(document.createElement('button'), { id: 'x' })).focus();
    render(container, model, state);
    dialog.close();
    assert.equal(document.activeElement, container.querySelector('[data-id="2"]'));
});
