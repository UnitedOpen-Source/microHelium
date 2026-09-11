import './vue-loader.js';
import test, { afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
const dom = new JSDOM('<!doctype html><html><head><meta name="csrf-token" content="test-csrf"></head><body><main id="app"></main></body></html>', { url: 'https://microhelium.test/practice' });
for (const key of ['window', 'document', 'location', 'history', 'Element', 'HTMLElement', 'Document', 'SVGElement', 'Node', 'Event']) globalThis[key] = dom.window[key];
const { createApp, nextTick } = await import('vue');
const { request, localUrl } = await import('../../resources/js/features/api.js');
const components = Object.fromEntries(await Promise.all(['Practice', 'Similarity', 'Webcast', 'JudgingHealth', 'BankGovernance', 'ManagedAccounts'].map(async name => [name, (await import(`../../resources/js/features/${name}.vue`)).default])));
const tick = async () => { await new Promise(resolve => setTimeout(resolve, 0)); await nextTick(); };
const envelope = (data, status = 200) => new Response(JSON.stringify({ data }), { status, headers: { 'Content-Type': 'application/json' } });
let app;
afterEach(() => { app?.unmount(); app = null; document.body.innerHTML = '<main id="app"></main>'; history.replaceState(null, '', '/practice'); });
async function mount(name, data, props = {}) { globalThis.fetch = async () => envelope(data); app = createApp(components[name], props); app.mount('#app'); await tick(); }
function field(name, value) { const input = document.querySelector(`[name="${name}"]`); assert.ok(input, name); input.value = value; input.dispatchEvent(new Event(input.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); return input; }
function submit(form = document.querySelector('form')) { form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); }
const meta = { current_page: 1, last_page: 1, total: 0 };

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
