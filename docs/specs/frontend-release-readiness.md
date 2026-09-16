# Contrato de integração da revisão de frontend

Referência: #221–#225 e [auditoria](../frontend-release-audit.md). Status: implementação da interface e contratos existentes; itens explicitamente futuros permanecem nas issues correspondentes.

## Operações de competição implementadas

Rotas de sessão, CSRF e middleware `auth` + `admin`:

- `GET /backend/contest/{contest}/operations`: dados do modelo, `ContestFinalizer::blockers()` e premiação de `ContestAwards` apenas quando finalizada. Contest técnico de treino retorna 404.
- `POST /backend/contest/{contest}/unfreeze`: delega à operação existente de `Api\ContestController`. Prova em andamento recebe retorno recuperável, sem publicar.
- `POST /backend/contest/{contest}/finalize`: relê/locka o registro, verifica impedimentos novamente e reutiliza `ContestFinalizer`; repetição de evento já finalizado é idempotente e não troca o responsável/data.

A confirmação visual não autoriza nada por si só. O servidor reaplica as regras. `contest_not_started` foi acrescentado ao serviço compartilhado para recusar eventos futuros ou sem início, tanto na tela quanto no preflight API.

`GET /api/contest/current` mantém seu formato e acrescenta `is_finalized`, `end_time` (término global calculado pelo servidor) e `server_time` (referência para corrigir a diferença de relógio do navegador). O relógio usa os booleanos do servidor para congelamento/revelação e finalização. O contador entre consultas é uma indicação da agenda, não uma autorização para envio; o backend continua decidindo se uma submissão é permitida.

## Bloqueador #225: transições legadas — **resolvido**

O frontend tinha retirado os atalhos globais `/backend/contest/freeze` e `/backend/contest/end` da tela porque eles faziam o oposto do que o nome dizia: `freeze_time=0` significa *sem congelamento nenhum*, e `is_active=false` escondia a competição em vez de encerrá-la.

#234 (issue #225) substituiu os dois por `ContestLifecycle::freezeNow()` e `endEarly()`, que registram autor e hora no log da competição. #241 (issue #240) consertou o arredondamento de `endEarly()`: `duration` é em minutos e o `ceil` deixava a prova aceitando envios por até 59 segundos depois da ordem de encerrar.

Aceite obrigatório: evento identificado → congelar → terminar antecipadamente → público/equipe continuam sem resultado oculto → revelar explicitamente → finalizar. Cobrir também trocar evento ativo e API de desativação. A verificação de início foi corrigida nesta revisão; as demais transições continuam pendentes.

## Formulários repetidos

Sedes e linguagens enviam `_form_key` como contexto de apresentação, nunca como identificador autorizado do registro. Identidade e autorização continuam na rota/modelo. O redirect de validação deve preservar esse campo com o restante de `old input`; a tela usa-o para reabrir somente o formulário correto. `max_judge_wait_time` continua inteiro em **segundos**: a interface agora expõe exatamente essa unidade.

## Integrações com as entregas do backend

| Issue | Resposta/estado necessário | Aceite de UI |
| --- | --- | --- |
| #188 | Identidade estável, nome, status de arquivamento, membros/permissões e efeito sobre problemas vinculados | Organização criada aparece no seletor; arquivamento não apaga atribuições silenciosamente; edição respeita permissões atuais |
| #195 / #219 | Estados consistentes, feed NDJSON, token de retomada estável, eventos duráveis e política de freeze | Reconexão sem perda/duplicação; resultados secretos retidos até revelar; nenhum selo de “resolver pronto” só por REST estar ativo |
| #196 | Host, linguagem, versão/checksum, unidade, amostras, data, estado/erro e validade | “Sem medição” difere de zero; configuração alterada invalida a leitura; fase de medição não promete alterar vereditos |
| #198 | Estratégia escolhida, sede(s), motivo, intervalo/extensão, término efetivo anterior/novo, ator e reversão | Prévia de impacto e confirmação; relógio consome término efetivo do servidor; histórico explica mudanças |
| #200 | Formatos/versões suportados, diagnóstico por arquivo/campo, resumo e resultado atômico ou parcial explicitamente contratado | Seleção informa formato; erro mantém contexto; nenhuma solução/teste privado aparece no enunciado; importação parcial não parece sucesso total |

