# #43 — Treino Livre, fase 1

## Contexto e objetivo

`Problem`/`Run` dependem de concurso e o fluxo atual seleciona competição ativa/do usuário. Permitir biblioteca pública e envios privados contínuos sem contaminar placares reais. Telas: `/practice`, `/practice/problems/{id}`, `/practice/history`; publicação em `/backend/bank-governance`.

## Escopo e regras

- Criar um único concurso técnico de prática, identificado por campo/tipo explícito e nunca por nome. Excluir esse concurso de competição ativa, seletor público, relógio, ativação global, CSV de evento, tarefas/balloons e cálculos de ranking de eventos.
- Não reutilizar diretamente `SubmitController` sem adaptar suas regras de horário e escopo. `canSubmit` para prática independe da associação do usuário a um evento ao vivo. Reusar avaliação, validação de linguagem/fonte e isolamento do juiz por serviço compartilhado com contexto explícito.
- Admin publica **snapshot versionado** de `ProblemBank` (enunciado, limites, testes e linguagens) para prática. Editar o banco depois não altera desafios ou resultados existentes. Retirar da biblioteca bloqueia novos envios, preservando histórico privado. Republicação usa versão publicada explícita e não reavalia silenciosamente envios anteriores.
- Biblioteca contém apenas publicados. Enunciado é texto simples com quebras de linha e exemplos estruturados; converter documentos/PDF no backend ou definir futuro contrato de download, sem enviar HTML bruto ao componente.
- Fonte pelo editor, sem upload de arquivo nesta fase. Resultado vai para histórico; cliente não finge AC. Usuário sem login pode ler e recebe convite para entrar; envio exige conta habilitada e política de rate limit.
- Estatísticas opcionais: participantes distintos que tentaram e participantes distintos com AC. Não dividir AC por total de runs e rotular como taxa de pessoas. `stats:null` quando não implementado ou suprimido por privacidade/baixa amostragem. Sem percentil de dificuldade nesta fase.

## API e dados

`GET /api/frontend/practice/problems?q=&page=1` público: `{items:[{id,short_name,name,summary,tags:string[],solved:boolean,stats:null|{solved_count,participant_count}}],meta}`. Para anônimo `solved=false`; campos personalizados tornam a resposta privada/não cacheável. Busca normalizada por nome/etiqueta, paginação estável.

`GET /api/frontend/practice/problems/{id}` público: `{problem:{id,short_name,name,statement,examples:[{input,output}],time_limit_ms,memory_limit_mb,max_source_bytes,languages:[{id,name}]},capabilities:{can_submit,requires_login},submit_unavailable_reason:null|string}`. Sem fonte de referência/testes ocultos. Não publicado/inexistente: 404.

`POST /api/frontend/practice/problems/{id}/runs`: `{language_id,source}` + Idempotency-Key → 202 `{data:{id,status:"pending"}}`. Validar bytes UTF-8, linguagem da versão, publicação ainda ativa, usuário habilitado, limites de frequência; 422 em `source`/`language_id`, 409 se versão/publicação mudou. Usar run_id efetivo, nunca ID legado.

`GET /api/frontend/practice/history?page=1` autenticado: `{items:[{id,problem_name,language_name,status,verdict:null|string,created_at,recovery_message:null|string,detail_url:null|string}],meta}`. Ordenar recente primeiro. Filtrar SEMPRE pelo ator e concurso de prática; não aceitar user_id por query. `detail_url` só pode apontar para detalhe que já autorize prática e a fonte privada; enquanto não implementado, null. Retirada do problema não remove os envios.

`POST /api/frontend/bank-governance/{id}/practice`: `{published:boolean,version:string}` → 200 `{data:{published,practice_problem_id,version}}`. `can_publish` deve vir na listagem do banco. Publicar exige statement/limites/testes/linguagens válidos e capacidade sobre o problema, além de aprovação para divulgação fora do evento. 422 para material incompleto; 409 para snapshot/versão obsoleta.

Proposta aditiva: tipo de concurso + `practice_publications(problem_bank_id, problem_id, version, published_at, unpublished_at, published_by, source_snapshot_hash)`. Reaproveitar runs e scores com contexto segregado; mapear versionamento antes de criar migration, não criar um concurso por tentativa.

## Casos de borda e requisitos não funcionais

- Concorrência entre retirada e envio: decisão atômica; se aceito antes da retirada, julgar e manter no histórico.
- Scheduler/watchdog #45 respeita site técnico de prática; sem competição ativa nunca impede treino.
- Privacidade #47 aplicada no servidor; biblioteca só recebe agregados permitidos, sem nascimento, lista de participantes ou histórico de terceiros.
- Desafio alterado exige nova versão; rejudge não altera estatísticas de evento; cache invalidado apenas no escopo de prática.
- Código preservado no cliente em erro; normalizar verdicts existentes, distinguir CS de falha de conexão.

## Critérios de aceite

1. Conta inscrita em evento A treina sem mudar sua inscrição; relógio/score de A ficam iguais.
2. Publicar/retirar atualiza biblioteca; usuário não acessa publicação retirada por ID nem envia nela após retirada confirmada.
3. Duplo clique/repetição de chave cria um Run; AC/WA aparecem no histórico do proprietário apenas.
4. Anônimo lê publicado, POST recebe 401; restrição de privacidade não é contornada por `/submissions`, API existente ou links de detalhe.
5. Banco vazio, busca vazia e ausência de estatísticas exibem estados distintos.

## Perguntas para decisão

Confirmar política de divulgação de problemas de eventos ainda ativos e volume mínimo para estatística pública. Proposta: publicação exige aprovação administrativa explícita e stats ficam null até política definida. Desacoplamento completo de contest_id e ranking pessoal são fase posterior, não pré-requisitos destas telas.
