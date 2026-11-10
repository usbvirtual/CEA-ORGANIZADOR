import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { createModel } from 'tool_ceaorganizador/treemodel';
import { open } from 'tool_ceaorganizador/movepicker';

const row = (id, parent, name, sortorder) => ({ id, parent, name, sortorder });
const setup = (draggedid = 1) => {
    const dom = new JSDOM('<!doctype html><button id="t">Mover</button>');
    const { document, KeyboardEvent } = dom.window;
    const model = createModel([row(1, 0, 'Pregrado', 1), row(2, 0, 'Posgrado', 2), row(11, 1, 'Medicina', 1), row(12, 1, 'Salud', 2)]);
    const picks = [];
    const trigger = document.getElementById('t');
    trigger.focus();
    const dialog = open({
        model,
        dragged: { type: 'category', id: draggedid },
        name: 'Pregrado',
        trigger,
        strings: { movetitle: 'Mover «{$a}» a…', moveto: 'Mover a…', movetoroot: 'Raíz (primer nivel)' },
        onPick: (id) => picks.push(id),
        doc: document,
    });
    return { document, KeyboardEvent, dialog, picks, trigger };
};
const labels = (document) => [...document.querySelectorAll('.tool-ceaorganizador-dialogo-opcion')].map((b) => b.textContent);

test('el diálogo es modal, excluye la categoría movida y sus descendientes', () => {
    const { document, dialog } = setup();
    assert.equal(dialog.element.getAttribute('role'), 'dialog');
    assert.equal(dialog.element.getAttribute('aria-modal'), 'true');
    assert.equal(dialog.element.querySelector('h2').textContent, 'Mover «Pregrado» a…');
    assert.deepEqual(labels(document), ['Posgrado']);
});

test('la raíz no se ofrece a una categoría que ya es la primera de primer nivel', () => {
    const { document } = setup(1);
    assert.ok(!labels(document).includes('Raíz (primer nivel)'));
});

test('una subcategoría ve la opción de raíz primero y elegirla llama a onPick(0)', () => {
    const { document, picks } = setup(12);
    assert.equal(labels(document)[0], 'Raíz (primer nivel)');
    document.querySelector('.tool-ceaorganizador-dialogo-opcion').click();
    assert.deepEqual(picks, [0]);
});

test('el buscador filtra por ruta y elegir llama a onPick y cierra', () => {
    const { document, picks } = setup();
    const input = document.querySelector('input');
    input.value = 'pos';
    input.dispatchEvent(new document.defaultView.Event('input'));
    assert.deepEqual(labels(document), ['Posgrado']);
    input.value = 'zzz';
    input.dispatchEvent(new document.defaultView.Event('input'));
    assert.deepEqual(labels(document), []);
    input.value = '';
    input.dispatchEvent(new document.defaultView.Event('input'));
    document.querySelector('.tool-ceaorganizador-dialogo-opcion').click();
    assert.deepEqual(picks, [2]);
    assert.equal(document.querySelector('[role="dialog"]'), null);
});

test('Esc cierra y devuelve el foco al botón que lo abrió', () => {
    const { document, KeyboardEvent, trigger, picks } = setup();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(document.querySelector('[role="dialog"]'), null);
    assert.equal(document.activeElement, trigger);
    assert.equal(picks.length, 0);
});

test('Tab desde el último elemento vuelve al primero', () => {
    const { document, KeyboardEvent } = setup();
    document.querySelector('.tool-ceaorganizador-dialogo-opcion').focus();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
    assert.equal(document.activeElement, document.querySelector('input'));
});