Atualização de 16/09 (manhã): #188, #195, #196 (fase 1), #198 e #200 foram integradas durante esta revisão. Use agora os contratos publicados em `188-organizacoes.md`, `195-contest-api.md`, `196-tempo-medido.md`, `198-intervalos-removidos.md` e `200-pacote-icpc.md`; não invente campos com base nesta tabela semântica. O frontend dessas operações adicionais ainda exige uma entrega própria. #219 também foi integrada no PR #232 (`219-event-feed.md`); falta homologação com resolver real. Os fluxos adicionais de interface estão reunidos em #233. A comparação da fase 1 de #196 usa envios aceitos já existentes, portanto não depende de importação de soluções de referência.

## Recuperação e dados desatualizados

As páginas Vue preservam a última resposta em falhas transitórias, identificam-na como desatualizada e recusam mutações até recuperar a consulta. 401/403 removem dados carregados da apresentação. Erros de envio preservam o rascunho em memória; 419 orienta copiar antes de atualizar a sessão. Não persistir soluções nem tokens em armazenamento do navegador. As chaves de idempotência continuam sendo reutilizadas em falhas ambíguas de rede.

## Atualização de 16/09 (tarde)

O que esta seção **não** faz é chamar de pronto o que não está. Cada linha diz onde a coisa para.

| capacidade | backend | interface | onde para |
| --- | --- | --- | --- |
| Organizações (#188) | pronto | pronto (#237) | — |
| Ajustes de tempo por sede (#198) | pronto | pronto (#238) | a revelação depois de extensão local é recusada quando **alguma** sede ainda submete; a tela diz qual |
| Comparação de máquinas (#196) | fase 1 | pronta (#237) | fase 1 mede sobre envios aceitos que já existem. **Não** altera veredito nenhum, e a tela não promete que altere |
| Importação ICPC/Kattis (#200) | pronto | pronto (#239) | `legacy` e `legacy-icpc`. A `2025-09` é recusada com o motivo dito; problema interativo idem |
| Ciclo de encerramento (#225/#202) | pronto | pronto (#236) | — |
| Estado da prova no relógio (#223) | pronto (#235) | pronto (#236) | — |
| Contest API CLICS (#195) | pronto | não se aplica | consumo por ferramenta externa, sem tela |
| Event feed NDJSON (#219) | pronto | não se aplica | **falta homologação com um resolver real.** Nenhum selo de "resolver pronto" até isso acontecer |
| Rejulgamento (#192) | pronto | **sem tela** | só por chamada à API. Um rejulgamento no meio da prova hoje se faz por `curl` |

### Rascunho não enviado (#224)

`resources/js/ui/draft-form.js`: um campo marcado com `data-draft-field` pede confirmação ao sair enquanto tiver conteúdo não enviado, e o envio libera a navegação. Ligado no formulário de envio da prova e na pergunta de esclarecimento; o editor de treino (`Practice.vue`) já tinha o seu.

A condição é "tem conteúdo e ainda não foi enviado desta página", e **não** "mudou desde que carregou". A diferença aparece depois de um 422: a página volta com o código reposto por `old()`, e uma comparação com o estado inicial diria "não mudou" justamente quando o código está digitado, não enviado, e a um clique de sumir.

Nada é gravado no armazenamento do navegador. Código-fonte de equipe é privado, e uma proteção contra perda que vaza a solução para o próximo que usar a máquina troca um problema por outro pior.

### Recuperação e navegação

Verificado nas dez telas Vue em master, não presumido:

- Todas passam `stale` ao `FeatureState`, então uma falha de atualização mantém a última resposta na tela com o aviso de desatualizada.
- `useFeature` escreve os filtros na URL com `pushState` e os relê no `popstate`; voltar e avançar restauram a consulta.
- 401 oferece entrar novamente, 419 oferece atualizar a sessão, 403 aponta a ajuda. Um 401/403 na consulta remove os dados carregados da apresentação.
- Chaves de idempotência são reutilizadas em falha ambígua de rede e descartadas em 422.

### Achados que continuam abertos

- **#242 (fechado em #243)** — quatro lugares extraíam ZIP enviado por alguém sem verificar os nomes. Não era fuga de diretório: o PHP reescrevia o caminho em silêncio, o que trocava um caso de teste dentro do próprio pacote.
- **`<submit-form>`** — a ilha está registrada em `resources/js/app.js` e **nenhuma view a usa**. Se alguma usasse, quebraria: o loop de ilhas monta sem props e o componente declara `contestId`, `problems` e `languages` como obrigatórias. O envio da prova é o formulário Blade de `exercises/submit.blade.php`.
