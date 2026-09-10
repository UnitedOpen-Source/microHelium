import { afterEach, test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { initializeDialogs } from '../../resources/js/ui/dialogs.js';
import { initializeForms, markFieldError } from '../../resources/js/ui/forms.js';
import { initializeUI } from '../../resources/js/ui.js';

let dom;
function page(html, url = 'https://microhelium.test/') {
    dom = new JSDOM(html, { url, pretendToBeVisual: true });
    for (const key of ['window', 'document', 'MutationObserver', 'CustomEvent', 'location', 'history']) globalThis[key] = dom.window[key];
    globalThis.matchMedia = () => ({ matches: true, addEventListener() {} });
    // jsdom has no layout or native inert; model visibility for keyboard navigation.
    dom.window.HTMLElement.prototype.getClientRects = function () { return this.closest('.hidden,[hidden]') ? [] : [{}]; };
    Object.defineProperty(dom.window.HTMLElement.prototype, 'inert', {
        configurable: true, get() { return this.hasAttribute('inert'); },
        set(value) { this.toggleAttribute('inert', Boolean(value)); },
    });
    return document;
}
afterEach(() => dom?.window.close());
const tick = () => new Promise(resolve => queueMicrotask(resolve));
const key = (value, shiftKey = false) => document.activeElement.dispatchEvent(new window.KeyboardEvent('keydown', { key: value, shiftKey, bubbles: true, cancelable: true }));
const submit = form => {
    const event = new window.SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: form.querySelector('button[type=submit]') });
    form.dispatchEvent(event); return event;
};

test('nested modal isolates background, traps both directions and restores focus/inert on Escape', async () => {
    page(`<header><a href="/">Home</a></header><aside inert>Already unavailable</aside><main><button id="open" onclick="openModal('createModal')">Create</button><div id="createModal" class="hidden"><h2>Create user</h2><input id="name"><button onclick="closeModal('createModal')">Cancel</button></div></main>`);
    initializeDialogs();
    const trigger = document.getElementById('open'); trigger.focus(); trigger.click();
    const dialog = document.getElementById('createModal');
    assert.equal(dialog.getAttribute('aria-labelledby'), 'createModal-title');
    assert.equal(document.querySelector('header').inert, true);
    assert.equal(document.querySelector('main').inert, false);
    assert.equal(document.activeElement.id, 'name');
    key('Tab', true); assert.equal(document.activeElement.textContent, 'Cancel');
    key('Tab'); assert.equal(document.activeElement.id, 'name');
    key('Escape'); await tick();
    assert.equal(document.activeElement, trigger);
    assert.equal(document.querySelector('header').inert, false);
    assert.equal(document.querySelector('aside').inert, true);
    assert.equal(document.body.style.overflow, '');
});

test('invalid submission focuses the field, links a useful error and preserves its hint during correction', () => {
    page('<form method="POST"><label for="email">E-mail</label><input id="email" name="email" type="email" required aria-describedby="hint"><p id="hint">Institutional address</p><button type="submit">Save</button></form>');
    initializeForms({});
    const form = document.querySelector('form'), field = document.querySelector('input');
    assert.equal(submit(form).defaultPrevented, true);
    assert.equal(document.activeElement, field);
    assert.equal(field.getAttribute('aria-describedby'), 'hint email-error');
    assert.match(document.getElementById('email-error').textContent, /Preencha/);
    field.value = 'invalid'; field.dispatchEvent(new window.Event('input', { bubbles: true }));
    assert.equal(field.getAttribute('aria-describedby'), 'hint');
    submit(form); assert.match(document.getElementById('email-error').textContent, /e-mail válido/);
});

test('same-named fields in separate row forms receive unique error associations', () => {
    page('<form><input name="answer"></form><form><input name="answer"></form>');
    const fields = document.querySelectorAll('input');
    fields.forEach((field, index) => markFieldError(field, `Error ${index}`));
    assert.notEqual(fields[0].id, fields[1].id);
    fields.forEach((field, index) => assert.equal(document.getElementById(field.getAttribute('aria-describedby')).textContent, `Error ${index}`));
});

