# #53 — Exploração de julgamento distribuído

## Contexto e objetivo

Avaliar pull de tarefas por agentes autenticados, preservando isolamento e resultado único. A issue é exploratória e grande; não há agente remoto nem cadastro fictício de máquinas nesta entrega. A tela `/judge/health` já consome estados de julgamento sem depender da localização do worker.

## Fases propostas

1. ADR com necessidade medida (fila/tempo de espera/throughput), comparação com múltiplos workers Redis existentes, trust model e custo operacional. Decidir se prosseguir.
2. Prova de conceito em ambiente descartável: registrar identidade, heartbeat, claim com lease e retorno de resultado, sem acesso a concurso real.
3. Confinamento #49 + credenciais revogáveis + roteamento por capacidades e site, recuperação #45 e testes de concorrência; rollout limitado.
4. Só então definir gestão visual de máquinas/capacidade; publicar API e capacidade can_manage_judges antes de adicionar ações à UI.

## Contrato backend proposto

API separada `/api/remote-judges/v1` autenticada por token por máquina, fora da sessão de browser: register, heartbeat, claim, report e release. DTO de claim inclui run/attempt IDs, versão de linguagem, limites e lease token/expires_at; não envia credenciais de banco/filas nem montagens do servidor. Downloads de fonte/testes devem ser autenticados e limitados à tentativa; resultados grandes limitados e validados.

Claim e conclusão são atômicos. Heartbeat estende lease apenas do proprietário atual. Re-registro identifica sessão/geração nova; agente antigo não pode finalizar após perda do lease. Report idempotente por run+attempt+token; resultado tardio409, sem sobrescrever score/balloons. Restart/cancelamento terminam processo local e servidor reconcilia lease expirado. Não presumir entrega exatamente uma vez pela rede: garantir efeitos finais idempotentes.

### Versão junto da capacidade (#303, entregue)

A promessa acima de que o DTO inclui "versão de linguagem" estava na spec e não no código: `MachineCapabilities::detect()` devolvia só extensões, e dois judgehosts de um parque — um com GCC 13, outro com GCC 15 — declaravam capacidade **idêntica**.

O que passou a existir:

- `App\Support\Judge\ToolchainVersions` é o dono único de *como se pergunta a versão* de cada toolchain (a receita morava em `tests/E2E/`, onde o agente não podia alcançá-la).
- O agente **sonda** a versão na própria máquina, no `register`, e a envia em `language_versions` (mapa `extensão => versão`), ao lado de `languages`.
- O servidor guarda em `judgehost_capabilities.version`, e a tela de judgehosts mostra a divergência entre máquinas do parque.

Três limites deliberados:

1. **Sonda, não pino do Dockerfile.** O pino diz o que o *nosso* repositório manda instalar; a máquina que importa é a da instituição parceira, que construiu a imagem dela.
2. **A versão é registro, nunca roteamento.** `canJudge()` continua decidindo por presença da extensão. Rotear por versão exigiria alguém dizendo qual versão um contest exige — hoje nada no sistema diz isso — e a falha dessa regra seria um parque inteiro parando de receber trabalho com a fila parecendo vazia.
3. **Ausente significa "não disse".** Um agente anterior a #303, ou um toolchain que não se identificou a tempo, declara as mesmas extensões de sempre com `version = null`.

**O que ainda falta:** o DTO de *claim* continua sem a versão, e nenhuma coluna de `runs` guarda com que versão aquela submissão foi julgada. O que existe hoje é a versão que cada host declara **agora**, que um re-registro sobrescreve; comparar dois julgamentos da mesma submissão ainda depende de olhar o host no momento certo.

Capacidades são compatibilidades efetivas de linguagem/runtime, não permissões. **Sem downgrade automático que retire isolamento ou mude a semântica do julgamento.** Se nenhum executor compatível existir, manter pending com motivo e alerta operacional. Credencial de um concurso/site não recebe runs fora do escopo; agente não acessa endpoints administrativos/participantes.

## Impacto de frontend

Manter `recovery_status`, retry_count, last_activity_at, next_check_at e mensagens seguras do contrato #45. Não mostrar token/endereço privado de máquinas a participantes. Uma futura tela de agentes pode listar saúde/capacidades/drenagem, mas depende de decisão arquitetural e API real. A interface atual não promete nem exige agentes remotos.

## Critérios de aceite

Dois agentes concorrentes não executam efeitos finais duplicados; resultado de lease vencido não modifica Run; reinício conserva trabalho recuperável; perda de rede não apaga fonte/resultados; máquina revogada não obtém novos trabalhos; capacidade insuficiente mantém fila com motivo. Medir throughput/latência contra múltiplos workers locais antes de justificar a migração.

## Perguntas para decisão

Meta de escala, ambientes confiáveis/voluntários, distribuição de testes privados, política de retenção, SLA de heartbeat e necessidade real de capacidade especial. Implementação condicionada ao ADR; nenhuma infraestrutura de produção foi alterada.
