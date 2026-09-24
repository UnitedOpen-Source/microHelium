# Issue #392 — cada julgamento registra com qual toolchain foi feito

**Decisão:** a versão do toolchain da linguagem do run e o perfil da imagem
(`JUDGE_PROFILE`) passam a ser gravados **no julgamento**, pelos dois caminhos
que julgam (judgehost remoto e fila local). O julgamento que vale mora em
`runs`; o julgamento anterior, quando há rejulgamento em lote, mora em
`rejudging_runs`, ao lado do veredito anterior, que é onde ele já morava.
Rejulgar sinaliza quando a versão mudou.

## O que já existia

- Desde o #373 (#303) cada judgehost declara a versão de cada toolchain que
  tem, sondada na máquina, em `judgehost_capabilities.version`. Um
  re-registro sobrescreve: é a versão **de agora**, não a do julgamento.
- `App\Support\Judge\ToolchainVersions` é o dono único de *como se pergunta*
  a versão; `MachineCapabilities::versionsOf()` a sonda e memoiza por
  processo.
- A imagem do juiz carrega o nome do perfil em `ENV JUDGE_PROFILE` (#306).
  Ausente quer dizer `completo`.

## Onde mora — e por que não numa tabela de julgamentos

A issue pergunta: coluna em `runs`, ou tabela de julgamentos, já que um run
pode ser julgado mais de uma vez?

**Colunas em `runs`** (`toolchain_version`, `toolchain_profile`), e **pares
antes/depois em `rejudging_runs`**. Razões:

1. **O esquema já decidiu onde fica o julgamento.** Não existe tabela de
   julgamentos: veredito, medição (#196), `auto_judge_*`, `judgehost_id` e
   `reported_claim_token` moram em `runs` e descrevem *o julgamento que
   vale*. A versão é mais um atributo desse julgamento, e fica ao lado da
   medição que a #196 já pôs ali pelo mesmo motivo (comparar máquinas).
2. **O "julgamento anterior" já tem casa.** O #192 criou `rejudging_runs`
   exatamente para guardar o estado de antes ("o old judging data is kept",
   como no DOMjudge). A versão de antes entra ali, capturada no mesmo
   instante em que o veredito de antes é capturado (`RejudgingService::create`),
   e a de depois entra quando o julgamento de sombra roda
   (`RejudgeMemberJob`). Aplicar o conjunto copia a de depois para o run,
   junto com o veredito — porque o veredito novo foi produzido por ela.
3. **Uma tabela de julgamentos é uma migração de modelo, não um campo.**
   Todo caminho que grava veredito (local, remoto, rejulgamento, manual,
   watchdog, reconciliação) teria de passar a gravar ali; fazer isso só para
   a versão deixaria duas fontes de verdade sobre "o julgamento" — a tabela
   para a versão, `runs` para o resto. Fica registrado como o passo certo
   *se* um dia o sistema passar a guardar o histórico inteiro de cada
   julgamento; não é o caso desta issue.

O rejulgamento avulso (`POST /api/runs/{run}/rejudge`) **não limpa** as
colunas de toolchain: elas ficam descrevendo o julgamento anterior até o novo
veredito chegar, e é isso que permite comparar na hora de gravar.

## Contrato

### Colunas

| Tabela | Coluna | Tipo | Significado |
|---|---|---|---|
| `runs` | `toolchain_version` | `string(40)` null | versão do toolchain da linguagem do run, na máquina que produziu o veredito vigente |
| `runs` | `toolchain_profile` | `string(40)` null | perfil da imagem (`JUDGE_PROFILE`) dessa máquina |
| `rejudging_runs` | `old_toolchain_version`, `old_toolchain_profile` | idem | o que estava no run quando o conjunto foi montado |
| `rejudging_runs` | `new_toolchain_version`, `new_toolchain_profile` | idem | o que o julgamento de sombra usou |

`null` significa **"não disse"**, nunca "não tem" — a mesma regra do #303. Um
agente anterior a esta mudança, um toolchain sem receita em
`ToolchainVersions` (ex.: linguagem customizada), ou uma sonda que falhou
gravam `null`. Nenhum veredito é recusado por falta de versão.

### De onde vem cada valor

- **Perfil:** `getenv('JUDGE_PROFILE')`, aparado; vazio ou ausente é
  `completo`. Lido direto do ambiente porque a classe que o #393 cria para
  isso (`App\Support\Judge\PerfilDaImagem`) ainda não está no `master`;
  quando entrar, `JudgingToolchain::perfil()` passa a delegar a ela.
- **Versão:** `MachineCapabilities::versionsOf([$extensao])`, na máquina que
  julga, memoizada por processo (o custo de subir uma JVM para
  `kotlinc -version` é pago uma vez por processo, não por julgamento).
- **Tudo isso atrás de um dono só:** `App\Services\Judgehost\JudgingToolchain`,
  singleton no container, com `carimbo(string $extensao): array{toolchain_version: ?string, toolchain_profile: string}`.
  Uma sonda que lança exceção vira `null`; carimbo nunca derruba julgamento.

### Caminho remoto

- **Claim** (`POST /api/remote-judges/v1/fetch-work`): o DTO ganha
  `language.version`, a versão que **este host declarou** para a extensão do
  run (ou `null`). Fecha a promessa da spec #53 ("DTO de claim inclui ...
  versão de linguagem").
- **Resultado** (`POST /api/remote-judges/v1/runs/{run}/result`): aceita
  `toolchain_version` e `toolchain_profile` (opcionais, `string|max:40`). O
  `JudgehostAgent` os envia a partir de `JudgingToolchain` construído sobre a
  `MachineCapabilities` dele.
- **Fallback:** um agente que não manda `toolchain_version` (anterior a esta
  mudança, mas posterior ao #373) tem gravada a versão que **o próprio host
  declarou** para aquela extensão no registro. É a mesma sonda, no mesmo
  processo, e é mais verdade que `null`. O perfil não tem fallback: sem
  declaração, fica `null`.

### Caminho local

- `JudgeRunJob` → `AutoJudgeService::judge()` carimba o resultado com
  `JudgingToolchain` do container antes de `recordVerdict()`.
- `recordVerdict()`/`writeVerdict()` gravam as duas colunas quando o
  resultado as traz (chave presente), e as deixam como estavam quando não
  traz — assim um caminho que não carimba não apaga o que outro gravou.

### Sinalização no rejulgamento

- **Em lote:** `RejudgingRun::changesToolchain()` é verdadeiro quando o
  membro foi julgado sem erro, as duas versões são conhecidas e diferem.
  Versão desconhecida de um dos lados **não** é mudança (não se sabe), mas a
  prévia a mostra como está. `RejudgingService::preview()` ganha
  `toolchain_changes` (contagem) e, por item, `toolchain_from`,
  `toolchain_to` e `toolchain_changed`. A tela `judge/rejudging` mostra a
  contagem com um aviso e uma coluna *Toolchain*. O log de aplicação leva
  `toolchain_changed` no contexto.
- **Avulso e qualquer re-gravação:** quando `writeVerdict()` grava uma
  versão conhecida e o run já tinha **outra** versão conhecida (o
  julgamento anterior), escreve `ContestLog::warning` com
  `event = toolchain_changed`, `from`, `to`, `profile_from`, `profile_to`.

## Casos de teste

1. Remoto: o agente julga um run e `runs.toolchain_version`/`toolchain_profile`
   recebem o que a `MachineCapabilities` **do agente** respondeu, atravessando
   o HTTP (duble só do lado do agente, para que o servidor não possa
   preencher sem o fio).
2. Remoto: o endpoint de resultado grava os campos enviados; um agente que
   não os envia tem o veredito aceito e a versão declarada do host gravada;
   sem declaração, `null`.
3. Remoto: o claim traz `language.version` com a versão declarada.
4. Local: `JudgeRunJob` julga de verdade um run em PHP e grava
   `toolchain_version = PHP_VERSION` (sonda real, pela linha de
   `ToolchainVersions`) e o perfil do ambiente.
5. Perfil: `JUDGE_PROFILE` ausente/vazio dá `completo`; definido dá o nome.
6. Lote: `create()` captura a versão antiga; `RejudgeMemberJob` grava a nova;
   `preview()` conta e marca a mudança; `apply()` copia versão e perfil novos
   para o run.
7. Lote: versão desconhecida de um lado não conta como mudança.
8. Avulso: rejulgar um run julgado com GCC 13 que agora é julgado com GCC 15
   escreve o aviso `toolchain_changed` no log da prova; mesma versão, não.

## O que fica de fora

- **Tabela de julgamentos** com histórico completo: ver acima.
- **Versão como critério de roteamento ou de aceite**: continua registro,
  nunca portão (mesmo limite do #303).
- **Perfil declarado no `register`** (e mostrado na tela de judgehosts): o
  perfil vai no resultado de cada julgamento, que é o que a issue pede; a
  declaração por host pode vir junto com o #393, que é quem dá significado
  ao perfil no lado do agente.
- **Mostrar a versão na tela de cada envio** (`julgado com GCC 15.2.0 /
  perfil maratona`): o dado fica nas colunas de `runs`; a tela de envio não
  muda neste PR. A única interface nova é a da prévia do rejulgamento em
  lote.
- **Falha de julgamento (`CS` de `handleJudgingError`) e veredito manual**
  não carimbam: nenhum dos dois é um julgamento produzido por um toolchain,
  e as colunas ficam como estavam (no rejulgamento avulso, com a versão do
  julgamento anterior). Distinguir "carimbo do julgamento que falhou" fica
  para quando houver quem leia essa distinção.
- **`Run.php` fora do Pint:** o arquivo já reprova no `pint --test` no
  `master` (importações e espaçamento antigos); este PR só acrescenta duas
  entradas ao `$fillable` e não reformata o resto.
