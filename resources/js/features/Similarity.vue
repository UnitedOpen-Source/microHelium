<script setup>
import { ref, computed } from 'vue';
import { useFeature } from './useFeature.js';
import { localUrl, dateTime, percent } from './api.js';
import FeatureState from './FeatureState.vue';
import FieldError from './FieldError.vue';
import FeaturePager from './FeaturePager.vue';
const state = useFeature('/api/frontend/similarity');
const { data, loading, error, busy, notice, actionError, load, filter, act } = state;
const problem = ref(''), language = ref(''), threshold = ref(80);
const languages = computed(() => data.value?.problems?.find(p => String(p.id) === String(problem.value))?.languages || []);
const statusLabels = { queued: 'Na fila', running: 'Em análise', completed: 'Concluída', failed: 'Falhou' };
async function start(event) {
    const result = await act('/api/frontend/similarity/checks', { problem_id: problem.value, language_id: language.value, threshold: Number(threshold.value) }, 'Análise solicitada. Atualize a lista para acompanhar.', 'POST', event.target);
    if (result) await load();
}
</script>
<template>
    <div class="feature-stack">
        <div class="feature-message"><strong>Compare com contexto.</strong> Similaridade é um indício para revisão humana. O resultado não aplica penalidades automaticamente.</div>
        <FeatureState :loading="loading" :error="error" :busy="busy" :notice="notice" :action-error="actionError" @retry="load()" />
        <template v-if="data && !error">
            <section class="surface feature-panel"><h2>Nova análise</h2><p class="feature-help">Compara a última solução aceita de cada equipe, no mesmo problema e linguagem.</p>
                <form class="feature-form" @submit.prevent="start">
                    <label>Problema<select v-model="problem" name="problem_id" required :disabled="busy" :aria-invalid="!!actionError?.errors?.problem_id" aria-describedby="problem_id-error" @change="language = ''"><option value="">Selecione um problema</option><option v-for="item in data.problems" :key="item.id" :value="item.id">{{ item.contest_name }} · {{ item.name }}</option></select><FieldError :error="actionError" name="problem_id" /></label>
                    <label>Linguagem<select v-model="language" name="language_id" required :disabled="!problem || busy" :aria-invalid="!!actionError?.errors?.language_id" aria-describedby="language_id-error"><option value="">Selecione uma linguagem</option><option v-for="item in languages" :key="item.id" :value="item.id">{{ item.name }}</option></select><FieldError :error="actionError" name="language_id" /></label>
                    <label>Similaridade mínima (%)<input v-model="threshold" name="threshold" type="number" required min="0" max="100" step="1" :disabled="busy" aria-describedby="threshold-help threshold-error" :aria-invalid="!!actionError?.errors?.threshold"><small id="threshold-help">Exibe pares a partir deste percentual.</small><FieldError :error="actionError" name="threshold" /></label>
                    <button class="button-primary" :disabled="busy || loading || !data.capabilities?.can_start || !language">Solicitar análise</button>
                </form>
                <p v-if="!data.capabilities?.can_start" class="feature-help">Seu perfil não pode solicitar novas análises.</p>
            </section>
            <section class="surface feature-panel"><div class="feature-heading"><h2>Análises e resultados</h2><button type="button" class="button-secondary" :disabled="loading || busy" @click="load()">Atualizar resultados</button></div>
                <p v-if="!data.items?.length" class="feature-empty">Nenhuma análise solicitada. Escolha um problema para começar.</p>
                <article v-for="check in data.items" :key="check.id" class="feature-record">
                    <div class="feature-heading"><h3>{{ check.problem_name }} · {{ check.language_name }}</h3><span class="feature-badge">{{ statusLabels[check.status] || 'Estado desconhecido' }}</span></div>
                    <p class="feature-help">{{ check.team_count }} equipes · Solicitada em {{ dateTime(check.created_at) }} · Limite {{ percent(check.threshold) }}</p>
                    <p v-if="check.status === 'failed'" class="feature-error">{{ check.error_message || 'Não foi possível analisar este conjunto. Solicite uma nova análise.' }}</p>
                    <p v-if="check.status === 'queued' || check.status === 'running'" role="status">A análise continua em segundo plano. Você pode sair desta página.</p>
                    <p v-if="check.status === 'completed' && !check.pairs?.length" class="feature-help">Nenhum par acima do limite nesta análise.</p>
                    <div v-for="pair in check.pairs" :key="pair.id" class="feature-pair">
                        <div><strong>{{ percent(pair.similarity_score) }}</strong><p class="feature-help">Similaridade média</p></div>
                        <a v-if="localUrl(pair.source_a_url)" :href="localUrl(pair.source_a_url)" class="button-secondary">{{ pair.team_a }} · Ver código #{{ pair.run_id_a }}</a>
                        <a v-if="localUrl(pair.source_b_url)" :href="localUrl(pair.source_b_url)" class="button-secondary">{{ pair.team_b }} · Ver código #{{ pair.run_id_b }}</a>
                    </div>
                </article>
                <FeaturePager :meta="data.meta" :loading="loading" @page="filter({ page: $event })" />
            </section>
        </template>
    </div>
</template>
