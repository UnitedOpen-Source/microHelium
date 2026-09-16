import { protectDraft } from './draft.js';

/**
 * Issue #224 -- sair de um formulário com código ainda não enviado pede
 * confirmação, e o envio libera a navegação.
 *
 * O editor de treino (`Practice.vue`) já tinha essa proteção. O formulário
 * de envio da PROVA não tinha -- e é nele que a perda dói: durante a
 * competição, uma equipe que cola quarenta linhas e clica em "Voltar ao
 * Problema" para reler o enunciado perde tudo, em silêncio, com o relógio
 * andando.
 *
 * Uma marcação só, `data-draft-field`, e o formulário sai do `closest()`.
 * A primeira versão pedia também um `data-draft-guard` no formulário; uma
 * mutação mostrou que ele não decidia nada -- e duas marcações que precisam
 * concordar são uma armadilha: marcar o campo e esquecer o formulário
 * desligaria a guarda sem erro nenhum.
 */
export function initializeDraftForms(root = document) {
    const byForm = new Map();

    for (const field of root.querySelectorAll('[data-draft-field]')) {
        const form = field.closest('form');

        if (!form) continue;

        byForm.set(form, [...(byForm.get(form) ?? []), field]);
    }

    const releases = [];

    for (const [form, fields] of byForm) {
        let submitted = false;

        // "Tem conteúdo e ainda não foi enviado desta página", e NÃO
        // "mudou desde que carregou".
        //
        // A diferença aparece depois de um 422: a página volta com o código
        // da pessoa reposto por `old()`, e uma comparação com o estado
        // inicial diria "não mudou" -- justamente quando o código está
        // digitado, não enviado, e a um clique de sumir.
        const dirty = () => !submitted && fields.some(field => field.value.trim() !== '');

        releases.push(protectDraft(dirty));

        form.addEventListener('submit', () => { submitted = true; });

        // Voltar pelo histórico traz a página do cache com o estado do
        // JavaScript congelado. Sem isto, `submitted` continuaria verdadeiro
        // e o código reposto pelo navegador ficaria desprotegido.
        window.addEventListener('pageshow', event => { if (event.persisted) submitted = false; });
    }

    return releases;
}
