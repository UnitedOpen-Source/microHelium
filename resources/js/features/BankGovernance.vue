<script setup>
import { ref, nextTick } from 'vue';
import { useFeature } from './useFeature.js';
import FeatureState from './FeatureState.vue';
import FeaturePager from './FeaturePager.vue';
import FieldError from './FieldError.vue';
const { data, loading, error, busy, notice, actionError, load, filter, act, query } = useFeature('/api/frontend/bank-governance');
const publication = ref(null);
const organization = ref(query.organization_id || ''), search = ref(query.q || ''), editing = ref(null), owner = ref(''), tags = ref('');
async function edit(item) { editing.value = item; owner.value = item.owning_org_id ?? ''; tags.value = item.tags.join(', '); actionError.value = null; await nextTick(); document.getElementById('bank-edit-title')?.focus(); }
async function save(event) {
    const result = await act(`/api/frontend/bank-governance/${encodeURIComponent(editing.value.id)}`, { owning_org_id: owner.value || null, tags: [...new Set(tags.value.split(',').map(t => t.trim()).filter(Boolean))], version: editing.value.version }, 'Organização do problema atualizada.', 'PATCH', event.target);
    if (result) { editing.value = null; await load(); }
}
async function publish(item) {
    const result = await act(`/api/frontend/bank-governance/${encodeURIComponent(item.id)}/practice`, { published: item.practice_status !== 'published', version: item.version }, item.practice_status === 'published' ? 'Problema retirado da biblioteca. O histórico foi preservado.' : 'Problema publicado no Treino Livre.');
    if (result) { publication.value = null; await load(); }
}
</script>
<template>
    <div class="feature-stack"><div class="feature-message"><strong>Responsabilidade e assunto são informações diferentes.</strong> A organização proprietária define quem pode editar. Etiquetas ajudam a encontrar problemas e não concedem acesso.</div>
        <FeatureState :loading="loading" :error="error" :busy="busy" :notice="notice" :action-error="actionError" @retry="load()" />
        <template v-if="data && !error">
            <form class="surface feature-panel feature-search" role="search" @submit.prevent="editing = null; filter({ q: search, organization_id: organization, page: 1 })"><label>Buscar no banco<input v-model="search" name="q" type="search" maxlength="100" placeholder="Nome ou etiqueta…"></label><label>Organização proprietária<select v-model="organization" name="organization_id"><option value="">Todas</option><option value="unassigned">Sem organização</option><option v-for="org in data.organizations" :key="org.id" :value="org.id">{{ org.name }}</option></select></label><button class="button-primary" :disabled="busy || loading">Filtrar</button></form>
            <section v-if="editing" class="surface feature-panel"><h2 id="bank-edit-title" tabindex="-1">Organizar: {{ editing.name }}</h2><form class="feature-form" @submit.prevent="save">
                <label>Organização proprietária<select v-model="owner" name="owning_org_id" :disabled="busy || !editing.capabilities?.can_transfer" aria-describedby="owner-help owning_org_id-error" :aria-invalid="!!actionError?.errors?.owning_org_id"><option value="">Sem organização</option><option v-for="org in data.organizations" :key="org.id" :value="org.id">{{ org.name }}</option></select><small id="owner-help">Trocar a organização muda quem pode editar este problema.</small><FieldError :error="actionError" name="owning_org_id" /></label>
                <label>Etiquetas<input v-model="tags" name="tags" maxlength="500" :disabled="busy" aria-describedby="tags-help tags-error" :aria-invalid="!!actionError?.errors?.tags"><small id="tags-help">Separe por vírgulas. Exemplo: grafos, programação dinâmica.</small><FieldError :error="actionError" name="tags" /></label>
                <div class="feature-actions feature-wide"><button class="button-primary" :disabled="busy || loading">Salvar organização</button><button type="button" class="button-secondary" :disabled="busy" @click="editing = null; actionError = null">Cancelar</button></div>
            </form></section>
            <section class="surface feature-panel"><h2>Problemas do banco</h2><p v-if="!data.items?.length" class="feature-empty">Nenhum problema corresponde aos filtros.</p>
                <article v-for="item in data.items" :key="item.id" class="feature-record"><div class="feature-heading"><h3>{{ item.name }}</h3><span class="feature-badge">{{ item.capabilities?.can_edit ? 'Você pode editar' : 'Somente leitura' }}</span></div><p><strong>Organização:</strong> {{ item.organization_name || 'Sem organização atribuída' }}</p><p v-if="!item.owning_org_id" class="feature-help">Problema legado. A atribuição é feita pela administração.</p><div class="feature-tags" aria-label="Etiquetas"><span v-for="tag in item.tags" :key="tag" class="feature-badge">{{ tag }}</span><span v-if="!item.tags.length" class="feature-help">Sem etiquetas</span></div><button v-if="item.capabilities?.can_edit" type="button" class="button-secondary" :disabled="busy" @click="edit(item)">Organizar<span class="sr-only"> {{ item.name }}</span></button>
                    <p class="feature-help">Treino Livre: {{ item.practice_status === 'published' ? 'Publicado' : 'Não publicado' }}</p>
                    <div v-if="publication === item.id" class="feature-message"><p>{{ item.practice_status === 'published' ? 'Retirar este problema da biblioteca? Os envios anteriores serão preservados.' : 'Publicar uma cópia deste problema para treino? O enunciado ficará acessível fora da competição.' }}</p><div class="feature-actions"><button type="button" class="button-primary" :disabled="busy" @click="publish(item)">Confirmar {{ item.practice_status === 'published' ? 'retirada' : 'publicação' }}</button><button type="button" class="button-secondary" :disabled="busy" @click="publication = null">Cancelar</button></div></div>
                    <button v-else-if="item.capabilities?.can_publish" type="button" class="button-secondary" :disabled="busy" @click="publication = item.id">{{ item.practice_status === 'published' ? 'Retirar do treino' : 'Publicar para treino' }}<span class="sr-only"> {{ item.name }}</span></button>
                </article>
                <FeaturePager :meta="data.meta" :loading="loading || busy" @page="editing = null; filter({ page: $event })" />
            </section>
        </template>
    </div>
</template>
