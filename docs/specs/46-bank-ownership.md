# #46 — Propriedade do banco e etiquetas

## Status de implementação

Backend implementado nesta entrega: migrations (`organizations`,
`organization_memberships`, `problem_bank.owning_org_id`/`version`,
`problem_bank_ownership_transfers`), `App\Models\Organization`,
`App\Models\OrganizationMembership`, `App\Models\ProblemBankOwnershipTransfer`,
`App\Policies\ProblemBankPolicy`, e `App\Http\Controllers\FrontendApi\BankGovernanceController`
servindo `GET`/`PATCH /api/frontend/bank-governance` (sessão web + CSRF, ver
`routes/frontend_api_bank_governance.php`). A policy também foi reaplicada
aos endpoints legados que alteram `problem_bank`
(`ProblemBankController::toggle()`/`destroy()`, e os dois closures de import
BOCA em `routes/web.php`) — hoje esses endpoints já ficam atrás do
middleware `admin`, então a checagem de policy ali é defesa em profundidade
(não fecha uma brecha hoje explorável), mas garante que a mesma regra de
propriedade se aplique se esse gate de rota for afrouxado no futuro.

Decisões de implementação:
- `version` é um inteiro monotonicamente crescente (coluna `problem_bank.version`,
  serializado como string na API), não `updated_at` — evita colisão de duas
  escritas no mesmo segundo. Ao dar 409, o servidor não devolve a versão
  atual (apenas sinaliza "desatualizado, recarregue").
- Etiquetas: apara espaços internos repetidos e bordas, deduplica
  comparando em minúsculas mas preserva a grafia da primeira ocorrência
  para exibição. Limite de 20 etiquetas, 40 caracteres por etiqueta, 500
  caracteres somando o comprimento bruto de todos os elementos recebidos
  (aproximação server-side do limite de 500 do campo de texto do
  formulário, já que a API recebe um array, não o texto bruto). Todo erro
  de etiqueta é reportado sob a chave `tags` (nunca `tags.3`).
- Toggle (`is_active`) também incrementa `version`, para que uma edição de
  etiquetas/proprietário concorrente com um toggle admin não sobrescreva
  silenciosamente.
- `problem_bank_ownership_transfers` (auditoria) usa `nullOnDelete()` em
  toda FK: o rastro de auditoria sobrevive à exclusão do item, do ator ou
  da organização (só a coluna referenciada vira null). Esse comportamento é
  imposto pelo banco de produção (MySQL); não é exercitado pela suíte de
  testes porque a conexão sqlite de teste (`config/database.php`) nunca
  liga `foreign_key_constraints`, então nenhuma ação `ON DELETE` roda ali
  para nenhuma tabela do projeto — não é algo específico desta feature.
- Escopo de listagem: admin vê tudo; um usuário sem papel de admin vê
  apenas organizações onde é membro `editor` e apenas itens dessas
  organizações — itens legados (`owning_org_id` null) ficam
  administráveis somente por admin. Filtrar por uma organização fora do
  escopo do usuário, ou por `unassigned` sendo não-admin, devolve `200`
  com lista vazia (nunca 403/404), para não vazar a existência/contagem de
  itens invisíveis.

Ainda não implementado / decisão em aberto:
- **Organização com membership vs. `owner_user_id`**: esta entrega
  implementou o modelo de organização com membership `editor`, conforme a
  proposta original desta spec. Isso ainda é uma decisão pendente de
  confirmação do mantenedor (ver "Perguntas para decisão" abaixo) — a
  proposta foi implementada porque a spec já a adotava como padrão,
  não porque a pergunta foi respondida.
- **Criação/gestão de organizações**: não há endpoint para criar, editar,
  arquivar ou adicionar/remover membro de organização nesta entrega —
  confirmado como fase própria pela spec original. `organizations` e
  `organization_memberships` só podem ser povoadas fora da UI (seed/tinker)
  até essa fase existir. `organizations.archived_at` já existe na
  migration para suportar "preferir arquivar organização com histórico"
  quando essa fase for implementada, mas nada a lê ou escreve ainda.
- **`practice_status`/`can_publish`**: sempre `"unpublished"`/`false`.
  Isso depende inteiramente da #43 (Treino Livre), que não está
  implementada neste backend — não existe hoje nenhuma publicação real
  para refletir, então esta não é uma resposta fictícia, é a resposta
  correta dado o estado atual do sistema. Quando a #43 existir, este
  contrato precisa ser revisitado para computar `practice_status`/
  `can_publish` de verdade.
- A rota de página `/backend/bank-governance` (`routes/frontend.php`)
  continua atrás do middleware `admin`, sem alteração nesta entrega — só a
  API (`/api/frontend/bank-governance`) já autoriza por policy
  (admin-bypass ou membership `editor`), preparada para quando/se uma tela
  de colaboração não-admin for habilitada.

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
