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

const setupFlaky = (script) => {
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
    const errors = [];
    let at = 0;
    const api = {
        cloneStatus: async () => {
            const step = script[Math.min(at++, script.length - 1)];
            if (step instanceof Error) {
                throw step;
            }
            return step;
        },
    };
    open(7, {
        api,
        strings,
        onError: (error) => errors.push(error),
        timers,
        trigger: document.getElementById('t'),
        doc: document,
    });
    return { document, clock, errors };
};

test('una consulta fallida se reintenta a los 15, 30 y 60 s; solo después se avisa el error', async () => {
    const boom = new Error('red');
    const { clock, errors } = setupFlaky([boom]);
    await flush();
    assert.deepEqual(clock.scheduled.map((s) => s.ms), [15000]);
    assert.equal(errors.length, 0);
    await tick(clock);
    assert.deepEqual(clock.scheduled.map((s) => s.ms), [30000]);
    await tick(clock);
    assert.deepEqual(clock.scheduled.map((s) => s.ms), [60000]);
    assert.equal(errors.length, 0);
    await tick(clock);
    assert.deepEqual(errors, [boom]);
    assert.equal(clock.scheduled.length, 0);
});

test('un éxito reinicia la cuenta de reintentos', async () => {
    const boom = new Error('red');
    const { clock, errors, document } = setupFlaky([boom, boom, status(1, 0, 0), boom, status(0, 1, 0)]);
    await flush();
    await tick(clock);
    assert.equal(clock.scheduled[0].ms, 30000);
    await tick(clock);
    // Success: back to the normal interval.
    assert.match(document.querySelector('[data-region="counters"]').textContent, /clonepending: 1/);
    assert.equal(clock.scheduled[0].ms, 15000);
    await tick(clock);
    // The first failure after a success waits 15 s again, not 60 s.
    assert.equal(clock.scheduled[0].ms, 15000);
    await tick(clock);
    assert.equal(errors.length, 0);
    assert.equal(clock.scheduled.length, 0);
});

test('sin cambios no se vuelve a pintar la lista y el foco se conserva', async () => {
    const rows = [
        { source: 1, newcourseid: 9, newfullname: 'A', status: 'done', url: '/course/view.php?id=9' },
        { source: 2, newcourseid: 0, newfullname: 'B', status: 'pending', url: '' },
    ];
    const { document, clock } = setup([
        status(1, 1, 0, rows),
        status(1, 1, 0, rows.map((r) => ({ ...r }))),
        status(0, 2, 0, [rows[0], { ...rows[1], status: 'done', newcourseid: 10, url: '/course/view.php?id=10' }]),
    ]);
    await flush();
    const link = document.querySelector('a');
    link.focus();
    assert.equal(document.activeElement, link);
    await tick(clock);
    assert.equal(document.querySelector('a'), link);
    assert.equal(document.activeElement, link);
    await tick(clock);
    assert.notEqual(document.querySelector('a'), link);
    assert.equal(document.querySelectorAll('a').length, 2);
    assert.match(document.querySelector('[data-region="counters"]').textContent, /clonedone: 2/);
});

test('el motivo de una copia fallida se muestra como texto y es opcional', async () => {
    const { document } = setup([status(0, 0, 2, [
        { source: 1, newcourseid: 0, newfullname: 'A', status: 'failed', url: '', reason: 'moodle_exception: <b>x</b>' },
        { source: 2, newcourseid: 0, newfullname: 'B', status: 'failed', url: '' },
    ])]);
    await flush();
    const reasons = document.querySelectorAll('[data-region="reason"]');
    assert.equal(reasons.length, 1);
    assert.equal(reasons[0].textContent, 'moodle_exception: <b>x</b>');
    assert.equal(document.querySelector('[data-region="copies"] b'), null);
});
