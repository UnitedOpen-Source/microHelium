# #45 — Recuperação de envios sem atividade

## Contexto e objetivo

#40 introduziu `Site.max_judge_wait_time` e badge de atraso. Durante esta entrega, #56 integrou `ReconcileStuckRunsCommand`, `Run.reconcile_attempts` e job único por run. Esta spec agora descreve o contrato de apresentação e extensões de confiabilidade ainda necessárias; não solicita recriar o watchdog. Tornar visível e finito o ciclo de recuperação. Interface `/judge/health`, para admin/judge/site, e mensagem segura no histórico de prática.

## Escopo e máquina de estados

- Comando `runs:reconcile-stuck`, agendado a cada minuto (proposta), com lock de scheduler e lock/transação por run. Consultar status pending e ausência de heartbeat/lease ativo além do limite do site.
- Primeira ocorrência: reservar retentativa atomicamente (`recovery_attempts=1`, `last_requeued_at`, attempt/lease ID), despachar após commit, registrar evento. Retry-count não pode depender de contagem do navegador.
- Worker com lease válido nunca é duplicado. Se morreu, lease expira; nova tentativa usa outro token e worker antigo não pode gravar veredito/score/tarefa após perder ownership.
- Segunda ocorrência após uma janela COMPLETA desde a retentativa (não desde created_at): se ainda sem atividade, concluir erro de julgamento via Answer CS e pipeline existente. Fonte ausente pode concluir CS diretamente. Nunca escrever answer de outro contest ou redispatch infinito.
- Reconcile não julga inline na requisição de tela. Processamento normal concorre com watchdog sob a mesma guarda atômica; AC de worker válido não é sobrescrito por CS.
- Estados de apresentação `overdue|retrying|recovered|failed|pending|judging` são independentes do veredito. Recuperada significa conclusão após retentativa, não necessariamente AC.

## Diferença para a implementação já integrada

O reconciliador atual usa idade desde created_at, incrementa reconcile_attempts e conclui CS na rodada seguinte se o run continua overdue. Não há timestamp de retentativa/heartbeat no contrato atual. A janela completa após requeue, lease, outbox e serialização de finalização abaixo são requisitos adicionais propostos, não capacidades já disponíveis. Antes de habilitar o health completo, backend deve fornecer esses dados ou omitir capacidades/retornar null sem inventar atividade.

## Dados e API

Campo existente `reconcile_attempts` deve alimentar retry_count; não criar recovery_attempts duplicado. Extensões propostas em Run: last_requeued_at, last_judge_activity_at, judge_lease_token/expires_at ou mecanismo equivalente, recovery_resolved_at. Audit/event table para decisão, motivo e ator técnico; não persistir conteúdo da fonte em eventos.

`GET /api/frontend/judging-health?status=&page=1`: `{generated_at,watchdog:{enabled,last_checked_at},summary:{overdue,retrying,recovered,failed},items:[{id,problem_name,team_name,site_name,max_wait_seconds,retry_count,recovery_status,last_activity_at,next_check_at,message,detail_url}],meta}`. Datas ausentes null. Unidades em segundos, como `max_judge_wait_time` e a comparação `diffInSeconds` existentes. `summary` representa TODOS os envios autorizados no mesmo horizonte da listagem, independentemente do filtro status; definir horizonte temporal antes de materializar métricas. UI não inventa zero para ausência.

Admin vê o escopo administrativo atual. Judge deve respeitar **roteamento de sites da #41 além de contest_id**; site vê só o próprio site. Links de fonte/detalhe só quando esse perfil já tem autorização efetiva, senão null. Não ampliar acesso para criar o link.

Histórico de prática #43 pode receber `status:"retrying"` e `recovery_message` segura. Para páginas Blade de submissão existentes, backend deve entregar o mesmo resumo derivado e incluir apresentação equivalente sem expor heartbeat interno. Mensagem sugerida: “O julgamento está demorando. A organização está acompanhando; não é necessário reenviar.”

## Requisitos e bordas

- Scheduler sem heartbeat recente não pode afirmar `enabled:true` apenas porque configurado; sinalizar indisponível/stale e last_checked_at real.
- Falha entre reservar retry e enqueue: usar outbox ou dispatch recuperável com chave única; não consumir a única chance sem execução rastreável.
- Arquivo removido, site ausente, limite zero/nulo e concurso de prática exigem defaults explícitos. Não alterar retroativamente Score nem duplicar balloons/emails.
- Parâmetros status/page validados; nenhuma mutação manual na interface nesta fase.

## Critérios de aceite

Teste com relógio congelado: antes do limite não age; após limite reenvia uma vez; nova rodada imediata não conclui CS; após segunda janela sem atividade conclui CS. Dois reconciliadores e worker concorrentes resultam em no máximo um enqueue efetivo/veredito final e um conjunto de efeitos. Fonte ausente conclui erro seguro. Judge roteado para site B não recebe runs não roteados de C. Heartbeat stale aparece como indisponível.

## Perguntas para decisão

Definir lease/heartbeat, horizonte das métricas, timeout padrão e responsabilidade de operação do scheduler. São parte da implementação backend, não detalhes a inferir no navegador.
