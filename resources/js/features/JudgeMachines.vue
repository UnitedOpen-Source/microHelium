<script setup>
import { ref } from 'vue';
import { useFeature } from './useFeature.js';
import { dateTime } from './api.js';
import FeatureState from './FeatureState.vue';

// Issue #53, fase 4. Contrato: docs/specs/53-judge-management.md.
const { data, loading, error, busy, notice, actionError, load, act } = useFeature('/api/frontend/judgehosts');

const name = ref(''), token = ref(null), disabling = ref(null), form = ref(null);

// Os cinco estados vem prontos do servidor, avaliados numa ordem que
// responde "o que o operador olha primeiro" -- uma maquina desligada E
// calada ha muito tempo e `disabled`, nao `stale`, porque dizer `stale`
// mandaria procurar falha de rede que nao existe.
const states = {
    judging: { label: 'Julgando', tone: 'feature-success' },
    idle: { label: 'Ociosa', tone: '' },
    stale: { label: 'Sem resposta', tone: 'feature-error' },
    disabled: { label: 'Desligada', tone: '' },
    never_seen: { label: 'Nunca registrou', tone: '' },
};

async function issue() {
    token.value = null;
    const result = await act('/api/frontend/judgehosts', { name: name.value }, 'Credencial emitida.', 'POST', form.value);
    // O token so existe nesta resposta -- o banco guarda o sha256. Some da
    // tela assim que o operador disser que guardou, e nao volta.
    if (result) { token.value = result.token; name.value = ''; await load(); }
}

async function setEnabled(host, enabled) {
    const released = await act(
        `/api/frontend/judgehosts/${host.id}`,
        { enabled },
        enabled ? 'Maquina religada.' : 'Maquina desligada.',
        'PATCH',
    );

    if (released) {
        disabling.value = null;
        token.value = null;
        await load();
    }
}
</script>
<template>
    <div class="feature-stack">
        <FeatureState :loading="loading" :error="error" :stale="!!data" :busy="busy" :notice="notice" :action-error="actionError" @retry="load()" />

        <template v-if="data">
            <dl class="feature-metrics">
                <div class="surface stat-card"><dt>Máquinas</dt><dd>{{ data.meta?.total ?? '—' }}</dd></div>
                <div class="surface stat-card"><dt>Habilitadas</dt><dd>{{ data.meta?.enabled ?? '—' }}</dd></div>
                <div class="surface stat-card"><dt>Julgando agora</dt><dd>{{ data.meta?.judging ?? '—' }}</dd></div>
                <div class="surface stat-card" :class="{ 'feature-error': data.meta?.stale > 0 }"><dt>Sem resposta</dt><dd>{{ data.meta?.stale ?? '—' }}</dd></div>
            </dl>

            <section v-if="data.capabilities?.can_manage_judges" class="surface feature-panel">
                <h2>Emitir credencial de máquina</h2>
                <p class="feature-help">O equivalente ao <code>php artisan judgehost:create</code>, sem precisar de shell no servidor. O token aparece uma única vez.</p>
                <form ref="form" class="feature-search" @submit.prevent="issue()">
                    <label>Nome da máquina<input v-model="name" name="name" maxlength="100" required autocomplete="off" placeholder="sala-3"></label>
                    <button class="button-primary" :disabled="busy || !name.trim()">Emitir credencial</button>
                </form>

                <div v-if="token" class="feature-message feature-success">
                    <label class="feature-secret">Token da nova máquina<input :value="token" readonly autocomplete="off" spellcheck="false" aria-describedby="token-help" @focus="$event.target.select()"></label>
                    <p id="token-help">Selecione e copie para um local seguro. Ele não será exibido novamente: o servidor guarda apenas o sha256. Configure <code>JUDGEHOST_TOKEN</code> na máquina com este valor.</p>
                    <button type="button" class="button-secondary" @click="token = null">Já guardei, ocultar token</button>
                </div>
            </section>

            <section class="surface feature-panel">
                <div class="feature-search">
                    <button type="button" class="button-secondary" :disabled="loading" @click="load()">Atualizar</button>
                    <p class="feature-help">Uma máquina é dada como sem resposta após {{ data.meta?.stale_after_seconds ?? '—' }} s — três vezes o intervalo com que um agente ocioso pede trabalho. O lease de um run é de {{ data.meta?.lease_seconds ?? '—' }} s.</p>
                </div>

                <div v-if="!data.items?.length" class="feature-empty">
                    <h2>Nenhuma máquina registrada</h2>
                    <p>Emita uma credencial acima e configure <code>JUDGEHOST_SERVER</code> e <code>JUDGEHOST_TOKEN</code> na máquina que vai julgar.</p>
                </div>

                <article v-for="host in data.items" :key="host.id" class="feature-record">
                    <div class="feature-heading">
                        <h2>{{ host.name }}</h2>
                        <span class="feature-badge" :class="states[host.state]?.tone">{{ states[host.state]?.label || host.state }}</span>
                    </div>

                    <dl class="feature-details">
                        <div><dt>Última vez vista</dt><dd>{{ dateTime(host.last_seen_at) }}</dd></div>
                        <div><dt>CPUs</dt><dd>{{ host.cpu_count ?? 'Não informado' }}</dd></div>
                        <div><dt>Memória</dt><dd>{{ host.memory_mb ? `${host.memory_mb} MB` : 'Não informada' }}</dd></div>
                    </dl>

                    <!--
                        `languages: []` NAO e "nao julga nada": e "nao
                        declarou nada", e a fila trata isso como "julga
                        qualquer coisa" (#117). Mostrar lista vazia como
                        incapacidade faria o operador desligar uma maquina
                        que esta funcionando.
                    -->
                    <p class="feature-help">
                        <template v-if="host.declares_languages">Linguagens declaradas: {{ host.languages.join(', ') }}</template>
                        <template v-else>Não declarou linguagens — a fila trata isso como “julga qualquer uma”. Agentes anteriores ao #117 se comportam assim.</template>
                    </p>

                    <p v-if="host.holding?.length" class="feature-help">
                        Segurando agora:
                        <template v-for="run in host.holding" :key="run.run_id">#{{ run.run_number ?? run.run_id }} ({{ run.problem || '?' }}) há {{ run.seconds_held }} s. </template>
                    </p>

                    <template v-if="data.capabilities?.can_manage_judges">
                        <button v-if="!host.enabled" type="button" class="button-secondary" :disabled="busy" @click="setEnabled(host, true)">Religar máquina</button>

                        <template v-else-if="disabling === host.id">
                            <p class="feature-message">
                                Desligar “{{ host.name }}” devolve à fila
                                {{ host.holding?.length || 'os' }}
                                {{ (host.holding?.length === 1) ? 'run que ela segura' : 'runs que ela segura' }},
                                para outra máquina julgar em seguida. A credencial continua válida: religar não exige reinstalar nada.
                            </p>
                            <button type="button" class="button-primary" :disabled="busy" @click="setEnabled(host, false)">Confirmar desligamento</button>
                            <button type="button" class="button-secondary" :disabled="busy" @click="disabling = null">Cancelar</button>
                        </template>

                        <button v-else type="button" class="button-secondary" :disabled="busy" @click="disabling = host.id">Desligar máquina</button>
                    </template>
                </article>
            </section>
        </template>
    </div>
</template>
