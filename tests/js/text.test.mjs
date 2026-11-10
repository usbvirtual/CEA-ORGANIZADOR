import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { decodeEntities } from 'tool_ceaorganizador/text';

globalThis.DOMParser = new JSDOM('').window.DOMParser;

test('decodeEntities decodifica las entidades del texto formateado', () => {
    assert.equal(decodeEntities('A &amp; B'), 'A & B');
    assert.equal(decodeEntities('&lt;b&gt;x&lt;/b&gt;'), '<b>x</b>');
    assert.equal(decodeEntities('Texto simple'), 'Texto simple');
    assert.equal(decodeEntities(''), '');
});

test('decodeEntities no interpreta etiquetas como HTML ejecutable', () => {
    assert.equal(decodeEntities('<img src=x onerror=alert(1)>&amp;'), '&');
});
