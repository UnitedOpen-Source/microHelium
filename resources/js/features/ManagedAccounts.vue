<script setup>
import { ref } from 'vue';
import { localUrl } from './api.js';
import { useFeature } from './useFeature.js';
import FeatureState from './FeatureState.vue';
import FeaturePager from './FeaturePager.vue';
import FieldError from './FieldError.vue';
const { data, loading, error, busy, notice, actionError, load, filter, act, query } = useFeature('/api/frontend/managed-accounts');
const activation = ref(null);
const search = ref(query.q || ''), fullname = ref(''), username = ref(''), birthdate = ref(''), contest = ref(''), site = ref('');
async function create(event) {
    activation.value = null;
    const result = await act('/api/frontend/managed-accounts', { fullname: fullname.value, username: username.value, birthdate: birthdate.value || null, contest_id: contest.value, site_id: site.value }, 'Conta gerenciada criada. A organização deve entregar o acesso pelo canal institucional.', 'POST', event.target);
    if (result) { activation.value = localUrl(result.activation_url) ? new URL(result.activation_url, location.origin).href : null; fullname.value = ''; username.value = ''; birthdate.value = ''; await load(); }
}
</script>
<template>
    <div class="feature-stack"><div class="feature-message"><strong>Privacidade desde o cadastro.</strong> Contas gerenciadas começam privadas. Datas de nascimento ficam restritas à administração; não aparecem na biblioteca nem no histórico público.</div>
        <FeatureState :loading="loading" :error="error" :busy="busy" :notice="notice" :action-error="actionError" @retry="load()" />
        <div v-if="activation" class="feature-message feature-success"><label class="feature-secret">Link de ativação da conta<input :value="activation" readonly autocomplete="off" @focus="$event.target.select()"></label><p>Entregue este link apenas ao participante pelo canal institucional. Ele é de uso único.</p><button type="button" class="button-secondary" @click="activation = null">Já entreguei, ocultar link</button></div>
        <template v-if="data && !error">
            <section v-if="data.capabilities?.can_create" class="surface feature-panel"><h2>Criar conta gerenciada</h2><p class="feature-help">Use os dados fornecidos à organização. Não é necessário vincular uma conta externa.</p>
                <form class="feature-form" @submit.prevent="create">
                    <label>Nome completo<input v-model="fullname" name="fullname" required maxlength="255" autocomplete="off" :disabled="busy" :aria-invalid="!!actionError?.errors?.fullname" aria-describedby="fullname-error"><FieldError :error="actionError" name="fullname" /></label>
                    <label>Nome de usuário<input v-model="username" name="username" required maxlength="80" autocomplete="off" autocapitalize="none" spellcheck="false" :disabled="busy" :aria-invalid="!!actionError?.errors?.username" aria-describedby="username-error"><FieldError :error="actionError" name="username" /></label>
                    <label>Data de nascimento (opcional)<input v-model="birthdate" name="birthdate" type="date" :disabled="busy" :aria-invalid="!!actionError?.errors?.birthdate" aria-describedby="birthdate-help birthdate-error"><small id="birthdate-help">Sem data informada, a conta permanece privada até revisão da organização.</small><FieldError :error="actionError" name="birthdate" /></label>
                    <label>Competição<select v-model="contest" name="contest_id" required :disabled="busy" @change="site = ''" :aria-invalid="!!actionError?.errors?.contest_id" aria-describedby="contest_id-error"><option value="">Selecione a competição</option><option v-for="item in data.contests" :key="item.id" :value="item.id">{{ item.name }}</option></select><FieldError :error="actionError" name="contest_id" /></label>
                    <label>Local de participação<select v-model="site" name="site_id" required :disabled="busy || !contest" :aria-invalid="!!actionError?.errors?.site_id" aria-describedby="site_id-error"><option value="">Selecione o local</option><option v-for="item in data.contests.find(item => String(item.id) === String(contest))?.sites || []" :key="item.id" :value="item.id">{{ item.name }}</option></select><FieldError :error="actionError" name="site_id" /></label>
                    <div class="feature-actions feature-wide"><button class="button-primary" :disabled="busy || loading">Criar conta privada</button></div>
                </form>
            </section>
            <section class="surface feature-panel"><h2>Contas e restrições</h2><form class="feature-search" role="search" @submit.prevent="filter({ q: search, page: 1 })"><label>Buscar por nome ou usuário<input v-model="search" name="q" type="search" maxlength="100"></label><button class="button-secondary" :disabled="busy || loading">Buscar contas</button></form><p v-if="!data.items?.length" class="feature-empty">Nenhuma conta gerenciada encontrada.</p>
                <article v-for="account in data.items" :key="account.id" class="feature-record"><div class="feature-heading"><h3>{{ account.fullname }}</h3><span class="feature-badge">{{ account.privacy_locked ? 'Privacidade protegida' : 'Restrição etária liberada' }}</span></div><p class="feature-help">{{ account.username }} · {{ account.contest_name }} · {{ account.site_name }}</p><dl class="feature-details"><div><dt>Visibilidade</dt><dd>{{ account.visibility === 'public' ? 'Pública' : 'Privada' }}</dd></div><div><dt>Contas externas</dt><dd>{{ account.external_linking_allowed ? 'Vinculação permitida' : 'Vinculação bloqueada' }}</dd></div></dl><p>{{ account.privacy_reason }}</p></article>
                <FeaturePager :meta="data.meta" :loading="loading || busy" @page="filter({ page: $event })" />
            </section>
        </template>
    </div>
</template>
