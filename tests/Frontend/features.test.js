import './vue-loader.js';
import test, { afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
const dom = new JSDOM('<!doctype html><html><head><meta name="csrf-token" content="test-csrf"></head><body><main id="app"></main></body></html>', { url: 'https://microhelium.test/practice' });
for (const key of ['window', 'document', 'location', 'history', 'Element', 'HTMLElement', 'Document', 'SVGElement', 'Node', 'Event', 'MutationObserver']) globalThis[key] = dom.window[key];
const { createApp, nextTick } = await import('vue');
const { request, localUrl } = await import('../../resources/js/features/api.js');
const components = Object.fromEntries(await Promise.all(['Practice', 'Similarity', 'Webcast', 'JudgingHealth', 'BankGovernance', 'ManagedAccounts', 'SiteReport', 'JudgeHistory', 'JudgeMachines'].map(async name => [name, (await import(`../../resources/js/features/${name}.vue`)).default])));
const tick = async () => { await new Promise(resolve => setTimeout(resolve, 0)); await nextTick(); };
const envelope = (data, status = 200) => new Response(JSON.stringify({ data }), { status, headers: { 'Content-Type': 'application/json' } });
let app;
afterEach(() => { app?.unmount(); app = null; document.body.innerHTML = '<main id="app"></main>'; history.replaceState(null, '', '/practice'); });
async function mount(name, data, props = {}) { globalThis.fetch = async () => envelope(data); app = createApp(components[name], props); app.mount('#app'); await tick(); }
function field(name, value) { const input = document.querySelector(`[name="${name}"]`); assert.ok(input, name); input.value = value; input.dispatchEvent(new Event(input.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); return input; }
function submit(form = document.querySelector('form')) { form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); }
const meta = { current_page: 1, last_page: 1, total: 0 };
// Issue #144: the report screens are asserted against the same fixture
// bodies the local preview server serves, so the two cannot drift apart.
const fixtures = JSON.parse(readFileSync(new URL('./fixtures/features.json', import.meta.url), 'utf8'));

test('API distinguishes denied, expired, unavailable and invalid responses without leaking server HTML', async () => {
    for (const status of [401, 403, 404, 409, 419, 422, 429, 503]) {
        fetch = async () => new Response(JSON.stringify({ message: 'private server details', errors: { source: ['Código obrigatório.'] } }), { status, headers: { 'Content-Type': 'application/json' } });
        await assert.rejects(request('/api/frontend/test'), error => error.status === status && !error.message.includes('private server'));
    }
    fetch = async () => new Response('<html>login</html>', { headers: { 'Content-Type': 'text/html' } });
    await assert.rejects(request('/api/frontend/test'));
    for (const url of ['javascript:alert(1)', '//evil.test', '/\\evil.test', '/\nevil']) assert.equal(localUrl(url), null);
    assert.equal(localUrl('/submission/12'), '/submission/12');
});

// Issue #53, fase 4. As duas armadilhas que a spec
// (docs/specs/53-judge-management.md) manda a interface nao cair.
test('judge machines: an undeclared language list is not rendered as "judges nothing"', async () => {
    await mount('JudgeMachines', {
        items: [
            { id: 1, name: 'sem-declaracao', enabled: true, state: 'idle', languages: [], declares_languages: false, holding: [] },
            { id: 2, name: 'declarou', enabled: true, state: 'idle', languages: ['cpp', 'py'], declares_languages: true, holding: [] },
        ],
        meta: { total: 2, enabled: 2, judging: 0, stale: 0, lease_seconds: 600, stale_after_seconds: 90 },
        capabilities: { can_manage_judges: true },
    });

    // A fila trata "nao declarou" como "julga qualquer uma" (#117), e a tela
    // tem que dizer isso -- renderizar lista vazia como incapacidade faria o
    // operador desligar uma maquina que esta funcionando.
    assert.match(document.body.textContent, /julga qualquer uma/i);
    assert.match(document.body.textContent, /cpp, py/);
});

// Issue #303. A versao que cada maquina declara aparece junto da linguagem:
// e assim que o organizador ve que duas maquinas do parque nao sao
// intercambiaveis, em vez de descobrir isso num rejulgamento que mudou de
// veredito no meio da maratona.
test('judge machines: the declared language shows the version it judges with', async () => {
    await mount('JudgeMachines', {
        items: [
            { id: 1, name: 'judge-01', enabled: true, state: 'idle', languages: ['c_gcc13', 'sh'], language_versions: { c_gcc13: '15.2.0' }, declares_languages: true, holding: [] },
            { id: 2, name: 'judge-02', enabled: true, state: 'idle', languages: ['c_gcc13', 'sh'], language_versions: { c_gcc13: '13.2.1' }, declares_languages: true, holding: [] },
        ],
        meta: { total: 2, enabled: 2, judging: 0, stale: 0, lease_seconds: 600, stale_after_seconds: 90 },
        capabilities: { can_manage_judges: true },
    });

    assert.match(document.body.textContent, /c_gcc13 15\.2\.0/);
    assert.match(document.body.textContent, /c_gcc13 13\.2\.1/);
    // E a linguagem sem versao continua listada: ausente e "nao disse", e
    // nunca "nao tem".
    assert.match(document.body.textContent, /sh/);
});

test('judge machines: the one-time token is shown once and disappears for good', async () => {
    await mount('JudgeMachines', {
        items: [],
        meta: { total: 0, enabled: 0, judging: 0, stale: 0, lease_seconds: 600, stale_after_seconds: 90 },
        capabilities: { can_manage_judges: true },
    });

    // Respostas por metodo: o POST devolve o token, e o GET que vem logo
    // depois devolve a lista. Com um unico envelope para os dois, o reload
    // trocaria `data` por um corpo sem `capabilities` e a secao inteira
    // sumiria -- levando o token junto, por um motivo que nao existe fora
    // do teste.
    const listing = { items: [{ id: 9, name: 'sala-3', enabled: true, state: 'never_seen', languages: [], declares_languages: false, holding: [] }], meta: { total: 1, enabled: 1, judging: 0, stale: 0, lease_seconds: 600, stale_after_seconds: 90 }, capabilities: { can_manage_judges: true } };
    globalThis.fetch = async (url, options) => options?.method === 'POST'
        ? envelope({ judgehost: { id: 9, name: 'sala-3' }, token: 'segredo-de-48-caracteres' })
        : envelope(listing);
    field('name', 'sala-3');
    submit();
    await tick();

    const revealed = document.querySelector('.feature-secret input');
    assert.ok(revealed, 'o token nao foi mostrado');
    assert.equal(revealed.value, 'segredo-de-48-caracteres');

    // Escondido a pedido, e nao volta: o servidor guarda so o sha256.
    [...document.querySelectorAll('button')].find(button => /Já guardei/.test(button.textContent)).click();
    await tick();
    assert.equal(document.querySelector('.feature-secret'), null);
});

test('judge machines: disabling asks first, and says what it will do to the runs in flight', async () => {
    await mount('JudgeMachines', {
        items: [{ id: 3, name: 'sala-3', enabled: true, state: 'judging', languages: [], declares_languages: false, holding: [{ run_id: 12, run_number: 7, problem: 'C', seconds_held: 30 }] }],
        meta: { total: 1, enabled: 1, judging: 1, stale: 0, lease_seconds: 600, stale_after_seconds: 90 },
        capabilities: { can_manage_judges: true },
    });

    [...document.querySelectorAll('button')].find(button => /Desligar/.test(button.textContent)).click();
    await tick();

    // O que o operador precisa saber antes de apertar: o run volta para a
    // fila em vez de ficar parado ate o lease expirar.
    assert.match(document.body.textContent, /devolve à fila/i);
    assert.match(document.body.textContent, /Confirmar desligamento/);
});

test('missing backend renders honest unavailable state and can recover with retry', async () => {
    fetch = async () => new Response('', { status: 404 }); app = createApp(components.Similarity); app.mount('#app'); await tick();
    assert.match(document.body.textContent, /ainda não está disponível/); assert.equal(document.querySelector('form'), null);
    fetch = async () => envelope({ problems: [], items: [], capabilities: { can_start: false }, meta });
    document.querySelector('button').click(); await tick();
    assert.match(document.body.textContent, /Nenhuma análise solicitada/);
});

test('practice search and pagination reach server and preserve unrelated URL state', async () => {
    history.replaceState(null, '', '/practice?campaign=school');
    await mount('Practice', { items: [], meta: { ...meta, last_page: 2 } });
    const calls = []; fetch = async path => { calls.push(path); return envelope({ items: [], meta: { ...meta, last_page: 2 } }); };
    field('q', 'grafos'); submit(); await tick();
    assert.equal(calls[0], '/api/frontend/practice/problems?q=grafos&page=1');
    assert.equal(new URL(location.href).searchParams.get('campaign'), 'school');
    [...document.querySelectorAll('button')].find(b => b.textContent === 'Próxima').click(); await tick();
    assert.match(calls[1], /page=2/);
});

test('practice submission retains source on validation errors, focuses field and blocks duplicate clicks', async () => {
    await mount('Practice', { problem: { id: 1, name: 'Soma', max_source_bytes: 1024, languages: [{ id: 1, name: 'C' }], examples: [] }, capabilities: { can_submit: true } }, { mode: 'problem', problemId: '1' });
    field('language_id', '1'); field('source', '<script>test</script>'); await tick();
    let resolve, count = 0, sent;
    fetch = async (path, options) => { count++; sent = options; return await new Promise(done => resolve = done); };
    submit(); submit(); await tick(); assert.equal(count, 1); assert.equal(document.querySelector('button').disabled, true);
    resolve(new Response(JSON.stringify({ errors: { source: ['Fonte inválida.'] } }), { status: 422, headers: { 'Content-Type': 'application/json' } })); await tick();
    assert.equal(document.querySelector('textarea').value, '<script>test</script>'); assert.equal(document.activeElement.name, 'source');
    assert.equal(document.querySelector('textarea').getAttribute('aria-invalid'), 'true');
    assert.equal(sent.headers['X-CSRF-TOKEN'], 'test-csrf'); assert.ok(sent.headers['Idempotency-Key']);
    assert.equal(document.querySelector('script'), null);
});

test('similarity resets language when problem changes and displays escaped pair names', async () => {
    await mount('Similarity', { problems: [{ id: 1, name: 'Soma', languages: [{ id: 1, name: 'C' }] }, { id: 2, name: 'Rede', languages: [{ id: 2, name: 'Python' }] }], capabilities: { can_start: true }, items: [{ id: 1, status: 'completed', pairs: [{ id: 1, team_a: '<img src=x onerror=alert(1)>', source_a_url: '/submission/1', source_b_url: 'javascript:alert(1)', similarity_score: 88 }] }], meta });
    field('problem_id', '1'); await tick(); field('language_id', '1'); await tick(); field('problem_id', '2'); await tick();
    assert.equal(document.querySelector('[name=language_id]').value, '');
    assert.equal(document.querySelector('img'), null); assert.equal(document.querySelectorAll('a').length, 1);
    assert.match(document.body.textContent, /não aplica penalidades/);
});

test('credential revocation requires explicit confirmation and never sends a request on cancel', async () => {
    const data = { contest: { id: 1, name: 'Maratona' }, contests: [], capabilities: { can_manage_credentials: true }, items: [{ id: 7, label: 'Cerimônia', status: 'active' }], meta };
    await mount('Webcast', data); let calls = [];
    fetch = async (path, options) => { calls.push([path, options.method]); return envelope(options.method === 'DELETE' ? { revoked: true } : { ...data, items: [] }); };
    const button = [...document.querySelectorAll('button')].find(b => b.textContent.startsWith('Revogar')); button.click(); await tick();
    [...document.querySelectorAll('button')].find(b => b.textContent === 'Cancelar').click(); await tick(); assert.equal(calls.length, 0);
    button.click(); await tick(); [...document.querySelectorAll('button')].find(b => b.textContent === 'Confirmar revogação').click(); await tick();
    assert.deepEqual(calls[0], ['/api/frontend/webcast/credentials/7', 'DELETE']); assert.match(document.body.textContent, /Credencial revogada/);
});

test('bank ownership remains independent of tags and read-only rows expose no edit action', async () => {
    await mount('BankGovernance', { organizations: [], items: [{ id: 1, name: 'Soma', tags: ['admin'], capabilities: { can_edit: false } }], meta });
    assert.match(document.body.textContent, /Somente leitura/); assert.match(document.body.textContent, /Sem organização atribuída/);
    assert.equal([...document.querySelectorAll('button')].some(b => b.textContent.startsWith('Organizar')), false);
});

test('managed-account creation submits private enrollment without publishing personal details', async () => {
    await mount('ManagedAccounts', { contests: [{ id: 1, name: 'Maratona', sites: [{ id: 2, name: 'Escola' }] }], capabilities: { can_create: true }, items: [{ id: 1, fullname: 'Aluno', username: 'aluno', privacy_locked: true, visibility: 'private', birthdate: '2012-03-04' }], meta });
    assert.doesNotMatch(document.body.textContent, /2012-03-04/);
    field('fullname', 'Aluno novo'); field('username', 'novo'); field('birthdate', '2011-10-10'); field('contest_id', '1'); await tick(); field('site_id', '2');
    let sent; fetch = async (path, options) => { if (options.method === 'POST') { sent = JSON.parse(options.body); return envelope({ id: 2 }, 201); } return envelope({ contests: [], items: [], capabilities: {}, meta }); };
    submit(); await tick(); assert.equal(String(sent.site_id), '2'); assert.equal(sent.birthdate, '2011-10-10'); assert.equal('visibility' in sent, false);
    assert.match(document.body.textContent, /Conta gerenciada criada/);
});

test('judging health describes actual watchdog availability and distinguishes retry from verdict', async () => {
    await mount('JudgingHealth', { watchdog: { enabled: false }, summary: { overdue: 1 }, items: [{ id: 8, recovery_status: 'retrying', retry_count: 1, detail_url: '/submission/8' }], meta });
    assert.match(document.body.textContent, /Recuperação automática indisponível/); assert.match(document.body.textContent, /Retentativa em andamento/); assert.match(document.body.textContent, /1 de 1/);
});

// Issue #390 (P2): Scratch's .sb3 is a file, not text. The page offers the
// upload only for the language that needs it, and sends it as multipart.
test('practice offers a file upload for Scratch and keeps the editor for text languages', async () => {
    await mount('Practice', { problem: { id: 1, name: 'Soma', max_source_bytes: 1024, languages: [{ id: 1, name: 'C', source_kind: 'text', accept: null }, { id: 9, name: 'Scratch', source_kind: 'file', accept: '.sb3' }], examples: [] }, capabilities: { can_submit: true } }, { mode: 'problem', problemId: '1' });
    field('language_id', '1'); field('source', 'int main() {}'); await tick();
    assert.ok(document.querySelector('textarea[name=source]')); assert.equal(document.querySelector('input[type=file]'), null);

    field('language_id', '9'); await tick();
    const input = document.querySelector('input[type=file][name=source_file]');
    assert.ok(input, 'Scratch sem campo de arquivo'); assert.equal(input.getAttribute('accept'), '.sb3');
    assert.equal(document.querySelector('textarea'), null);

    // Too large: refused in the browser, nothing sent.
    let sent = null; fetch = async (path, options) => { sent = [path, options]; return envelope({ id: 5, status: 'pending' }, 202); };
    Object.defineProperty(input, 'files', { configurable: true, value: [new File([new Uint8Array(2048)], 'grande.sb3')] });
    input.dispatchEvent(new Event('change', { bubbles: true })); await tick();
    submit(); await tick();
    assert.equal(sent, null); assert.match(document.body.textContent, /limite é 1024/);

    const project = new File([new Uint8Array([0x50, 0x4b, 0x03, 0x04, 0x00])], 'projeto.sb3');
    Object.defineProperty(input, 'files', { configurable: true, value: [project] });
    input.dispatchEvent(new Event('change', { bubbles: true })); await tick();
    submit(); await tick();
    assert.equal(sent[0], '/api/frontend/practice/problems/1/runs');
    assert.ok(sent[1].body instanceof FormData, 'o projeto nao foi como multipart');
    assert.equal(sent[1].body.get('language_id'), '9'); assert.equal(sent[1].body.get('source_file').name, 'projeto.sb3');
    assert.equal(sent[1].headers['Content-Type'], undefined, 'o navegador escreve o boundary; forcar JSON quebraria o upload');
    assert.ok(sent[1].headers['Idempotency-Key']);

    // Back to C: the editor returns with what was typed.
    field('language_id', '1'); await tick();
    assert.equal(document.querySelector('textarea').value, 'int main() {}');
});

test('ambiguous network failure reuses idempotency key for the same practice submission', async () => {
    await mount('Practice', { problem: { max_source_bytes: 1024, languages: [{ id: 1, name: 'C' }], examples: [] }, capabilities: { can_submit: true } }, { mode: 'problem', problemId: '1' });
    field('language_id', '1'); field('source', 'int main() {}'); await tick();
    const keys = []; fetch = async (path, options) => { keys.push(options.headers['Idempotency-Key']); throw new TypeError('network'); };
    submit(); await tick(); submit(); await tick();
    assert.equal(keys.length, 2); assert.equal(keys[0], keys[1]);
    assert.equal(document.querySelector('textarea').value, 'int main() {}');
});

test('bank publication waits for confirmation and sends the reviewed version', async () => {
    const item = { id: 7, name: 'Soma', tags: [], version: 'v1', practice_status: 'unpublished', capabilities: { can_edit: true, can_transfer: false, can_publish: true } };
    await mount('BankGovernance', { organizations: [], items: [item], meta });
    const calls = []; fetch = async (path, options) => { calls.push([path, options]); return envelope(options.method === 'POST' ? { published: true } : { organizations: [], items: [{ ...item, practice_status: 'published' }], meta }); };
    [...document.querySelectorAll('button')].find(b => b.textContent.startsWith('Publicar para treino')).click(); await tick(); assert.equal(calls.length, 0);
    [...document.querySelectorAll('button')].find(b => b.textContent === 'Confirmar publicação').click(); await tick();
    assert.deepEqual(JSON.parse(calls[0][1].body), { published: true, version: 'v1' });
    assert.match(document.body.textContent, /Problema publicado no Treino Livre/);
});

test('new webcast secret is shown only after confirmed creation and can be removed from DOM', async () => {
    const data = { contest: { id: 1, name: 'Maratona' }, contests: [{ id: 1, name: 'Maratona' }], items: [], capabilities: { can_manage_credentials: true }, meta };
    await mount('Webcast', data); field('label', 'Cerimônia'); field('expires_at', '2027-09-10T15:00');
    fetch = async (path, options) => envelope(options.method === 'POST' ? { id: 1, secret: 'test-only-secret' } : data, options.method === 'POST' ? 201 : 200);
    submit(document.querySelectorAll('form')[1]); await tick();
    assert.equal(document.querySelector('input[readonly]').value, 'test-only-secret');
    [...document.querySelectorAll('button')].find(b => b.textContent === 'Já guardei, ocultar segredo').click(); await tick();
    assert.equal(document.querySelector('input[readonly]'), null);
});

/*
 * Issue #144. jsdom has no 2d canvas context, so chart.js cannot draw here --
 * which is exactly the point of the assertion: the report must still say
 * everything it has to say in text. That is not only a test convenience, it
 * is the requirement that nothing depend on colour alone, and the reason the
 * chart is drawn inside a try/catch.
 */
test('report screens state every charted number in text, so a chart that cannot draw loses nothing', async () => {
    const site = fixtures['/api/frontend/reports/site'];
    await mount('SiteReport', site);
    // The per-problem cut, including the problem nobody solved.
    assert.match(document.body.textContent, /Ninguém resolveu/);
    assert.match(document.body.textContent, /Sede Centro/);
    // The freeze is stated out loud rather than left for the reader to guess.
    assert.match(document.body.textContent, /placar público está congelado/);
    // Every chart carries its own table of the same numbers.
    const tables = document.querySelectorAll('.report-chart-data table');
    assert.equal(tables.length, 5);
    assert.match(tables[0].textContent, /15 min/);
    // The site picker offers only what the server allowed.
    assert.equal(document.querySelectorAll('select[name="site_id"] option').length, 1);
});

test('judge history names the judge, the team and the wait, and keeps the machine separate', async () => {
    await mount('JudgeHistory', fixtures['/api/frontend/reports/judge-history']);
    assert.match(document.body.textContent, /Ana Juíza/);
    assert.match(document.body.textContent, /Equipe Alfa/);
    // The verdict is readable as text, not only as a coloured badge.
    assert.match(document.body.textContent, /WA — Wrong Answer/);
    // Automatic judgments are counted apart from the person's total.
    assert.match(document.body.textContent, /Automáticos/);
    assert.equal(document.querySelector('a[href="/submission/101"]').textContent, '#17');
});

test('failed refresh keeps the last view and forbids mutations until recovery', async () => {
    const listing = { items: [], meta, capabilities: { can_submit: true }, problem: { name: 'Soma', statement: 'Some dois números', max_source_bytes: 1000, languages: [{ id: 1, name: 'C' }] } };
    await mount('Practice', listing, { mode: 'problem', problemId: '1' });
    field('source', 'int main() {}'); field('language_id', '1');
    fetch = async () => { throw new Error('offline'); };
    window.dispatchEvent(new window.PopStateEvent('popstate')); await tick();
    assert.equal(document.querySelector('[name=source]').value, 'int main() {}');
    assert.match(document.body.textContent, /última consulta concluída/);
    assert.equal(document.querySelector('button[type=submit], form button').disabled, true);
    const leaving = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(leaving);
    assert.equal(leaving.defaultPrevented, true);
    fetch = async () => envelope(listing);
    [...document.querySelectorAll('button')].find(button => /Tentar novamente/.test(button.textContent)).click(); await tick();
    assert.equal(document.querySelector('[name=source]').value, 'int main() {}');
});

test('explicit searches add history entries and popstate restores the submitted search', async () => {
    await mount('Practice', { items: [], meta });
    const originalLength = history.length;
    field('q', 'grafos'); submit(); await tick();
    assert.equal(history.length, originalLength + 1);
    history.replaceState(null, '', '/practice?q=aritmetica');
    window.dispatchEvent(new window.PopStateEvent('popstate')); await tick();
    assert.equal(document.querySelector('[name=q]').value, 'aritmetica');
});

// Issue #396 -- habilidades de currículo oficial (docs/specs/396-curriculos-oficiais.md).
test('practice problem lists its curriculum skills, each linking to the library filtered by that code', async () => {
    const skill = { id: 3, code: 'EF06CO02', text: 'Elaborar algoritmos usando uma linguagem de programação.', stage: '6º ano', axis: 'Pensamento Computacional', framework: { slug: 'bncc-computacao', name: 'BNCC Computação' } };
    await mount('Practice', { problem: { id: 1, name: 'Soma', max_source_bytes: 1024, languages: [], examples: [], skills: [skill] }, capabilities: { can_submit: false } }, { mode: 'problem', problemId: '1' });
    assert.match(document.body.textContent, /Habilidades de currículo/);
    assert.match(document.body.textContent, /Elaborar algoritmos/);
    assert.match(document.body.textContent, /BNCC Computação · 6º ano · Pensamento Computacional/);
    assert.equal(document.querySelector('a[href="/practice?skill=EF06CO02"]').textContent.startsWith('EF06CO02'), true);
});

test('practice library sends the skill filter from the URL and can drop it', async () => {
    history.replaceState(null, '', '/practice?skill=EF06CO02');
    const calls = []; globalThis.fetch = async path => { calls.push(path); return envelope({ items: [{ id: 1, short_name: 'A', name: 'Soma', summary: '', tags: [], skills: ['EF06CO02'], solved: false, stats: null }], meta }); };
    app = createApp(components.Practice); app.mount('#app'); await tick();
    assert.equal(calls[0], '/api/frontend/practice/problems?skill=EF06CO02');
    assert.match(document.body.textContent, /associados à habilidade EF06CO02/);
    [...document.querySelectorAll('button')].find(b => b.textContent === 'Remover filtro de habilidade').click(); await tick();
    assert.equal(calls[1], '/api/frontend/practice/problems?page=1');
    assert.equal(new URL(location.href).searchParams.has('skill'), false);
});

test('bank edit sends the chosen outcome ids only once the curricula have loaded', async () => {
    const item = { id: 7, name: 'Soma', tags: [], outcomes: [{ id: 1, code: 'EF06CO02', text: 'Elaborar algoritmos.' }], version: '4', practice_status: 'unpublished', capabilities: { can_edit: true, can_transfer: false, can_publish: false } };
    const frameworks = [{ id: 1, slug: 'bncc-computacao', name: 'BNCC Computação', outcomes: [{ id: 1, code: 'EF06CO02', text: 'Elaborar algoritmos.', stage: '6º ano', axis: 'Pensamento Computacional' }, { id: 2, code: 'EF07CO02', text: 'Analisar programas para detectar e remover erros.', stage: '7º ano', axis: 'Pensamento Computacional' }] }];
    await mount('BankGovernance', { organizations: [], items: [item], meta });
    const calls = []; fetch = async (path, options) => { calls.push([path, options]); if (path === '/api/frontend/curricula') return envelope({ frameworks }); return envelope(options?.method === 'PATCH' ? { id: 7, version: '5' } : { organizations: [], items: [item], meta }); };
    [...document.querySelectorAll('button')].find(b => b.textContent.startsWith('Organizar')).click(); await tick();
    assert.equal(document.getElementById('outcome-1').checked, true);
    field('outcome_ids', 'remover erros'); await tick();
    assert.ok(document.getElementById('outcome-1'), 'selected skills stay visible while searching');
    document.getElementById('outcome-2').click(); await tick();
    submit(document.querySelector('.feature-form')); await tick();
    const patch = calls.find(([, options]) => options?.method === 'PATCH');
    assert.deepEqual(JSON.parse(patch[1].body).outcome_ids, [1, 2]);
});

test('bank edit leaves outcome_ids out when the curricula could not load', async () => {
    const item = { id: 7, name: 'Soma', tags: [], outcomes: [{ id: 1, code: 'EF06CO02', text: 'x' }], version: '4', practice_status: 'unpublished', capabilities: { can_edit: true, can_transfer: false, can_publish: false } };
    await mount('BankGovernance', { organizations: [], items: [item], meta });
    const calls = []; fetch = async (path, options) => { calls.push([path, options]); if (path === '/api/frontend/curricula') return new Response('', { status: 503 }); return envelope(options?.method === 'PATCH' ? { id: 7, version: '5' } : { organizations: [], items: [item], meta }); };
    [...document.querySelectorAll('button')].find(b => b.textContent.startsWith('Organizar')).click(); await tick();
    assert.match(document.body.textContent, /As habilidades atuais serão mantidas/);
    submit(document.querySelector('.feature-form')); await tick();
    const patch = calls.find(([, options]) => options?.method === 'PATCH');
    assert.equal('outcome_ids' in JSON.parse(patch[1].body), false);
});
