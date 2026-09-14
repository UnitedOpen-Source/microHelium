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
