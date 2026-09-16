import test, { beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://microhelium.test/submit/1' });
for (const key of ['window', 'document', 'Element', 'HTMLElement', 'Document', 'Node', 'Event']) globalThis[key] = dom.window[key];

const { initializeDraftForms } = await import('../../resources/js/ui/draft-form.js');

let releases = [];

/**
 * Monta o formulário de envio com o texto já digitado e liga a guarda.
 */
function mount(code = '') {
    releases.forEach(release => release());
    document.body.innerHTML = `
        <form action="/submit/1" method="POST">
            <textarea name="code_text" data-draft-field>${code}</textarea>
            <input name="source_file" type="file">
            <button type="submit">Enviar</button>
        </form>`;
    releases = initializeDraftForms(document);
    return document.querySelector('form');
}

/**
 * Dispara o beforeunload e diz se o navegador seria instruído a perguntar.
 *
 * É assim que a confirmação de saída funciona: nenhum diálogo é nosso -- o
 * que se controla é o `preventDefault()`, e é isso que se mede.
 */
function wouldAsk() {
    const event = new dom.window.Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);
    return event.defaultPrevented;
}

beforeEach(() => { releases.forEach(release => release()); releases = []; });

test('sair com código digitado pede confirmação', () => {
    const form = mount();
    form.elements.code_text.value = 'int main(){}';

    assert.equal(wouldAsk(), true);
});

test('o formulário vazio não incomoda quem só passou pela página', () => {
    mount();

    assert.equal(wouldAsk(), false);
});

test('só espaço em branco não conta como código', () => {
    const form = mount();
    form.elements.code_text.value = '   \n\t  ';

    assert.equal(wouldAsk(), false);
});

/**
 * "Envio confirmado libera navegação": depois de enviar, a página navega
 * sozinha e uma pergunta ali seria a própria guarda atrapalhando o envio que
 * ela existe para proteger.
 */
test('enviar libera a navegação', () => {
    const form = mount();
    form.elements.code_text.value = 'int main(){}';
    assert.equal(wouldAsk(), true, 'controle: antes de enviar, pergunta');

    form.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));

    assert.equal(wouldAsk(), false);
});

/**
 * O caso que uma comparação com o estado inicial erraria.
 *
 * Depois de um 422 a página volta com o código reposto por `old()`. Quem
 * medisse "mudou desde que carregou" diria "não mudou" -- exatamente quando
 * o código está digitado, não enviado, e a um clique de sumir.
 */
test('código reposto depois de um erro de validação continua protegido', () => {
    mount('int main(){ return 0; }');

    assert.equal(wouldAsk(), true);
});

/**
 * Voltar pelo histórico traz a página do cache com o JavaScript congelado.
 * Sem o reset, `submitted` continuaria verdadeiro e o código que o navegador
 * repõe ficaria desprotegido.
 */
test('voltar pelo histórico depois de enviar volta a proteger', () => {
    const form = mount();
    form.elements.code_text.value = 'int main(){}';
    form.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    assert.equal(wouldAsk(), false, 'controle: enviado, não pergunta');

    const pageshow = new dom.window.Event('pageshow');
    Object.defineProperty(pageshow, 'persisted', { value: true });
    window.dispatchEvent(pageshow);

    assert.equal(wouldAsk(), true);
});

test('um campo sem a marcação não é vigiado', () => {
    releases.forEach(release => release());
    document.body.innerHTML = '<form><textarea name="q">texto</textarea></form>';
    releases = initializeDraftForms(document);

    assert.equal(wouldAsk(), false);
});

/**
 * Um campo marcado fora de qualquer formulário não derruba a inicialização
 * -- e também não ganha guarda, porque não há envio que a libere: a pessoa
 * ficaria presa numa pergunta que nunca para de aparecer.
 */
test('um campo marcado fora de formulário é ignorado sem quebrar', () => {
    releases.forEach(release => release());
    document.body.innerHTML = '<textarea data-draft-field>solto</textarea>';
    releases = initializeDraftForms(document);

    assert.equal(wouldAsk(), false);
});

/**
 * Controle positivo do desligamento: se `release()` não desligasse nada, os
 * testes acima poderiam estar medindo guardas deixadas por testes
 * anteriores, e não a que cada um monta.
 */
test('desligar a guarda para de perguntar', () => {
    const form = mount();
    form.elements.code_text.value = 'int main(){}';
    assert.equal(wouldAsk(), true);

    releases.forEach(release => release());
    releases = [];

    assert.equal(wouldAsk(), false);
});