test('server validation reopens the relevant dialog with retained input and focuses its error', () => {
    page('<main><div data-error-summary tabindex="-1"><li data-error-field="email">E-mail já cadastrado.</li></div><div id="userModal" class="hidden"><h2>New user</h2><form method="post"><label for="email">E-mail</label><input id="email" name="email" value="existing@example.com"><button type="submit">Save</button></form></div></main>');
    initializeForms(initializeDialogs());
    assert.equal(document.getElementById('userModal').classList.contains('hidden'), false);
    assert.equal(document.activeElement.id, 'email');
    assert.equal(document.activeElement.value, 'existing@example.com');
    assert.equal(document.querySelector('[data-error-summary] a').getAttribute('href'), '#email');
});

test('sending state prevents repeat submits without dropping submitter value, and resets after Back', async () => {
    page('<form method="post"><button type="submit" name="action" value="save">Save</button></form>');
    initializeForms({}); const form = document.querySelector('form'), button = form.querySelector('button');
    assert.equal(submit(form).defaultPrevented, false); await tick();
    assert.equal(form.getAttribute('aria-busy'), 'true');
    assert.equal(button.disabled, false);
    assert.equal(new window.FormData(form, button).get('action'), 'save');
    assert.equal(submit(form).defaultPrevented, true);
    window.dispatchEvent(new window.Event('pageshow'));
    assert.equal(button.textContent, 'Save'); assert.equal(form.hasAttribute('aria-busy'), false);
});

test('cancelled confirmation does not leave a form busy', async () => {
    page('<form method="post"><button type="submit">Delete</button></form>');
    initializeForms({}); const form = document.querySelector('form');
    form.addEventListener('submit', event => event.preventDefault()); submit(form); await tick();
    assert.equal(form.hasAttribute('aria-busy'), false);
    assert.equal(form.querySelector('[role=status]'), null);
});

test('filters restore URL state, match accents, announce empty results and preserve unrelated parameters', () => {
    page('<div data-table-filter="problems"><input type="search"><select><option value="">All</option><option value="easy">Easy</option></select><span role="status"></span><button data-filter-clear>Clear</button></div><table id="problems"><tbody><tr data-difficulty="easy"><td>Árvore</td></tr><tr data-difficulty="hard"><td>Rede</td></tr><tr id="problems-empty" hidden><td colspan="1">No results</td></tr></tbody></table>', 'https://microhelium.test/?contest=3&ui-problems=arvore#list');
    initializeUI(); const input = document.querySelector('input');
    assert.equal(input.value, 'arvore');
    assert.equal(document.querySelector('[role=status]').textContent, '1 de 2 registros');
    input.value = 'missing'; input.dispatchEvent(new window.Event('input'));
    assert.equal(document.getElementById('problems-empty').hidden, false);
    assert.equal(new URL(location.href).searchParams.get('contest'), '3');
    assert.equal(location.hash, '#list');
    document.querySelector('[data-filter-clear]').click();
    assert.equal(new URL(location.href).searchParams.has('ui-problems'), false);
    assert.equal(document.querySelector('[role=status]').textContent, '2 de 2 registros');
    history.replaceState(null, '', '?ui-problems=rede'); window.dispatchEvent(new window.Event('popstate'));
    assert.equal(input.value, 'rede'); assert.equal(document.querySelector('[role=status]').textContent, '1 de 2 registros');
});

