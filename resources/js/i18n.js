/*
 * Issue #397 -- a traducao no Vue le os mesmos arquivos do Laravel.
 *
 * O layout poe no HTML o catalogo do idioma da pagina
 * (resources/lang/frontend/<idioma>.json) dentro de
 * <script type="application/json" id="i18n-catalog">. Um JSON nao e
 * executado, entao a CSP da #143 nao se importa, e nao ha nada a compilar
 * -- por isso nao `vue-i18n` (a comparacao medida esta em
 * docs/specs/397-interface-traduzivel.md).
 *
 * A chave e o texto em pt_BR, como no `__()` do Laravel: sem catalogo (pagina
 * em portugues, teste em Node, catalogo quebrado), `t()` devolve a propria
 * chave, que e o texto certo em portugues.
 */
let catalog = null;

function load() {
    if (catalog !== null) return catalog;
    catalog = {};
    try {
        const element = typeof document === 'undefined' ? null : document.getElementById('i18n-catalog');
        const parsed = element ? JSON.parse(element.textContent || '{}') : {};
        if (parsed && typeof parsed === 'object' && ! Array.isArray(parsed)) catalog = parsed;
    } catch {
        // Catalogo ilegivel: a interface continua em portugues, que e melhor
        // do que ilha nenhuma.
    }
    return catalog;
}

/**
 * Traduz `key`, substituindo `:nome` pelos valores de `replacements` -- a
 * mesma sintaxe do `__()` do Laravel, para que a mesma chave sirva aos dois.
 */
export function t(key, replacements = {}) {
    let text = typeof load()[key] === 'string' ? load()[key] : key;
    // Nomes mais longos primeiro, para `:minutes` nao ser comido por `:min`.
    const names = Object.keys(replacements).sort((a, b) => b.length - a.length);
    for (const name of names) text = text.split(`:${name}`).join(String(replacements[name]));
    return text;
}

/** So para testes: esquece o catalogo lido, para ler o proximo. */
export function resetCatalog() {
    catalog = null;
}
