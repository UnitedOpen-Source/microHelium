<script setup>
/*
 * Issue #144 -- BOCA's src/judge/history.php: what each judge judged.
 *
 * This is the screen you open when a verdict is contested, so the row that
 * matters is "who gave this verdict, to whom, and how long after the
 * submission". The per-judge chart is on top because the first question is
 * usually about a person's whole shift, not one run.
 */
import { ref, computed, watch } from 'vue';
import { useFeature } from './useFeature.js';
import { localUrl } from './api.js';
import FeatureState from './FeatureState.vue';
import FeaturePager from './FeaturePager.vue';
import ReportChart from './ReportChart.vue';

const { data, loading, error, query, load, filter } = useFeature('/api/frontend/reports/judge-history');
const judge = ref(query.judge_id || '');
watch(() => query.judge_id, value => { judge.value = value || ''; });

const duration = seconds => {
    if (seconds == null) return 'Sem dados';
    if (seconds < 60) return `${seconds} s`;
    const minutes = Math.floor(seconds / 60);
    return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
};

const judges = computed(() => data.value?.judges ?? []);

const judgeChart = computed(() => ({
    labels: judges.value.map(row => row.name),
    datasets: [
        { label: 'Aceitas', data: judges.value.map(row => row.accepted), token: '--color-success' },
        { label: 'Não aceitas', data: judges.value.map(row => row.rejected), token: '--color-destructive' },
    ],
}));
</script>

<template>
    <div class="feature-stack">
        <FeatureState :loading="loading" :error="error" @retry="load()" />
        <template v-if="data && !error">
            <div class="feature-message">
                <strong>{{ data.contest?.name }}</strong> — histórico de julgamento.
                <p class="feature-help">
                    Apenas julgamentos feitos por uma pessoa aparecem na lista. Os
                    {{ data.summary?.automatic ?? 0 }} julgamentos automáticos são contados à parte: eles não têm juiz
                    responsável, e somá-los a alguém faria o total dessa pessoa mentir.
                </p>
            </div>

            <dl class="feature-metrics">
                <div class="surface stat-card"><dt>Julgamentos</dt><dd>{{ data.summary?.judged ?? '—' }}</dd></div>
                <div class="surface stat-card"><dt>Por pessoa</dt><dd>{{ data.summary?.manual ?? '—' }}</dd></div>
                <div class="surface stat-card"><dt>Automáticos</dt><dd>{{ data.summary?.automatic ?? '—' }}</dd></div>
                <div class="surface stat-card"><dt>Juízes ativos</dt><dd>{{ data.summary?.judges ?? '—' }}</dd></div>
            </dl>

            <section class="surface feature-panel">
                <h2>Por juiz</h2>
                <div v-if="!judges.length" class="feature-empty">
                    <h3>Nenhum julgamento manual registrado</h3>
                    <p>Enquanto o julgamento automático der conta, esta lista fica vazia.</p>
                </div>
                <template v-else>
                    <ReportChart
                        type="bar"
                        title="Julgamentos por juiz"
                        summary="Quantos vereditos cada juiz deu, separados entre aceitas e não aceitas."
                        unit-label="Juiz"
                        :labels="judgeChart.labels"
                        :datasets="judgeChart.datasets" />
                    <div class="table-scroll">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th scope="col">Juiz</th><th scope="col">Julgamentos</th>
                                    <th scope="col">Aceitas</th><th scope="col">Não aceitas</th>
                                    <th scope="col">Espera mediana</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in judges" :key="row.judge_id">
                                    <th scope="row">{{ row.name }}</th>
                                    <td>{{ row.judged }}</td>
                                    <td>{{ row.accepted }}</td>
                                    <td>{{ row.rejected }}</td>
                                    <td>{{ duration(row.median_delay_seconds) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </section>

            <section class="surface feature-panel">
                <h2>Julgamentos</h2>
                <form class="feature-search" @submit.prevent="filter({ judge_id: judge, page: 1 })">
                    <label>Juiz
                        <select v-model="judge" name="judge_id">
                            <option value="">Todos</option>
                            <option v-for="row in judges" :key="row.judge_id" :value="row.judge_id">{{ row.name }}</option>
                        </select>
                    </label>
                    <button class="button-primary" :disabled="loading">Aplicar filtro</button>
                    <button type="button" class="button-secondary" :disabled="loading" @click="load()">Atualizar</button>
                </form>

                <div v-if="!data.items?.length" class="feature-empty">
                    <h3>Nenhum julgamento neste recorte</h3>
                    <p>Ajuste o filtro de juiz ou atualize a página.</p>
                </div>
                <div v-else class="table-scroll">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th scope="col">Envio</th><th scope="col">Problema</th><th scope="col">Equipe</th>
                                <th scope="col">Sede</th><th scope="col">Veredito</th><th scope="col">Juiz</th>
                                <th scope="col">Espera</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in data.items" :key="item.run_id">
                                <th scope="row">
                                    <a v-if="localUrl(item.detail_url)" :href="localUrl(item.detail_url)">#{{ item.run_number }}</a>
                                    <span v-else>#{{ item.run_number }}</span>
                                </th>
                                <td>{{ item.problem }}</td>
                                <td>{{ item.team }}</td>
                                <td>{{ item.site }}</td>
                                <td>
                                    <!-- Text, not only colour: the specs require the
                                         verdict to be readable without seeing the badge. -->
                                    <span class="feature-badge" :class="item.is_accepted ? 'feature-success' : 'feature-error'">
                                        {{ item.verdict_short }} — {{ item.verdict }}
                                    </span>
                                </td>
                                <td>{{ item.judge }}</td>
                                <td>{{ duration(item.delay_seconds) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <FeaturePager :meta="data.meta" :loading="loading" @page="filter({ page: $event })" />
            </section>
        </template>
    </div>
</template>
