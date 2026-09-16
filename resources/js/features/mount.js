import { createApp } from 'vue';
const pages = {
    similarity: () => import('./Similarity.vue'),
    practice: () => import('./Practice.vue'),
    webcast: () => import('./Webcast.vue'),
    'judging-health': () => import('./JudgingHealth.vue'),
    'bank-governance': () => import('./BankGovernance.vue'),
    'managed-accounts': () => import('./ManagedAccounts.vue'),
    // Issue #144. Lazy like the rest: chart.js only reaches the browser of
    // someone who actually opens a report screen.
    'site-report': () => import('./SiteReport.vue'),
    'judge-history': () => import('./JudgeHistory.vue'),
    // Issue #53, fase 4. O contrato esta em
    // docs/specs/53-judge-management.md.
    'judge-machines': () => import('./JudgeMachines.vue'),
    // Issue #188/#233. A #46 entregou a governanca do banco e nenhum jeito
    // de criar a organizacao que ela pede -- o seletor de proprietario
    // abria vazio. docs/specs/188-organizacoes.md.
    organizations: () => import('./Organizations.vue'),
};
export async function mountFeaturePages() {
    for (const element of document.querySelectorAll('[data-feature-page]')) {
        const loader = pages[element.dataset.featurePage];
        if (!loader) continue;
        try {
            const { default: component } = await loader();
            createApp(component, { mode: element.dataset.mode, problemId: element.dataset.problemId }).mount(element);
        } catch {
            element.textContent = 'Não foi possível abrir esta página. Atualize para tentar novamente.';
            element.setAttribute('role', 'alert');
        }
    }
}
