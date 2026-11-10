import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { createModel } from 'tool_ceaorganizador/treemodel';
import { render } from 'tool_ceaorganizador/treeview';
import { attach } from 'tool_ceaorganizador/keyboard';

globalThis.DOMParser = new JSDOM('').window.DOMParser;

const row = (id, parent, name, sortorder) => ({
    id, parent, name, sortorder, visible: true, coursecount: 0, needsort: false,
});
const strings = {
    needsort: 'n', hidden: 'Oculta', loadmore: 'm', coursecount: '{$a} cursos',
    grabbed: 'Tomado: {$a}.', dropbefore: 'Antes de {$a}', dropinside: 'Dentro de {$a}',
    dropafter: 'Después de {$a}', dropcancelled: 'Movimiento cancelado', invaliddrop: 'No se puede soltar aquí',
};
const setup = () => {
    const dom = new JSDOM('<!doctype html><div id="c"></div>');
    const { document, KeyboardEvent } = dom.window;
    const container = document.getElementById('c');
    const model = createModel([row(1, 0, 'A', 1), row(2, 0, 'B', 2), row(3, 0, 'C', 3)]);
    const state = { api: { getCourses: async () => ({ courses: [], total: 0 }) }, strings, storage: null };
    render(container, model, state);
    const drops = [];
    const controller = attach(container, { model, strings }, {
        onDrop: (dragged, dest) => drops.push({ dragged, dest }),
    });
    const first = container.querySelector('[data-id="1"]');
    first.focus();
    const press = (key) => first.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
    const live = () => document.querySelector('[aria-live="polite"]');
    return { container, drops, press, live, first, controller, document };
};

test('Espacio, flecha abajo dos veces y Enter suelta en el segundo destino válido', () => {
    const { drops, press, first } = setup();
    press(' ');
    assert.ok(first.classList.contains('tool-ceaorganizador-grabbed'));
    press('ArrowDown');
    press('ArrowDown');
    press('Enter');
    assert.equal(drops.length, 1);
    assert.deepEqual(drops[0].dragged, { type: 'category', id: 1 });
    assert.deepEqual(drops[0].dest, { parent: 0, afterid: 2 });
    assert.ok(!first.classList.contains('tool-ceaorganizador-grabbed'));
});

test('Esc cancela sin llamar a onDrop y lo anuncia', () => {
    const { drops, press, live, first } = setup();
    press(' ');
    press('ArrowDown');
    press('Escape');
    assert.equal(drops.length, 0);
    assert.equal(live().textContent, 'Movimiento cancelado');
    assert.ok(!first.classList.contains('tool-ceaorganizador-grabbed'));
});

test('anuncia el destino con la región aria-live', () => {
    const { press, live } = setup();
    press(' ');
    assert.equal(live().textContent, 'Tomado: A.');
    press('ArrowDown');
    assert.equal(live().textContent, 'Dentro de B');
    press('ArrowDown');
    assert.equal(live().textContent, 'Después de B');
});

test('sin tomar nada, las flechas siguen siendo del árbol', () => {
    const { press, container } = setup();
    press('ArrowDown');
    assert.equal(container.ownerDocument.activeElement.dataset.id, '2');
});
