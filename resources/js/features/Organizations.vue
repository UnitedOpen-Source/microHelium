<script setup>
import { ref, computed } from 'vue';
import { useFeature } from './useFeature.js';
import FeatureState from './FeatureState.vue';
import FieldError from './FieldError.vue';

// Issue #188/#233. Contrato: docs/specs/188-organizacoes.md.
//
// A #46 entregou "um problema pertence a uma organizacao, e quem e editor
// dela pode edita-lo" -- e nenhum jeito de CRIAR uma organizacao. A tela de
// governanca do banco abria com o seletor de proprietario vazio em qualquer
// instalacao real. Esta e a tela que faltava.
const { data, loading, error, busy, notice, actionError, load, act } = useFeature('/api/frontend/organizations');

const novaOrg = ref(''), formCriar = ref(null);
const renomeando = ref(null), nomeNovo = ref('');
const arquivando = ref(null);
const adicionandoEm = ref(null), novoMembro = ref(''), formMembro = ref(null);
const removendo = ref(null);

// `request()` ja devolve o `data` da resposta, entao aqui NAO se
// desembrulha de novo -- foi o que um teste pegou, passando vazio por
// engano em vez de falhar.
const organizacoes = computed(() => data.value?.organizations ?? []);
const ativas = computed(() => organizacoes.value.filter(o => !o.archived).length);
const arquivadas = computed(() => organizacoes.value.filter(o => o.archived).length);

async function criar() {
    const feita = await act('/api/frontend/organizations', { name: novaOrg.value }, 'Organização criada.', 'POST', formCriar.value);
    if (feita) { novaOrg.value = ''; await load(); }
}

async function renomear(org) {
    const feita = await act(`/api/frontend/organizations/${org.id}`, { name: nomeNovo.value }, 'Nome atualizado.', 'PATCH');
    if (feita) { renomeando.value = null; nomeNovo.value = ''; await load(); }
}

// Arquivar NAO orfana nada: os problemas continuam apontando para a
// organizacao, os membros dela continuam podendo edita-los, e o que muda e
// que ela some do seletor de proprietario. A confirmacao diz isso, porque
// "arquivar" sozinho soa como remover.
async function definirArquivo(org, archived) {
    const feita = await act(
        `/api/frontend/organizations/${org.id}`,
        { archived },
        archived ? 'Organização arquivada.' : 'Organização reaberta.',
        'PATCH',
    );
    if (feita) { arquivando.value = null; await load(); }
}

async function adicionarMembro(org) {
    const feita = await act(
        `/api/frontend/organizations/${org.id}/members`,
        { user_id: Number(novoMembro.value) },
        'Membro adicionado.',
        'POST',
        formMembro.value,
    );
    if (feita) { adicionandoEm.value = null; novoMembro.value = ''; await load(); }
}

async function removerMembro(org, membro) {
    const feita = await act(
        `/api/frontend/organizations/${org.id}/members/${membro.user_id}`,
        null,
        'Membro removido.',
        'DELETE',
    );
    if (feita) { removendo.value = null; await load(); }
}
</script>

