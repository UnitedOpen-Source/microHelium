import './vue-loader.js';
import test from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';

// Issue #397 -- o t() le o catalogo que o layout poe na pagina.
const { t, resetCatalog } = await import('../../resources/js/i18n.js');

function page(catalogJson) {
    const dom = new JSDOM(`<!doctype html><html lang="es"><body>${catalogJson === null ? '' : `<script type="application/json" id="i18n-catalog">${catalogJson}</script>`}</body></html>`);
    globalThis.document = dom.window.document;
    resetCatalog();
}

test('without a catalog the key, which is the Portuguese text, comes back', () => {
    page(null);
    assert.equal(t('Tempo restante'), 'Tempo restante');
});

test('the catalog on the page translates, with Laravel-style :name replacements', () => {
    page(JSON.stringify({ 'Tempo restante': 'Tiempo restante', ':count de :countMax': ':count de :countMax' }));
    assert.equal(t('Tempo restante'), 'Tiempo restante');
    // O nome mais longo primeiro: `:countMax` nao pode virar `3Max`.
    assert.equal(t(':count de :countMax', { count: 3, countMax: 9 }), '3 de 9');
});

test('a broken catalog leaves the interface in Portuguese instead of breaking the island', () => {
    page('{not json');
    assert.equal(t('Placar congelado'), 'Placar congelado');
});

test('the Spanish catalog shipped to the browser translates the contest timer labels', () => {
    const catalog = readFileSync(new URL('../../resources/lang/frontend/es.json', import.meta.url), 'utf8');
    page(catalog);
    assert.equal(t('Competição finalizada'), 'Competencia finalizada');
    assert.equal(t('Começa em'), 'Comienza en');
});
