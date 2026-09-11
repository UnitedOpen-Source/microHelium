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

Capacidades são compatibilidades efetivas de linguagem/runtime, não permissões. **Sem downgrade automático que retire isolamento ou mude a semântica do julgamento.** Se nenhum executor compatível existir, manter pending com motivo e alerta operacional. Credencial de um concurso/site não recebe runs fora do escopo; agente não acessa endpoints administrativos/participantes.

## Impacto de frontend

Manter `recovery_status`, retry_count, last_activity_at, next_check_at e mensagens seguras do contrato #45. Não mostrar token/endereço privado de máquinas a participantes. Uma futura tela de agentes pode listar saúde/capacidades/drenagem, mas depende de decisão arquitetural e API real. A interface atual não promete nem exige agentes remotos.

## Critérios de aceite

Dois agentes concorrentes não executam efeitos finais duplicados; resultado de lease vencido não modifica Run; reinício conserva trabalho recuperável; perda de rede não apaga fonte/resultados; máquina revogada não obtém novos trabalhos; capacidade insuficiente mantém fila com motivo. Medir throughput/latência contra múltiplos workers locais antes de justificar a migração.

## Perguntas para decisão

Meta de escala, ambientes confiáveis/voluntários, distribuição de testes privados, política de retenção, SLA de heartbeat e necessidade real de capacidade especial. Implementação condicionada ao ADR; nenhuma infraestrutura de produção foi alterada.
