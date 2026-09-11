<script setup>
import { ref } from 'vue';
import { useFeature } from './useFeature.js';
import { dateTime, localUrl } from './api.js';
import FeatureState from './FeatureState.vue';
import FeaturePager from './FeaturePager.vue';
const { data, loading, error, query, load, filter } = useFeature('/api/frontend/judging-health');
const status = ref(query.status || '');
const statuses = { overdue: 'Atrasada', retrying: 'Retentativa em andamento', recovered: 'Recuperada', failed: 'Erro no julgamento', pending: 'Aguardando', judging: 'Em avaliação' };
</script>
<template>
    <div class="feature-stack"><FeatureState :loading="loading" :error="error" @retry="load()" />
        <template v-if="data && !error">
            <div class="feature-message"><strong>{{ data.watchdog?.enabled ? 'Recuperação automática ativa.' : 'Recuperação automática indisponível.' }}</strong> {{ data.watchdog?.enabled ? 'Envios sem atividade podem receber uma nova tentativa. O limite é uma retentativa por envio.' : 'Acompanhe os envios pendentes com a organização.' }}<p class="feature-help">Última verificação: {{ dateTime(data.watchdog?.last_checked_at) }}</p></div>
            <dl class="feature-metrics"><div class="surface stat-card"><dt>Atrasadas</dt><dd>{{ data.summary?.overdue ?? '—' }}</dd></div><div class="surface stat-card"><dt>Em retentativa</dt><dd>{{ data.summary?.retrying ?? '—' }}</dd></div><div class="surface stat-card"><dt>Recuperadas</dt><dd>{{ data.summary?.recovered ?? '—' }}</dd></div><div class="surface stat-card"><dt>Erros no julgamento</dt><dd>{{ data.summary?.failed ?? '—' }}</dd></div></dl>
            <section class="surface feature-panel"><form class="feature-search" @submit.prevent="filter({ status, page: 1 })"><label>Situação<select v-model="status" name="status"><option value="">Todas</option><option v-for="(label, value) in statuses" :key="value" :value="value">{{ label }}</option></select></label><button class="button-primary" :disabled="loading">Aplicar filtro</button><button type="button" class="button-secondary" :disabled="loading" @click="load()">Atualizar</button></form>
                <p class="feature-help">Atualizado em {{ dateTime(data.generated_at) }}. Os totais representam os envios do escopo autorizado.</p>
                <div v-if="!data.items?.length" class="feature-empty"><h2>Nenhum envio nesta situação</h2><p>Novos eventos aparecerão aqui após a próxima atualização.</p></div>
                <article v-for="run in data.items" :key="run.id" class="feature-record"><div class="feature-heading"><h2>Envio #{{ run.id }} · {{ run.problem_name }}</h2><span class="feature-badge" :class="{ 'feature-error': run.recovery_status === 'failed', 'feature-success': run.recovery_status === 'recovered' }">{{ statuses[run.recovery_status] || 'Estado desconhecido' }}</span></div><p class="feature-help">{{ run.team_name }} · {{ run.site_name }} · Limite de espera: {{ run.max_wait_seconds }} s</p><dl class="feature-details"><div><dt>Última atividade</dt><dd>{{ dateTime(run.last_activity_at) }}</dd></div><div><dt>Retentativas</dt><dd>{{ run.retry_count }} de 1</dd></div><div><dt>Próxima verificação</dt><dd>{{ dateTime(run.next_check_at) }}</dd></div></dl><p v-if="run.message">{{ run.message }}</p><a v-if="localUrl(run.detail_url)" :href="localUrl(run.detail_url)" class="button-secondary">Ver envio #{{ run.id }}</a></article>
                <FeaturePager :meta="data.meta" :loading="loading" @page="filter({ page: $event })" />
            </section>
        </template>
    </div>
</template>
