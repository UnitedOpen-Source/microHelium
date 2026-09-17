import { createApp } from 'vue';
import { initializeUI } from './ui';

// Import components
import ContestTimer from './components/ContestTimer.vue';
import ThemeToggle from './components/ThemeToggle.vue';

/*
 * Issue #144 found this dead in a built deployment.
 *
 * This loop used to read each island element's outerHTML and hand it to
 * createApp() as a `template` string. A string template is compiled at
 * RUNTIME, and Vue compiles it with `new Function` -- which issue #143's CSP
 * (script-src 'self' plus a per-request nonce, no 'unsafe-eval') refuses.
 * The mount threw an EvalError, and because the throw happened inside this
 * module's top level it took everything after it down with it:
 * initializeUI() never ran and the feature-page mount below never ran. Every
 * page in the application renders <contest-timer> or <theme-toggle> from the
 * layout, so this fired on every page load. Verified in a browser against a
 * production build, not reasoned about.
 *
 * The islands never actually needed a compiler. Every use of them in
 * resources/views is a bare, attribute-free element -- <contest-timer>
 * </contest-timer> -- so the element names a component and nothing more.
 * Mounting the component directly is what the markup already meant, and it
 * uses only build-time compiled render functions, which the CSP is happy
 * with. vite.config.js no longer aliases `vue` to the compiler-carrying
 * esm-bundler build either. That does NOT make a reintroduced string
 * template fail at build time -- measured, and the build emits neither an
 * error nor a warning; the component simply renders nothing. What it buys
 * is that the failure stays local instead of throwing at module top level
 * and taking every other island, and initializeUI(), down with it.
 *
 * Since that silence is not a guard, two things watch this:
 * tests/Unit/FrontendCspCompatibilityTest.php asserts statically on the
 * alias and on the absence of a `template` option, and
 * tests/Browser/console.spec.js loads real built pages in a real browser
 * under the real CSP and fails on any console error -- which is the only
 * layer that would have caught the original defect.
 */
/*
 * Issue #246 -- quatro destas ilhas eram codigo morto, e nao uma.
 *
 * `scoreboard`, `run-list`, `submit-form` e `clarification-list` nao
 * apareciam em view nenhuma, e nao dava para simplesmente comecar a usa-las:
 * cada uma declara `contestId` (e `problems`, e `languages`) como prop
 * obrigatoria, e o laco abaixo monta SEM PROPS. Escrever
 * <scoreboard></scoreboard> numa view estouraria dentro do componente, o
 * `try/catch` engoliria o erro e removeria o host -- pagina sem o painel e
 * sem dizer nada.
 *
 * O caminho real de cada uma e Blade: scoreboard.blade.php,
 * submissions.blade.php, clarifications.blade.php e exercises/submit.blade.php.
 * As telas novas usam `[data-feature-page]` e `features/mount.js`, que passa
 * props de verdade a partir de data-attributes.
 *
 * As duas que sobram sao usadas: <contest-timer> em 4 views, <theme-toggle>
 * em 3. Ambas sem props obrigatorias, que e por que funcionam.
 */
const islands = {
    'contest-timer': ContestTimer,
    'theme-toggle': ThemeToggle,
};

for (const [selector, component] of Object.entries(islands)) {
    for (const element of document.querySelectorAll(selector)) {
        const host = document.createElement('div');
        host.className = 'vue-island';
        element.replaceWith(host);
        // One island failing must never stop the next one, nor the feature
        // page below -- that is precisely how one EvalError blanked the
        // whole application.
        try {
            createApp(component).mount(host);
        } catch {
            host.remove();
        }
    }
}

initializeUI();

if (document.querySelector('[data-feature-page]')) {
    import('./features/mount.js').then(({ mountFeaturePages }) => mountFeaturePages()).catch(() => {
        const host = document.querySelector('[data-feature-page]');
        host.textContent = 'Não foi possível abrir esta página. Atualize para tentar novamente.';
        host.setAttribute('role', 'alert');
    });
}
