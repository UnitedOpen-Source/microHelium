<script setup>
/*
 * Issue #144 -- BOCA's src/staff/report/: one site's submissions, verdicts
 * and timings, with the graphs BOCA draws through libchart drawn here in the
 * browser instead.
 *
 * The site selector only ever offers what the server already decided this
 * account may see (data.scope.sites): for a coordinator that is exactly one
 * entry. The server re-derives the scope on every request, so the selector is
 * presentation, not permission.
 */
import { ref, computed, watch } from 'vue';
import { useFeature } from './useFeature.js';
import { dateTime } from './api.js';
import FeatureState from './FeatureState.vue';
import ReportChart from './ReportChart.vue';

const { data, loading, error, query, load, filter } = useFeature('/api/frontend/reports/site');
const site = ref(query.site_id || '');
watch(() => query.site_id, value => { site.value = value || ''; });

const duration = seconds => {
    if (seconds == null) return 'Sem dados';
    if (seconds < 60) return `${seconds} s`;
    const minutes = Math.floor(seconds / 60);
    return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
};

const rate = value => (value == null ? 'Sem dados' : `${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 }).format(value)}%`);

const verdicts = computed(() => data.value?.verdicts ?? []);
const problems = computed(() => data.value?.problems ?? []);
const timeline = computed(() => data.value?.timeline ?? []);
const delay = computed(() => data.value?.judging_delay ?? []);
const solved = computed(() => data.value?.solved_distribution ?? []);

// Every chart is fed straight from the aggregates; nothing is recomputed
// here, so the table under each chart and the chart itself can never drift.
const verdictChart = computed(() => ({
    labels: verdicts.value.map(v => `${v.short_name} — ${v.name}`),
    datasets: [{ label: 'Submissões', data: verdicts.value.map(v => v.total) }],
}));

const problemChart = computed(() => ({
    labels: problems.value.map(p => p.short_name),
    datasets: [
        { label: 'Submissões', data: problems.value.map(p => p.submissions), token: '--color-info' },
        { label: 'Times que resolveram', data: problems.value.map(p => p.solved_teams), token: '--color-success' },
    ],
}));

const timelineChart = computed(() => ({
    labels: timeline.value.map(point => `${point.minute} min`),
    datasets: [
        { label: 'Submissões', data: timeline.value.map(point => point.submissions), token: '--color-primary' },
        { label: 'Aceitas', data: timeline.value.map(point => point.accepted), token: '--color-success' },
    ],
}));

const delayChart = computed(() => ({
    labels: delay.value.map(bucket => bucket.label),
    datasets: [{ label: 'Julgamentos', data: delay.value.map(bucket => bucket.total), token: '--color-warning' }],
}));

const solvedChart = computed(() => ({
    labels: solved.value.map(row => `${row.solved}`),
    datasets: [{ label: 'Times', data: solved.value.map(row => row.teams), token: '--color-primary' }],
}));
</script>