<template>
    <div class="feature-stack">
        <FeatureState :loading="loading" :error="error" :stale="!!data" :busy="busy" :notice="notice" :action-error="actionError" @retry="load()" />

        <template v-if="data">
            <dl class="feature-metrics">
                <div class="surface stat-card"><dt>Organizações</dt><dd>{{ organizacoes.length }}</dd></div>
                <div class="surface stat-card"><dt>Ativas</dt><dd>{{ ativas }}</dd></div>
                <div class="surface stat-card"><dt>Arquivadas</dt><dd>{{ arquivadas }}</dd></div>
            </dl>

            <form ref="formCriar" class="surface feature-form" @submit.prevent="criar">
                <h2>Nova organização</h2>
                <p class="feature-hint">
                    Uma organização é a instituição dona de problemas no banco. Quem for
                    <strong>editor</strong> dela pode editar os problemas dela.
                </p>
                <div class="feature-field">
                    <label for="org-nome">Nome</label>
                    <input id="org-nome" v-model="novaOrg" name="name" type="text" maxlength="120" required autocomplete="organization">
                    <FieldError :errors="actionError?.errors" field="name" />
                </div>
                <button type="submit" class="button-primary" :disabled="busy || !novaOrg.trim()">Criar organização</button>
            </form>

            <div v-if="!organizacoes.length" class="surface feature-empty">
                <h2>Nenhuma organização ainda</h2>
                <p>Enquanto não houver nenhuma, o seletor de proprietário na governança do banco fica vazio e os problemas ficam sem dono.</p>
            </div>

            <ul v-else class="feature-list">
                <li v-for="org in organizacoes" :key="org.id" class="surface feature-row">
                    <div class="feature-row-head">
                        <h3>
                            {{ org.name }}
                            <span v-if="org.archived" class="feature-badge">Arquivada</span>
                        </h3>
                        <p class="feature-hint">
                            {{ org.problem_count }} problema(s) no banco · {{ org.members.length }} membro(s)
                        </p>
                    </div>

                    <!-- Renomear -->
                    <form v-if="renomeando === org.id" class="feature-inline-form" @submit.prevent="renomear(org)">
                        <label :for="`renomear-${org.id}`" class="sr-only">Novo nome de {{ org.name }}</label>
                        <input :id="`renomear-${org.id}`" v-model="nomeNovo" name="name" type="text" maxlength="120" required>
                        <button type="submit" class="button-primary" :disabled="busy">Salvar</button>
                        <button type="button" class="button-secondary" @click="renomeando = null">Cancelar</button>
                        <FieldError :errors="actionError?.errors" field="name" />
                    </form>

                    <!-- Arquivar / reabrir, com o que isso significa dito antes -->
                    <div v-else-if="arquivando === org.id" class="feature-confirm" role="group" :aria-label="`Confirmar arquivamento de ${org.name}`">
                        <p>
                            Arquivar <strong>{{ org.name }}</strong> tira ela do seletor de proprietário:
                            nenhum problema novo poderá ser atribuído a ela.
                        </p>
                        <p class="feature-hint">
                            Os {{ org.problem_count }} problema(s) dela continuam sendo dela, e os membros
                            continuam podendo editá-los. Dá para reabrir depois.
                        </p>
                        <button type="button" class="button-primary" :disabled="busy" @click="definirArquivo(org, true)">Arquivar</button>
                        <button type="button" class="button-secondary" @click="arquivando = null">Cancelar</button>
                    </div>

                    <div v-else class="feature-row-actions">
                        <button type="button" class="button-secondary" @click="renomeando = org.id; nomeNovo = org.name">Renomear</button>
                        <button v-if="!org.archived" type="button" class="button-secondary" @click="arquivando = org.id">Arquivar</button>
                        <button v-else type="button" class="button-secondary" :disabled="busy" @click="definirArquivo(org, false)">Reabrir</button>
                    </div>

                    <!-- Membros -->
                    <details class="feature-details">
                        <summary>Membros ({{ org.members.length }})</summary>

                        <p v-if="!org.members.length" class="feature-hint">
                            Sem membros. Ninguém além de administradores pode editar os problemas desta organização.
                        </p>

                        <ul v-else class="feature-sublist">
                            <li v-for="membro in org.members" :key="membro.user_id">
                                <span>{{ membro.name }} <em>({{ membro.role }})</em></span>

                                <span v-if="removendo === `${org.id}:${membro.user_id}`" class="feature-confirm-inline">
                                    Remover o acesso de {{ membro.name }}?
                                    <button type="button" class="button-primary" :disabled="busy" @click="removerMembro(org, membro)">Remover</button>
                                    <button type="button" class="button-secondary" @click="removendo = null">Cancelar</button>
                                </span>
                                <button v-else type="button" class="button-secondary" @click="removendo = `${org.id}:${membro.user_id}`">
                                    Remover<span class="sr-only"> {{ membro.name }} de {{ org.name }}</span>
                                </button>
                            </li>
                        </ul>

                        <form v-if="adicionandoEm === org.id" ref="formMembro" class="feature-inline-form" @submit.prevent="adicionarMembro(org)">
                            <label :for="`membro-${org.id}`">Identificador da conta</label>
                            <input :id="`membro-${org.id}`" v-model="novoMembro" name="user_id" type="number" min="1" required>
                            <button type="submit" class="button-primary" :disabled="busy">Adicionar como editor</button>
                            <button type="button" class="button-secondary" @click="adicionandoEm = null">Cancelar</button>
                            <FieldError :errors="actionError?.errors" field="user_id" />
                        </form>
                        <button v-else type="button" class="button-secondary" @click="adicionandoEm = org.id">Adicionar membro</button>
                    </details>
                </li>
            </ul>
        </template>
    </div>
</template>
