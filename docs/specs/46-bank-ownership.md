# #46 — Propriedade do banco e etiquetas

## Contexto e objetivo

Separar quem pode editar de como o problema é encontrado. Interface `/backend/bank-governance`, acessível via banco e ferramentas. Modelo proposto: organização proprietária nullable; manter tags array. Esta é uma decisão proposta, não uma entidade que já existe no backend.

## Escopo funcional

- Cada problema tem zero (legado) ou uma organização. Tags são múltiplas, texto livre e nunca concessão de papel/acesso.
- Admin global conserva bypass atual. Membro editor da organização pode editar seus problemas quando o backend oferecer esse papel e middleware. Transferência é capacidade separada de edição; proposta inicial: só admin transfere/desatribui. Problema legado sem organização fica administrável só por admin.
- UI inicial é administrativa. Colaboração por membros não-admin requer permitir a rota GET e API com policy explícita; **não apenas retirar `admin` da rota**. A versão atual oculta ação sem `can_edit` e desabilita troca sem `can_transfer`.
- Editar etiquetas/proprietário não modifica snapshots já publicados em treino ou usados em evento. Excluir organização com problemas: impedir até transferência/desatribuição explícita, preferir arquivar organização com histórico.

## Dados e API

Proposta: `organizations`, membership com papel editor, `problem_bank.owning_org_id` nullable/indexado/FK restrita e `version`/updated_at para controle otimista. Backfill mantém null; nunca atribuir todos os legados ao primeiro usuário. Escopo global de admin preservado até decisão diferente.

`GET /api/frontend/bank-governance?q=&organization_id=&page=1`: `{organizations:[{id,name}],items:[{id,name,owning_org_id:null|id,organization_name:null|string,tags:string[],version:string,practice_status:"published"|"unpublished",capabilities:{can_edit,can_transfer,can_publish}}],meta}`. organization_id vazio=todas; `unassigned`=legados. IDs e opções filtrados pelo escopo permitido. `can_publish` integra #43, false até publicação real existir.

`PATCH /api/frontend/bank-governance/{id}`: `{owning_org_id:null|id,tags:string[],version:string}` → 200 `{data:{id,version}}`. Tags: no máximo20, até40 caracteres por etiqueta, até500 no texto de entrada; trim/deduplicação/normalização definidas no servidor. Retornar erros de item como `tags` para associação com o campo da UI (não somente `tags.3`). Nome de organização vem do servidor, não do payload. 409 para versão antiga, preservar entrada e exigir recarregar antes de salvar; 422 para etiqueta/inexistência; 403 para transferência não autorizada, mesmo quando `can_edit` era true.

A UI manda o owning_org_id atual mesmo quando o select está desabilitado. Servidor permite valor inalterado a editor sem `can_transfer`, mas rejeita mudança. Tags que parecem nomes de roles não alteram policies.

## Não funcionais e casos de borda

Auditar transferências com origem/destino, ator e tempo. Verificar membership e versão dentro da mesma transação da gravação. Revogar membership entre GET e PATCH deve bloquear. Reaplicar policy aos endpoints antigos (`toggle`, `destroy`, import/copy) que também alteram ProblemBank; caso contrário a nova tela não resolve a vulnerabilidade de escopo.

## Critérios de aceite

- Admin organiza legado; editor A altera etiquetas de A, não modifica B nem transfere para si.
- Adicionar tag `admin` não muda capabilities.
- Filtro de organização e busca combinam sem vazar quantidade de itens invisíveis.
- Duas edições simultâneas não sobrescrevem silenciosamente; segunda recebe409 com campos preservados.
- Banco usado por competição/treino continua íntegro após troca do responsável.

## Perguntas para decisão

Confirmar organização com membership versus proprietário individual. Esta UI propõe organização; se o backend optar por owner_user_id, ajustar contrato/labels antes de habilitar. Coleções normalizadas e criação/gestão de organizações são fase própria; não há botão fictício para elas.
