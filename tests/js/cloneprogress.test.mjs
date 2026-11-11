import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { open } from 'tool_ceaorganizador/cloneprogress';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const strings = new Proxy({}, { get: (_, name) => String(name) });

const setup = (responses) => {
    const dom = new JSDOM('<!doctype html><body><button id="t">x</button></body>');
    const { document } = dom.window;
    const clock = { now: 0, scheduled: [], cleared: [] };
    const timers = {
        setTimeout: (fn, ms) => {
            clock.scheduled.push({ fn, ms });
            return clock.scheduled.length;
        },
        clearTimeout: (id) => clock.cleared.push(id),
        now: () => clock.now,
    };
    const calls = [];
    let at = 0;
    const api = {
        cloneStatus: async (batchid) => {
            calls.push(batchid);
            return responses[Math.min(at++, responses.length - 1)];
        },
    };
    const panel = open(7, {
        api,
        strings,
        onError: (error) => {
            throw error;
        },
        timers,
        trigger: document.getElementById('t'),
        doc: document,
    });
    return { document, clock, calls, panel };
};
const status = (pending, done, failed, rows = []) => ({ rows, pending, done, failed });
const tick = async (clock) => {
    const next = clock.scheduled.shift();
    clock.now += next.ms;
    next.fn();
    await flush();
};

test('muestra contadores, enlaces a los cursos nuevos y el nombre crudo como texto', async () => {
    const { document, calls } = setup([
        status(1, 1, 0, [
            { source: 1, newcourseid: 9, newfullname: '<b>Cálculo</b> & Co', status: 'done', url: '/course/view.php?id=9' },
            { source: 2, newcourseid: 0, newfullname: 'Otro', status: 'pending', url: '' },
        ]),
    ]);
    await flush();
    assert.deepEqual(calls, [7]);
    assert.match(document.querySelector('[data-region="counters"]').textContent, /clonedone: 1.*clonepending: 1.*clonefailed: 0/);
    const link = document.querySelector('a');
    assert.equal(link.getAttribute('href'), '/course/view.php?id=9');
    assert.equal(link.textContent, '<b>Cálculo</b> & Co');
    assert.equal(document.querySelector('[data-region="copies"] b'), null);
    assert.equal(document.querySelectorAll('a').length, 1);
});

test('un url con esquema peligroso no se convierte en enlace', async () => {
    const { document } = setup([
        status(0, 1, 0, [{ source: 1, newcourseid: 9, newfullname: 'X', status: 'done', url: 'javascript:alert(1)' }]),
    ]);
    await flush();
    assert.equal(document.querySelector('a'), null);
});

test('consulta cada 15 s mientras haya pendientes y se detiene cuando pending llega a 0', async () => {
    const { clock, calls } = setup([status(2, 0, 0), status(1, 1, 0), status(0, 2, 0)]);
    await flush();
    assert.equal(clock.scheduled.length, 1);
    assert.equal(clock.scheduled[0].ms, 15000);
    await tick(clock);
    assert.equal(calls.length, 2);
    assert.equal(clock.scheduled.length, 1);
    await tick(clock);
    assert.equal(calls.length, 3);
    assert.equal(clock.scheduled.length, 0);
});

test('el aviso de cron aparece a los 10 minutos sin avance y no antes', async () => {
    const { document, clock } = setup([status(2, 0, 0)]);
    await flush();
    const warning = document.querySelector('[data-region="cronwarning"]');
    assert.equal(warning.hidden, true);
    for (let i = 0; i < 39; i += 1) {
        await tick(clock);
    }
    assert.equal(warning.hidden, true);
    await tick(clock);
    assert.equal(clock.now, 600000);
    assert.equal(warning.hidden, false);
    assert.equal(warning.textContent, 'cronwarning');
});

test('el avance reinicia el reloj del aviso', async () => {
    const { document, clock } = setup([status(3, 0, 0), status(2, 1, 0), status(2, 1, 0)]);
    await flush();
    clock.now = 575000;
    await tick(clock);
    await tick(clock);
    assert.equal(clock.now, 605000);
    assert.equal(document.querySelector('[data-region="cronwarning"]').hidden, true);
});

test('al cerrar se cancela el sondeo y una respuesta tardía se ignora', async () => {
    const { document, clock, calls, panel } = setup([status(2, 0, 0), status(1, 1, 0)]);
    await flush();
    panel.close();
    assert.equal(clock.cleared.length, 1);
    const pendingTick = clock.scheduled.shift();
    pendingTick.fn();
    await flush();
    assert.equal(calls.length, 1);
    assert.equal(document.querySelector('[role="dialog"]'), null);
});
