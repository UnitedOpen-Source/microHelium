# #48 — Consolidar autorização sem mudar comportamento

## Contexto e objetivo

Judge, Staff e Site repetem bypass de admin seguido de comparação de escopo. Centralizar a regra mecânica preservando as restrições específicas já existentes. O PR #57 já integrou `Controller::authorizeScopedAccess(userScope, resourceScope, message)` e call sites durante este trabalho. Nenhuma mudança de controller/model foi feita pela entrega de frontend. Estados403 das novas interfaces já estão implementados.

O helper atual compara valores com `!==` e permite igualdade null/null. Negar escopo nulo, sugerido abaixo, é hardening adicional a validar separadamente, não descrição do comportamento entregue pela #57.

## Escopo funcional e fronteiras

- Inventariar `JudgeController::authorizeRunAccess`, `StaffController::authorizeTaskAccess`, `SiteController::authorizeSiteAccess` e todos os call sites na base mais recente.
- Extrair helper/trait/policy pequena com nomes de coluna definidos em código; nunca aceitar coluna/resourceLabel arbitrários da requisição. Resolver diferenças de tipo de ID de forma consistente com casts do ORM; não trocar `!==` por `!=` sem testes.
- Admin mantém bypass existente. Usuário não autenticado continua no middleware; helper não deve causar null dereference nem tornar null==null autorização implícita. Escopo ausente deve negar.
- **Não absorver/remover as regras adicionais de roteamento de sites e visibilidade da #41.** Comparação de contest_id é necessária, mas pode não ser suficiente para julgar um run. Nenhuma herança nova de privilégios entre judge/staff/site.
- Refatoração não muda query scoping de listagens nem policies de acesso à fonte. Uniformizar mecanismo não significa liberar ações cruzadas.

## Contrato com a interface

Página HTML negada: status403 e `errors/403.blade.php`, com caminho para início/ajuda. JSON negado: status403, mensagem genérica, sem nome/ID privado do recurso alheio. Sessão ausente:401 em JSON; redirect login para HTML. Não devolver200 com array vazio para uma ação proibida nem simular sucesso ao ocultar um botão.

Frontend das features trata403 sem botão de repetir, oferece ajuda, mantém ações indisponíveis e não tenta outro endpoint para contornar permissão. Capabilities são orientações da UI; autorização é novamente verificada em cada mutação.

## Matriz mínima de regressão

| Ator/recurso | Esperado |
| --- | --- |
| Admin, recurso de outro concurso/site | Mesmo bypass atual |
| Judge, run no concurso e site roteado | Mesma autorização atual |
| Judge, concurso diferente ou site não roteado |403 |
| Staff, tarefa de seu escopo | Mesma autorização atual |
| Staff, tarefa de outro concurso |403 |
| Site, equipe/tarefa/clarificação de seu site | Mesma autorização atual |
| Site, outro site no MESMO concurso |403 |
| Team/spectator em ação gerencial |403 |
| Usuário com escopo nulo, recurso com escopo nulo | Negar; sem autorização acidental |
| IDs em casts diferentes | Resultado consistente com IDs válidos e política existente |
| Usuário sem sessão | Redirect HTML/401 JSON, sem500 |

## Critérios de aceite

Executar `JudgeControllerTest`, `JudgeSiteRoutingTest`, `StaffControllerTest`, `SiteControllerTest`, `ScoreboardSiteVisibilityTest` e testes das novas rotas. Manter respostas e mutações idênticas nos casos permitidos; garantir zero gravações nos proibidos. Não alterar migrations/API/domain da #42–47 no mesmo refactor mecânico.

## Perguntas para decisão

Nenhuma decisão de produto necessária para a extração preservando semântica. Logging de negações ou mudança no bypass de admin são mudanças separadas, com review próprio; não incluí-las implicitamente nesta issue.