<template>
    <div class="feature-stack">
        <FeatureState :loading="loading" :error="error" :stale="!!data" @retry="load()" />
        <template v-if="data">
            <div class="feature-message">
                <strong>{{ data.scope?.site_name || 'Todas as sedes' }}</strong> — {{ data.contest?.name }}.
                <p class="feature-help">
                    Atualizado em {{ dateTime(data.generated_at) }}.
                    <template v-if="data.contest?.is_frozen">
                        O placar público está congelado; este relatório <strong>não</strong> está. Ele é material da organização
                        e não deve ser projetado nem repassado às equipes antes do descongelamento.
                    </template>
                    <template v-else>Este relatório mostra os dados sem congelamento.</template>
                </p>
            </div>

            <dl class="feature-metrics">
                <div class="surface stat-card"><dt>Submissões</dt><dd>{{ data.summary?.submissions ?? '—' }}</dd></div>
                <div class="surface stat-card"><dt>Na fila</dt><dd>{{ data.summary?.pending ?? '—' }}</dd></div>
                <div class="surface stat-card"><dt>Aceitas</dt><dd>{{ rate(data.summary?.acceptance_rate) }}</dd></div>
                <div class="surface stat-card"><dt>Julgamento (mediana)</dt><dd>{{ duration(data.summary?.median_delay_seconds) }}</dd></div>
            </dl>

            <section class="surface feature-panel">
                <h2>Recorte</h2>
                <form class="feature-search" @submit.prevent="filter({ site_id: site })">
                    <label>Sede
                        <select v-model="site" name="site_id" :disabled="!data.scope?.can_choose_site">
                            <option v-if="data.scope?.can_choose_site" value="">Todas as sedes</option>
                            <option v-for="option in data.scope?.sites || []" :key="option.id" :value="option.id">{{ option.name }}</option>
                        </select>
                    </label>
                    <button class="button-primary" :disabled="loading">Aplicar</button>
                    <button type="button" class="button-secondary" :disabled="loading" @click="load()">Atualizar</button>
                </form>
                <p v-if="!data.scope?.can_choose_site" class="feature-help">
                    Sua conta está vinculada a uma sede, então este relatório cobre apenas ela.
                </p>
            </section>

            <section class="surface feature-panel">
                <h2>Submissões ao longo da prova</h2>
                <p class="feature-help">
                    Faixas de 15 minutos desde o início. Uma curva que sobe sem que as aceitas acompanhem costuma ser fila de
                    julgamento, não dificuldade do problema.
                </p>
                <ReportChart
                    type="line"
                    title="Submissões por faixa de 15 minutos"
                    summary="Total de submissões e de submissões aceitas, por faixa de 15 minutos da prova."
                    unit-label="Minuto da prova"
                    :labels="timelineChart.labels"
                    :datasets="timelineChart.datasets" />
            </section>

            <section class="surface feature-panel">
                <h2>Problemas</h2>
                <ReportChart
                    type="bar"
                    title="Submissões e acertos por problema"
                    summary="Quantas submissões cada problema recebeu e quantos times o resolveram."
                    unit-label="Problema"
                    :labels="problemChart.labels"
                    :datasets="problemChart.datasets" />
                <div class="table-scroll">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th scope="col">Problema</th><th scope="col">Submissões</th><th scope="col">Aceitas</th>
                                <th scope="col">Times que resolveram</th><th scope="col">Primeiro acerto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="problem in problems" :key="problem.problem_id">
                                <th scope="row">{{ problem.short_name }} — {{ problem.name }}</th>
                                <td>{{ problem.submissions }}</td>
                                <td>{{ problem.accepted }}</td>
                                <td>{{ problem.solved_teams }}</td>
                                <td>{{ problem.first_solve_minute == null ? 'Ninguém resolveu' : `${problem.first_solve_minute} min` }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="surface feature-panel">
                <h2>Vereditos</h2>
                <ReportChart
                    type="doughnut"
                    title="Distribuição de vereditos"
                    summary="Quantas submissões receberam cada veredito, incluindo as que ainda aguardam julgamento."
                    unit-label="Veredito"
                    :labels="verdictChart.labels"
                    :datasets="verdictChart.datasets" />
            </section>

            <section class="surface feature-panel">
                <h2>Tempo de julgamento</h2>
                <p class="feature-help">
                    Intervalo entre o envio e o veredito, medido no relógio da prova. Máximo observado:
                    {{ duration(data.summary?.max_delay_seconds) }}.
                </p>
                <ReportChart
                    type="bar"
                    title="Julgamentos por faixa de espera"
                    summary="Quantos julgamentos ficaram em cada faixa de espera entre o envio e o veredito."
                    unit-label="Faixa de espera"
                    :labels="delayChart.labels"
                    :datasets="delayChart.datasets" />
            </section>

            <section class="surface feature-panel">
                <h2>Problemas resolvidos por time</h2>
                <ReportChart
                    type="bar"
                    title="Times por quantidade de problemas resolvidos"
                    summary="Quantos times terminaram com cada quantidade de problemas resolvidos."
                    unit-label="Problemas resolvidos"
                    :labels="solvedChart.labels"
                    :datasets="solvedChart.datasets" />
            </section>
        </template>
    </div>
</template>
