import './vue-loader.js';
import test, { afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

const dom = new JSDOM(
    '<!doctype html><html><head><meta name="csrf-token" content="test-csrf"></head><body><main id="app"></main></body></html>',
    { url: 'https://microhelium.test/backend/organizations' },
);
for (const key of ['window', 'document', 'location', 'history', 'Element', 'HTMLElement', 'Document', 'SVGElement', 'Node', 'Event', 'MutationObserver']) globalThis[key] = dom.window[key];

const { createApp, nextTick } = await import('vue');
const Organizations = (await import('../../resources/js/features/Organizations.vue')).default;
const JudgeMachines = (await import('../../resources/js/features/JudgeMachines.vue')).default;

const tick = async () => { await new Promise(resolve => setTimeout(resolve, 0)); await nextTick(); };
const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

let app;
afterEach(() => { app?.unmount(); app = null; document.body.innerHTML = '<main id="app"></main>'; });

function clickByText(text) {
    const button = [...document.querySelectorAll('button')].find(b => b.textContent.includes(text));
    assert.ok(button, `botão "${text}" não encontrado`);
    button.dispatchEvent(new Event('click', { bubbles: true }));
    return button;
}

// ── Organizações (#188/#233) ────────────────────────────────────────────

test('organizações: o estado vazio explica a consequência para o banco de problemas', async () => {
    globalThis.fetch = async () => json({ data: { organizations: [], roles: ['editor'] } });
    app = createApp(Organizations); app.mount('#app'); await tick();

    const texto = document.body.textContent;
    assert.match(texto, /Nenhuma organização ainda/);
    // A issue pede ligar o estado vazio de propriedade no banco a esta tela:
    // sem organização, o seletor de proprietário fica vazio.
    assert.match(texto, /seletor de proprietário/i);
});

test('organizações: arquivar diz que os problemas continuam com a organização', async () => {
    globalThis.fetch = async () => json({
        data: {
            organizations: [{ id: 7, name: 'Universidade Exemplo', archived: false, archived_at: null, problem_count: 200, members: [] }],
            roles: ['editor'],
        },
    });
    app = createApp(Organizations); app.mount('#app'); await tick();

    clickByText('Arquivar');
    await tick();

    const texto = document.body.textContent;
    // Arquivar NAO orfana nada -- a confirmacao tem que dizer isso, porque
    // "arquivar" sozinho soa como remover.
    assert.match(texto, /200 problema\(s\) dela continuam sendo dela/);
    assert.match(texto, /membros\s+continuam podendo editá-los/);
    assert.match(texto, /seletor de proprietário/i);
});

test('organizações: remover membro pede confirmação nomeando quem perde o acesso', async () => {
    globalThis.fetch = async () => json({
        data: {
            organizations: [{
                id: 7, name: 'Universidade Exemplo', archived: false, archived_at: null, problem_count: 3,
                members: [{ user_id: 42, name: 'Professora Ana', role: 'editor' }],
            }],
            roles: ['editor'],
        },
    });
    app = createApp(Organizations); app.mount('#app'); await tick();

    document.querySelector('details').open = true;
    clickByText('Remover');
    await tick();

    assert.match(document.body.textContent, /Remover o acesso de Professora Ana\?/);
});

test('organizações: um 422 preserva o nome digitado', async () => {
    let chamadas = 0;
    globalThis.fetch = async (_url, options = {}) => {
        if (options.method === 'POST') return json({ message: 'inválido', errors: { name: ['Este nome já existe.'] } }, 422);
        chamadas++;
        return json({ data: { organizations: [], roles: ['editor'] } });
    };
    app = createApp(Organizations); app.mount('#app'); await tick();

    const input = document.querySelector('[name="name"]');
    input.value = 'Universidade Exemplo';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await tick();

    // O que o operador digitou continua na tela: reescrever depois de um 422
    // e o jeito mais rapido de perder trabalho.
    assert.equal(document.querySelector('[name="name"]').value, 'Universidade Exemplo');
    assert.match(document.body.textContent, /Este nome já existe\./);
    assert.equal(chamadas, 1, 'um 422 não pode recarregar a lista por cima do formulário');
});

// ── Calibração de máquinas (#196/#233) ──────────────────────────────────

const maquinas = {
    items: [
        { id: 1, name: 'rapida', enabled: true, state: 'idle', languages: [], declares_languages: false, holding: [] },
        { id: 2, name: 'lenta', enabled: true, state: 'idle', languages: [], declares_languages: false, holding: [] },
    ],
    meta: { total: 2, enabled: 2, judging: 0, stale: 0, stale_after_seconds: 90, lease_seconds: 600 },
    capabilities: { can_manage_judges: true },
};

async function montarMaquinas(calibracao) {
    globalThis.fetch = async url => json(String(url).includes('/calibration') ? { data: calibracao } : { data: maquinas });
    app = createApp(JudgeMachines); app.mount('#app'); await tick(); await tick();
}

test('calibração: ausência de medição não é apresentada como máquinas equivalentes', async () => {
    await montarMaquinas({ threshold: 1.5, items: [], machines: [] });

    const texto = document.body.textContent;
    // A issue e explicita: nao confundir ausencia de medicao com igualdade.
    assert.match(texto, /não\s+quer dizer que as máquinas sejam equivalentes/);
    assert.match(texto, /duas\s+máquinas diferentes/);
});

test('calibração: a tela não promete compensação de vereditos', async () => {
    await montarMaquinas({
        threshold: 1.5,
        items: [{
            problem_id: 1, problem: 'A', language_id: 1, language: 'C++', samples: 6,
            per_host: [{ judgehost_id: 1, median_cpu_ms: 1000 }, { judgehost_id: 2, median_cpu_ms: 3000 }],
            fastest_ms: 1000, slowest_ms: 3000, divergence: 3.0,
        }],
        machines: [],
    });

    const texto = document.body.textContent;
    assert.match(texto, /não altera vereditos/i);
    assert.match(texto, /3\.00×/);
    assert.match(texto, /acima do limiar/);
});

test('calibração: a divergência é mostrada com o nome da máquina, não com o id', async () => {
    await montarMaquinas({
        threshold: 1.5,
        items: [{
            problem_id: 1, problem: 'A', language_id: 1, language: 'C++', samples: 4,
            per_host: [{ judgehost_id: 1, median_cpu_ms: 1000 }, { judgehost_id: 2, median_cpu_ms: 1100 }],
            fastest_ms: 1000, slowest_ms: 1100, divergence: 1.1,
        }],
        machines: [],
    });

    document.querySelectorAll('details').forEach(d => { d.open = true; });
    await tick();

    const texto = document.body.textContent;
    assert.match(texto, /rapida/);
    assert.match(texto, /lenta/);
});

test('calibração: um payload com forma inesperada não apaga a tela de máquinas', async () => {
    // O caso que dois testes JA EXISTENTES pegaram: eles servem o payload de
    // maquinas para qualquer URL, e a lista de maquinas tambem tem `items`.
    // Sem conferir a forma, o render chamava `.toFixed()` num campo ausente
    // e derrubava o componente inteiro -- a tela ficava em branco. Um
    // try/catch no fetch nao pega isso, porque acontece depois.
    globalThis.fetch = async () => json({ data: maquinas });
    app = createApp(JudgeMachines); app.mount('#app'); await tick(); await tick();

    assert.match(document.body.textContent, /rapida/, 'a lista de máquinas tem que sobreviver');
    // E a secao diz que falhou, com botao de tentar de novo -- e nao
    // "Carregando..." para sempre, que prometeria algo que nunca chega.
    assert.match(document.body.textContent, /Não foi possível carregar a comparação/);
    assert.doesNotMatch(document.body.textContent, /Carregando comparação/);
});

test('calibração: linhas malformadas são descartadas sem levar as boas junto', async () => {
    await montarMaquinas({
        threshold: 1.5,
        items: [
            { problem_id: 9, problem: 'Z' },
            {
                problem_id: 1, problem: 'A', language_id: 1, language: 'C++', samples: 4,
                per_host: [{ judgehost_id: 1, median_cpu_ms: 1000 }, { judgehost_id: 2, median_cpu_ms: 2000 }],
                fastest_ms: 1000, slowest_ms: 2000, divergence: 2.0,
            },
        ],
        machines: [],
    });

    assert.match(document.body.textContent, /2\.00×/);
    assert.doesNotMatch(document.body.textContent, /Z/);
});

test('calibração: falhar a comparação não derruba a tela de máquinas', async () => {
    globalThis.fetch = async url => {
        if (String(url).includes('/calibration')) return json({ message: 'erro' }, 500);
        return json({ data: maquinas });
    };
    app = createApp(JudgeMachines); app.mount('#app'); await tick(); await tick();

    // A tela de maquinas e operacional: uma falha na comparacao, que e
    // informativa, nao pode levar junto a lista de quem esta julgando.
    assert.match(document.body.textContent, /rapida/);
    assert.match(document.body.textContent, /Não foi possível carregar a comparação/);
});
