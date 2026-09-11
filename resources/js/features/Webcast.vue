<script setup>
import { ref, watch } from 'vue';
import { useFeature } from './useFeature.js';
import { dateTime, localUrl } from './api.js';
import FeatureState from './FeatureState.vue';
import FieldError from './FieldError.vue';
import FeaturePager from './FeaturePager.vue';
const { data, loading, error, busy, notice, actionError, query, load, filter, act } = useFeature('/api/frontend/webcast');
const contest = ref(query.contest_id || ''), label = ref(''), expires = ref(''), secret = ref(null), revoking = ref(null);
async function changeContest() { secret.value = null; revoking.value = null; await filter({ contest_id: contest.value, page: 1 }); }
async function create(event) {
    secret.value = null;
    const result = await act('/api/frontend/webcast/credentials', { contest_id: data.value.contest.id, label: label.value, expires_at: new Date(expires.value).toISOString() }, 'Credencial criada. Guarde o segredo antes de sair desta página.', 'POST', event.target);
    if (result) { secret.value = result.secret; label.value = ''; await load(); }
}
async function revoke(id) {
    const result = await act(`/api/frontend/webcast/credentials/${encodeURIComponent(id)}`, undefined, 'Credencial revogada. Ela não permite mais acessar a transmissão.', 'DELETE');
    if (result) { revoking.value = null; secret.value = null; await load(); }
}
watch(() => query.contest_id, value => { contest.value = value || ''; });
</script>
<template>
    <div class="feature-stack">
        <div class="feature-message"><strong>Placar completo para a premiação.</strong> Quem recebe uma credencial pode ver resultados após o congelamento. Compartilhe apenas com a equipe responsável pela cerimônia.</div>
        <FeatureState :loading="loading" :error="error" :busy="busy" :notice="notice" :action-error="actionError" @retry="load()" />
        <template v-if="data && !error">
            <form class="surface feature-panel feature-search" @submit.prevent="changeContest"><label>Competição<select v-model="contest" name="contest_id" required><option value="">Selecione uma competição</option><option v-for="item in data.contests" :key="item.id" :value="item.id">{{ item.name }}</option></select></label><button class="button-primary" :disabled="loading || busy">Abrir transmissão</button></form>
            <template v-if="data.contest">
                <section class="surface feature-panel"><div class="feature-heading"><div><p class="eyebrow">TRANSMISSÃO</p><h2>{{ data.contest.name }}</h2></div><span class="feature-badge">Placar sem congelamento</span></div>
                    <a v-if="data.capabilities?.can_export && localUrl(data.export_url)" :href="localUrl(data.export_url)" class="button-primary">Baixar webcast ZIP</a><p v-else class="feature-help">A exportação não está disponível para esta competição.</p>
                </section>
                <section class="surface feature-panel"><h2>Nova credencial</h2><p class="feature-help">O segredo será exibido uma única vez. Defina um nome reconhecível e uma data de validade.</p>
                    <form v-if="data.capabilities?.can_manage_credentials" class="feature-form" @submit.prevent="create">
                        <label>Nome da credencial<input v-model="label" name="label" required maxlength="80" :disabled="busy" :aria-invalid="!!actionError?.errors?.label" aria-describedby="label-error"><FieldError :error="actionError" name="label" /></label>
                        <label>Validade (seu horário local)<input v-model="expires" name="expires_at" type="datetime-local" required :disabled="busy" :aria-invalid="!!actionError?.errors?.expires_at" aria-describedby="expires_at-error"><FieldError :error="actionError" name="expires_at" /></label>
                        <button class="button-primary" :disabled="busy || loading">Gerar credencial</button>
                    </form>
                    <div v-if="secret" class="feature-message feature-success"><label class="feature-secret">Segredo da nova credencial<input :value="secret" readonly autocomplete="off" spellcheck="false" aria-describedby="secret-help" @focus="$event.target.select()"></label><p id="secret-help">Selecione e copie para um local seguro. Ele não será exibido novamente.</p><button type="button" class="button-secondary" @click="secret = null">Já guardei, ocultar segredo</button></div>
                </section>
                <section class="surface feature-panel"><div class="feature-heading"><h2>Credenciais emitidas</h2><button type="button" class="button-secondary" :disabled="loading || busy" @click="load()">Atualizar credenciais</button></div><p v-if="!data.items?.length" class="feature-empty">Nenhuma credencial emitida para esta competição.</p>
                    <article v-for="item in data.items" :key="item.id" class="feature-record"><div class="feature-heading"><h3>{{ item.label }}</h3><span class="feature-badge">{{ { active: 'Ativa', expired: 'Expirada', revoked: 'Revogada' }[item.status] || 'Indisponível' }}</span></div><p class="feature-help">Validade: {{ dateTime(item.expires_at) }} · Último acesso: {{ dateTime(item.last_used_at) }}</p>
                        <div v-if="revoking === item.id" class="feature-message"><p>Revogar “{{ item.label }}”? A transmissão que usa esta credencial perderá acesso imediatamente.</p><div class="feature-actions"><button type="button" class="button-danger" :disabled="busy" @click="revoke(item.id)">Confirmar revogação</button><button type="button" class="button-secondary" :disabled="busy" @click="revoking = null">Cancelar</button></div></div>
                        <button v-else-if="item.status === 'active' && data.capabilities?.can_manage_credentials" type="button" class="button-secondary" :disabled="busy" @click="revoking = item.id">Revogar<span class="sr-only"> {{ item.label }}</span></button>
                    </article><FeaturePager :meta="data.meta" :loading="loading" @page="secret = null; filter({ page: $event })" />
                </section>
            </template>
        </template>
    </div>
</template>
