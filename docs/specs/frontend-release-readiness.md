# Contrato de integração da revisão de frontend

Referência: #221–#225 e [auditoria](../frontend-release-audit.md). Status: implementação da interface e contratos existentes; itens explicitamente futuros permanecem nas issues correspondentes.

## Operações de competição implementadas

Rotas de sessão, CSRF e middleware `auth` + `admin`:

- `GET /backend/contest/{contest}/operations`: dados do modelo, `ContestFinalizer::blockers()` e premiação de `ContestAwards` apenas quando finalizada. Contest técnico de treino retorna 404.
- `POST /backend/contest/{contest}/unfreeze`: delega à operação existente de `Api\ContestController`. Prova em andamento recebe retorno recuperável, sem publicar.
- `POST /backend/contest/{contest}/finalize`: relê/locka o registro, verifica impedimentos novamente e reutiliza `ContestFinalizer`; repetição de evento já finalizado é idempotente e não troca o responsável/data.

A confirmação visual não autoriza nada por si só. O servidor reaplica as regras. `contest_not_started` foi acrescentado ao serviço compartilhado para recusar eventos futuros ou sem início, tanto na tela quanto no preflight API.

`GET /api/contest/current` mantém seu formato e acrescenta `is_finalized`, `end_time` (término global calculado pelo servidor) e `server_time` (referência para corrigir a diferença de relógio do navegador). O relógio usa os booleanos do servidor para congelamento/revelação e finalização. O contador entre consultas é uma indicação da agenda, não uma autorização para envio; o backend continua decidindo se uma submissão é permitida.

## Bloqueador #225: transições legadas

O frontend retirou os atalhos globais `/backend/contest/freeze` e `/backend/contest/end` da tela, mas essas rotas ainda existem. Antes de uma versão oficial, o backend precisa substituir `freeze_time=0` e `is_active=false` por operações coerentes com o sigilo do placar. Não adicionar um novo botão até existir uma transição segura e auditada.

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

Atualização de 16/09: #188, #195, #196 (fase 1), #198 e #200 foram integradas durante esta revisão. Use agora os contratos publicados em `188-organizacoes.md`, `195-contest-api.md`, `196-tempo-medido.md`, `198-intervalos-removidos.md` e `200-pacote-icpc.md`; não invente campos com base nesta tabela semântica. O frontend dessas operações adicionais ainda exige uma entrega própria. #219 também foi integrada no PR #232 (`219-event-feed.md`); falta homologação com resolver real. Os fluxos adicionais de interface estão reunidos em #233. A comparação da fase 1 de #196 usa envios aceitos já existentes, portanto não depende de importação de soluções de referência.

## Recuperação e dados desatualizados

As páginas Vue preservam a última resposta em falhas transitórias, identificam-na como desatualizada e recusam mutações até recuperar a consulta. 401/403 removem dados carregados da apresentação. Erros de envio preservam o rascunho em memória; 419 orienta copiar antes de atualizar a sessão. Não persistir soluções nem tokens em armazenamento do navegador. As chaves de idempotência continuam sendo reutilizadas em falhas ambíguas de rede.
