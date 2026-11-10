import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { show } from 'tool_ceaorganizador/toast';

test('muestra el mensaje con role=status, ejecuta la acción y se cierra', () => {
    const dom = new JSDOM('<!doctype html><div id="c"></div>');
    const container = dom.window.document.getElementById('c');
    let done = 0;
    const toast = show('Movido <b>x</b>', {
        actionLabel: 'Deshacer',
        onAction: () => {
            done++;
        },
        container,
    });
    const el = container.querySelector('[role="status"]');
    assert.ok(el);
    assert.equal(el.querySelector('b'), null);
    el.querySelector('button').click();
    assert.equal(done, 1);
    assert.equal(container.querySelector('[role="status"]'), null);
    toast.dismiss();
});

test('se cierra solo al pasar el tiempo', async () => {
    const dom = new JSDOM('<!doctype html><div id="c"></div>');
    const container = dom.window.document.getElementById('c');
    show('Hola', { timeout: 10, container });
    assert.ok(container.querySelector('[role="status"]'));
    await new Promise((resolve) => setTimeout(resolve, 40));
    assert.equal(container.querySelector('[role="status"]'), null);
});
