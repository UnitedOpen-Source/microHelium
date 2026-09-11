<script setup>
import { ref, watch } from 'vue';
import { useFeature } from './useFeature.js';
import { dateTime, localUrl } from './api.js';
import FeatureState from './FeatureState.vue';
import FeaturePager from './FeaturePager.vue';
import FieldError from './FieldError.vue';
const props = defineProps({ mode: { default: 'library' }, problemId: String });
const endpoint = props.mode === 'history' ? '/api/frontend/practice/history' : props.mode === 'problem' ? `/api/frontend/practice/problems/${encodeURIComponent(props.problemId)}` : '/api/frontend/practice/problems';
const { data, loading, error, busy, notice, actionError, load, filter, act, query } = useFeature(endpoint);
const search = ref(query.q || ''), language = ref(''), source = ref('');
const verdicts = { AC: 'Aceita', WA: 'Resposta incorreta', TLE: 'Tempo excedido', MLE: 'Memória excedida', CE: 'Erro de compilação', RE: 'Erro de execução', RTE: 'Erro de execução', PE: 'Erro de apresentação', CS: 'Erro no julgamento', pending: 'Na fila', judging: 'Em avaliação', retrying: 'Retentativa em andamento' };
async function submit(event) {
    if (new TextEncoder().encode(source.value).length > data.value.problem.max_source_bytes) {
        actionError.value = { message: 'O código ultrapassa o tamanho permitido.', errors: { source: ['Reduza o código antes de enviar.'] } }; event.target.elements.source.focus(); return;
    }
    await act(`${endpoint}/runs`, { language_id: language.value, source: source.value }, 'Solução recebida. Consulte o histórico para acompanhar o julgamento.', 'POST', event.target);
}
watch(() => query.q, value => { search.value = value || ''; });
</script>
<template>
    <div class="feature-stack">
        <nav class="feature-actions" aria-label="Treino Livre"><a href="/practice" class="button-secondary" :aria-current="mode === 'library' ? 'page' : undefined">Biblioteca</a><a href="/practice/history" class="button-secondary" :aria-current="mode === 'history' ? 'page' : undefined">Meu histórico</a></nav>
        <FeatureState :loading="loading" :error="error" :busy="busy" :notice="notice" :action-error="actionError" @retry="load()" />
        <template v-if="data && !error">
            <template v-if="mode === 'library'">
                <div class="feature-message">Pratique no seu ritmo. Os envios de treino não alteram o placar das competições.</div>
                <form class="surface feature-panel feature-search" role="search" @submit.prevent="filter({ q: search, page: 1 })"><label>Buscar problemas<input v-model="search" type="search" name="q" maxlength="100" placeholder="Nome ou etiqueta…"></label><button class="button-primary" :disabled="loading">Buscar</button><button type="button" class="button-secondary" :disabled="loading" @click="search = ''; filter({ q: '', page: 1 })">Limpar</button></form>
                <p role="status" class="feature-help">{{ data.meta?.total ?? data.items?.length ?? 0 }} problemas encontrados</p>
                <div v-if="!data.items?.length" class="surface feature-empty"><h2>Nenhum problema encontrado</h2><p>{{ query.q ? 'Tente outro termo ou limpe a busca.' : 'Os problemas aparecerão aqui quando a organização publicar a biblioteca.' }}</p></div>
                <div class="feature-grid"><article v-for="problem in data.items" :key="problem.id" class="surface feature-panel feature-problem">
                    <div class="feature-heading"><span class="eyebrow">{{ problem.short_name }}</span><span class="feature-badge">{{ problem.solved ? 'Resolvido' : 'Para praticar' }}</span></div>
                    <h2><a :href="`/practice/problems/${encodeURIComponent(problem.id)}`">{{ problem.name }}</a></h2>
                    <p class="feature-help">{{ problem.summary }}</p><div class="feature-tags"><span v-for="tag in problem.tags" :key="tag" class="feature-badge">{{ tag }}</span></div>
                    <p class="feature-help">{{ problem.stats ? `${problem.stats.solved_count} de ${problem.stats.participant_count} participantes resolveram` : 'Estatísticas ainda indisponíveis' }}</p>
                    <a :href="`/practice/problems/${encodeURIComponent(problem.id)}`" class="button-secondary">Abrir problema<span class="sr-only">: {{ problem.name }}</span></a>
                </article></div>
                <FeaturePager :meta="data.meta" :loading="loading" @page="filter({ page: $event })" />
            </template>
            <template v-else-if="mode === 'history'">
                <div class="feature-heading"><p class="feature-help">Este histórico é visível apenas para você e para a organização autorizada.</p><button type="button" class="button-secondary" :disabled="loading" @click="load()">Atualizar envios</button></div>
                <div v-if="!data.items?.length" class="surface feature-empty"><h2>Seu primeiro treino começa na biblioteca</h2><p>Escolha um problema e envie uma solução para acompanhar seu progresso aqui.</p><a href="/practice" class="button-primary">Explorar problemas</a></div>
                <div v-else class="surface feature-panel"><article v-for="run in data.items" :key="run.id" class="feature-record"><div class="feature-heading"><h2>{{ run.problem_name }}</h2><span class="feature-badge" :class="{ 'feature-success': run.verdict === 'AC' }">{{ verdicts[run.verdict || run.status] || 'Resultado indisponível' }}</span></div><p class="feature-help">Envio #{{ run.id }} · {{ run.language_name }} · {{ dateTime(run.created_at) }}</p><p v-if="run.recovery_message">{{ run.recovery_message }}</p><a v-if="localUrl(run.detail_url)" :href="localUrl(run.detail_url)" class="button-secondary">Ver envio #{{ run.id }}</a></article></div>
                <FeaturePager :meta="data.meta" :loading="loading" @page="filter({ page: $event })" />
            </template>
            <template v-else-if="data.problem">
                <article class="surface feature-panel feature-statement"><p class="eyebrow">{{ data.problem.short_name }}</p><h2>{{ data.problem.name }}</h2><p class="feature-help">Tempo: {{ data.problem.time_limit_ms }} ms · Memória: {{ data.problem.memory_limit_mb }} MB</p><div class="feature-prose">{{ data.problem.statement }}</div>
                    <section v-for="(example, index) in data.problem.examples" :key="index"><h3>Exemplo {{ index + 1 }}</h3><div class="feature-grid"><div><h4>Entrada</h4><pre>{{ example.input }}</pre></div><div><h4>Saída</h4><pre>{{ example.output }}</pre></div></div></section>
                </article>
                <section class="surface feature-panel"><h2>Enviar solução</h2><p id="source-help" class="feature-help">Cole seu código. Limite de {{ data.problem.max_source_bytes }} bytes. Seu texto será preservado se o envio falhar.</p>
                    <form v-if="data.capabilities?.can_submit" class="feature-form" @submit.prevent="submit">
                        <label>Linguagem<select v-model="language" name="language_id" required :disabled="busy" :aria-invalid="!!actionError?.errors?.language_id" aria-describedby="language_id-error"><option value="">Selecione uma linguagem</option><option v-for="item in data.problem.languages" :key="item.id" :value="item.id">{{ item.name }}</option></select><FieldError :error="actionError" name="language_id" /></label>
                        <label class="feature-wide">Código-fonte<textarea v-model="source" name="source" required rows="14" spellcheck="false" autocapitalize="none" autocomplete="off" :disabled="busy" aria-describedby="source-help source-error" :aria-invalid="!!actionError?.errors?.source"></textarea><FieldError :error="actionError" name="source" /></label>
                        <div class="feature-actions feature-wide"><button class="button-primary" :disabled="busy || loading">Enviar para julgamento</button><a href="/practice/history" class="button-secondary">Acompanhar meus envios</a></div>
                    </form>
                    <p v-else class="feature-message">{{ data.submit_unavailable_reason || 'O envio não está disponível para sua conta neste problema.' }} <a v-if="data.capabilities?.requires_login" href="/login" class="feature-link">Entrar na conta</a></p>
                </section>
            </template>
        </template>
    </div>
</template>