function wizardPage(error = '') {
    page(`${error}<p id="wizard-status"></p>${[1,2,3,4,5].map(i => `<span id="step${i}-indicator">${i}</span><span>Step</span>`).join('')}
      <form method="post" id="wizardForm" novalidate>
        <section id="step1" class="wizard-step"><h2>Informações</h2><label for="contestName">Nome</label><input id="contestName" name="name" required><input id="contestPenalty" value="20"><input id="contestMaxFile" value="100"></section>
        <section id="step2" class="wizard-step hidden"><h2>Agenda</h2><input id="contestStart" name="start_time" type="datetime-local" value="2027-02-15T13:30" required><input id="contestDuration" value="300"><input id="contestFreeze" value="60"><span id="calculatedEnd"></span></section>
        <section id="step3" class="wizard-step hidden"><h2>Linguagens</h2><label class="language-card"><input type="checkbox" name="languages[]" value="cpp">C++</label><span id="selectedLangCount"></span></section>
        <section id="step4" class="wizard-step hidden"><h2>Problemas</h2><label class="problem-card" data-difficulty="easy"><input type="checkbox" name="problems[]" value="1">Soma</label><span id="selectedProblemCount"></span></section>
        <section id="step5" class="wizard-step hidden"><h2>Confirmar</h2>${['Name','Start','Duration','Penalty','Languages','Problems'].map(id => `<span id="summary${id}"></span>`).join('')}</section>
        <button type="button" id="prevBtn" data-wizard-action="prevStep">Anterior</button><button type="button" id="nextBtn" data-wizard-action="nextStep">Próximo</button><button id="submitBtn" type="submit">Criar</button>
      </form>`);
    initializeUI();
}

test('wizard preserves submitted date, validates each step and selects languages through native labels', () => {
    wizardPage();
    assert.equal(document.getElementById('contestStart').value, '2027-02-15T13:30');
    const next = document.getElementById('nextBtn'); next.click();
    assert.equal(document.activeElement.id, 'contestName');
    assert.match(document.querySelector('[data-step-error]').textContent, /Preencha o campo Nome/);
    document.getElementById('contestName').value = 'Maratona'; next.click(); next.click();
    assert.equal(document.getElementById('wizard-status').textContent, 'Etapa 3 de 5');
    next.click(); assert.match(document.querySelector('#step3 [role=alert]').textContent, /pelo menos uma linguagem/);
    document.querySelector('.language-card').click();
    assert.equal(document.querySelector('.language-card input').checked, true);
    assert.equal(document.getElementById('selectedLangCount').textContent, '1');
    assert.equal(document.querySelector('.language-card').classList.contains('selected'), true);
    next.click(); next.click();
    assert.equal(document.getElementById('summaryName').textContent, 'Maratona');
    assert.equal(document.getElementById('summaryLanguages').textContent, 'CPP');
    assert.equal(submit(document.querySelector('form')).defaultPrevented, false);
});

test('wizard reveals a hidden step when the server returns an error for its field', () => {
    wizardPage('<div data-error-summary tabindex="-1"><li data-error-field="start_time">Data inválida.</li></div>');
    assert.equal(document.getElementById('step2').classList.contains('hidden'), false);
    assert.equal(document.activeElement.id, 'contestStart');
    assert.equal(document.getElementById('wizard-status').textContent, 'Etapa 2 de 5');
});

test('clarification status filters expose pressed state, empty feedback and restore from URL', () => {
    page('<div data-status-filter="questions"><button data-status="all">Todas</button><button data-status="pending">Pendentes</button><button data-status="answered">Respondidas</button><p role="status"></p></div><div id="questions"><article data-status="answered">Resposta</article><p data-filter-empty hidden>Nenhuma pergunta</p></div>', 'https://microhelium.test/?ui-questions=pending&contest=3');
    initializeUI();
    assert.equal(document.querySelector('article').hidden, true);
    assert.equal(document.querySelector('[data-filter-empty]').hidden, false);
    assert.equal(document.querySelector('button[data-status=pending]').getAttribute('aria-pressed'), 'true');
    document.querySelector('button[data-status=answered]').click();
    assert.equal(document.querySelector('article').hidden, false);
    assert.equal(document.querySelector('[role=status]').textContent, '1 de 1 perguntas');
    assert.equal(new URL(location.href).searchParams.get('contest'), '3');
    assert.equal(new URL(location.href).searchParams.get('ui-questions'), 'answered');
});
