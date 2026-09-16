<script setup>
import { ref, onMounted } from 'vue';
import { useFeature } from './useFeature.js';
import { dateTime, request } from './api.js';
import FeatureState from './FeatureState.vue';

// Issue #53, fase 4. Contrato: docs/specs/53-judge-management.md.
const { data, loading, error, busy, notice, actionError, load, act } = useFeature('/api/frontend/judgehosts');

const name = ref(''), token = ref(null), disabling = ref(null), form = ref(null);

// Issue #196/#233 -- a divergencia medida entre as maquinas.
//
// Na mesma tela porque e a mesma pergunta: o #53 responde "quais maquinas
// existem e estao vivas", isto responde "elas sao comparaveis?". Carregado
// a parte do listamento principal para que uma falha aqui nao derrube a
// tela de maquinas, que e operacional.
const calibracao = ref(null), calibracaoFalhou = ref(false);

// A forma e CONFERIDA antes de ser usada, e nao so o status HTTP.
//
// Sem isto, um payload com forma inesperada faz o render lancar ao chamar
// `.toFixed()` num campo ausente -- e um erro de render derruba o
// componente INTEIRO, deixando a tela de maquinas em branco. O try/catch do
// fetch nao pega isso, porque acontece depois. Dois testes que ja existiam
// pegaram: eles servem um payload de maquinas para qualquer URL, e a lista
// de maquinas tambem tem `items`.
//
// A tela de maquinas e operacional; a comparacao e informativa. Uma
// informacao malformada nao pode levar junto a lista de quem esta julgando.
function linhaDeCalibracao(linha) {
    return linha
        && typeof linha.divergence === 'number'
        && typeof linha.fastest_ms === 'number'
        && typeof linha.slowest_ms === 'number'
        && Array.isArray(linha.per_host);
}

async function carregarCalibracao() {
    calibracaoFalhou.value = false;
    try {
        const recebido = await request('/api/frontend/judgehosts/calibration');

        // Payload ilegivel conta como FALHA, e nao como ausencia de dados.
        //
        // Deixa-lo como `null` faria a secao dizer "Carregando comparacao..."
        // para sempre -- prometendo algo que nunca chega, que e uma terceira
        // forma de mentir depois de "derrubar a tela" e "dizer que as
        // maquinas sao iguais". Do ponto de vista de quem opera, nao
        // conseguir ler a resposta e nao conseguir obter a comparacao sao a
        // mesma coisa: deu errado, tente de novo.
        if (typeof recebido?.threshold !== 'number' || !Array.isArray(recebido.items)) {
            calibracao.value = null;
            calibracaoFalhou.value = true;
            return;
        }

        calibracao.value = { ...recebido, items: recebido.items.filter(linhaDeCalibracao) };
    } catch {
        calibracao.value = null;
        calibracaoFalhou.value = true;
    }
}

onMounted(carregarCalibracao);

const nomeDaMaquina = id => data.value?.items?.find(host => host.id === id)?.name ?? `#${id}`;

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

            <!--
                Issue #196/#233. O #117/#130 decidiu que hardware
                heterogeneo e AVISADO e nao compensado -- e ate o #196 nao
                havia o aviso. Esta secao nao muda veredito nenhum, e o
                texto diz isso, porque prometer compensacao que nao existe
                seria pior do que nao avisar.
            -->
            <section class="surface feature-panel">
                <h2>Comparação entre máquinas</h2>
                <p class="feature-help">
                    Medido nos envios <strong>aceitos</strong> que a prova já produziu — a mesma solução,
                    julgada em máquinas diferentes. Isto <strong>não altera vereditos</strong>: serve para
                    decidir se as máquinas podem julgar a mesma prova.
                </p>

                <p v-if="calibracaoFalhou" class="feature-message feature-error" role="alert">
                    Não foi possível carregar a comparação.
                    <button type="button" class="button-secondary" @click="carregarCalibracao()">Tentar de novo</button>
                </p>

                <template v-else-if="calibracao">
                    <div v-if="!calibracao.items?.length" class="feature-empty">
                        <h3>Ainda não há o que comparar</h3>
                        <!--
                            "Sem medicao" NAO e "as maquinas sao iguais", e a
                            issue pede explicitamente que a tela nao confunda
                            os dois. Uma comparacao exige o mesmo problema e
                            linguagem julgados em DUAS maquinas.
                        -->
                        <p>
                            Uma comparação precisa do mesmo problema e linguagem julgados em <strong>duas
                            máquinas diferentes</strong>, com veredito aceito. Enquanto isso não acontecer,
                            não há medição — o que <em>não</em> quer dizer que as máquinas sejam equivalentes.
                        </p>
                    </div>

                    <table v-else class="feature-table">
                        <caption class="sr-only">Divergência de tempo entre máquinas, por problema e linguagem</caption>
                        <thead>
                            <tr>
                                <th scope="col">Problema</th>
                                <th scope="col">Linguagem</th>
                                <th scope="col">Amostras</th>
                                <th scope="col">Mais rápida</th>
                                <th scope="col">Mais lenta</th>
                                <th scope="col">Divergência</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in calibracao.items" :key="`${item.problem_id}:${item.language_id}`"
                                :class="{ 'feature-error': item.divergence >= calibracao.threshold }">
                                <td>{{ item.problem ?? '—' }}</td>
                                <td>{{ item.language ?? '—' }}</td>
                                <td>{{ item.samples }}</td>
                                <td>{{ (item.fastest_ms / 1000).toFixed(2) }} s</td>
                                <td>{{ (item.slowest_ms / 1000).toFixed(2) }} s</td>
                                <td>
                                    {{ item.divergence.toFixed(2) }}×
                                    <span v-if="item.divergence >= calibracao.threshold" class="feature-badge feature-error">
                                        acima do limiar
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-if="calibracao.items?.length" class="feature-help">
                        Acima de {{ calibracao.threshold }}× o mesmo limite de tempo começa a significar
                        coisas diferentes em máquinas diferentes, e a equipe passa a receber TLE ou AC
                        conforme a máquina que pegou o envio. A mediana é usada para que um engasgo isolado
                        não decida o julgamento sobre uma máquina.
                    </p>

                    <details v-if="calibracao.items?.length" class="feature-details">
                        <summary>Tempo por máquina</summary>
                        <ul class="feature-sublist">
                            <li v-for="item in calibracao.items" :key="`d-${item.problem_id}:${item.language_id}`">
                                {{ item.problem ?? '—' }} / {{ item.language ?? '—' }}:
                                <template v-for="host in item.per_host" :key="host.judgehost_id">
                                    {{ nomeDaMaquina(host.judgehost_id) }} {{ (host.median_cpu_ms / 1000).toFixed(2) }} s ·
                                </template>
                            </li>
                        </ul>
                    </details>
                </template>

                <p v-else class="feature-message" role="status">Carregando comparação…</p>
            </section>
        </template>
    </div>
</template>
