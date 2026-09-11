# Frontend e contratos de integração — issues #42–48, #49, #53 e #54

Status: frontend implementado; endpoints e regras de domínio propostos para o time de backend. As issues de funcionalidades permanecem abertas até a entrega integrada; #45 e #48 foram encerradas pelo backend durante este trabalho. Escopo anunciado em cada issue antes da implementação. Base inicial: `06960b4`; branch atualizada com `91821f2`, incluindo watchdog #56, autorização #57 e correções de API/filas até #67.

## Mapa de entrega

| Issue | Interface entregue | Spec |
| --- | --- | --- |
| [#42](https://github.com/UnitedOpen-Source/microHelium/issues/42) | `/backend/similarity`: solicitar análise, estados e pares | [Similaridade](42-similarity.md) |
| [#43](https://github.com/UnitedOpen-Source/microHelium/issues/43) | `/practice`, `/practice/problems/{id}`, `/practice/history`; publicação no banco | [Treino Livre](43-practice.md) |
| [#44](https://github.com/UnitedOpen-Source/microHelium/issues/44) | `/backend/webcast`: emissão/revogação real de credenciais e export BOCA implementados (`can_export` mantido `false` até homologação do consumidor Animeitor) | [Transmissão](44-webcast.md) |
| [#45](https://github.com/UnitedOpen-Source/microHelium/issues/45) | `/judge/health`: situação, limites, atividade e recuperação | [Watchdog](45-watchdog.md) |
| [#46](https://github.com/UnitedOpen-Source/microHelium/issues/46) | `/backend/bank-governance`: responsável, etiquetas e capacidades | [Propriedade](46-bank-ownership.md) |
| [#47](https://github.com/UnitedOpen-Source/microHelium/issues/47) | `/backend/managed-accounts`: cadastro privado e restrições | [Contas gerenciadas](47-managed-accounts.md) |
| [#48](https://github.com/UnitedOpen-Source/microHelium/issues/48) | Estado 403 compartilhado; nenhuma regra alterada | [Autorização](48-authorization.md) |

`/backend/tools` reúne as ferramentas. Os links também partem das páginas existentes de usuários, banco e julgamento. As rotas GET de apresentação ficam em `routes/frontend.php`. Não há migrations, jobs ou endpoints de dados implementados neste PR.

## Atualização durante a implementação

Novas issues abertas foram revisadas antes da entrega, com comentário de escopo antes de escrever as specs:

| Issue | Entrega/impacto |
| --- | --- |
| [#49](https://github.com/UnitedOpen-Source/microHelium/issues/49) | [Isolamento obrigatório do executor](49-judge-isolation.md): dependência para habilitar envio; sem alteração de runtime neste PR |
| [#53](https://github.com/UnitedOpen-Source/microHelium/issues/53) | [Julgamento distribuído](53-distributed-judging.md): ADR/fases e contratos; sem infraestrutura ou tela fictícia de agentes |
| [#54](https://github.com/UnitedOpen-Source/microHelium/issues/54) | [Rollback e smoke](54-deployment-verification.md): spec de release incluindo assets; nenhum deploy realizado |

#45 já tem `reconcile_attempts`, comando e job único; faltam o endpoint de health e dados de atividade/recuperação deste contrato. #48 já tem helper compartilhado; sua spec registra regressão e requisitos adicionais propostos, sem afirmar que todos estejam implementados.

## Contrato comum proposto v1

- Prefixo `/api/frontend`, sessão web da própria aplicação, JSON `Accept: application/json`, sem bearer token no navegador. **Registrar estes endpoints no grupo `web` com prefixo `api/frontend`, sessão + CSRF e middleware de papel**, ou configurar explicitamente Sanctum stateful equivalente. O grupo atual `routes/api.php` usa `auth:sanctum`, e apenas adicionar rotas ali não garante a sessão web existente.
- Toda resposta de sucesso JSON: `{ "data": { ... } }`. Listas: `data.items` e `data.meta = { current_page, last_page, total }`. Ações também devolvem objeto `data`, inclusive DELETE; não retornar 204 para este cliente.
- GET 200; criação 201; enfileiramento 202; PATCH/DELETE 200. 401 para sessão ausente, 403 para escopo negado, 404 para recurso inexistente/não publicado, 409 para versão conflitante/operação concorrente, 419 CSRF, 422 `{message, errors:{campo:[mensagem]}}`, 429 com Retry-After, 503 para feature indisponível. Nunca redirecionar JSON para HTML de login.
- Paginação: `page` >= 1, 20 registros por página (limite máximo 100 no servidor). Ordenação estável com ID como desempate. `meta.total` respeita busca e autorização. IDs numéricos ou strings numéricas; datas ISO8601 UTC com timezone. UI converte para horário local, sem interpretar data de nascimento como instante.
- Texto é texto: sem HTML em `statement`, nomes, etiquetas, mensagens ou erros. Links retornados são caminhos relativos iniciados por `/`, da mesma origem, autorizados pelo servidor. Não retornar caminhos de arquivos do worker, dumps, fontes de outras equipes ou segredos em listagens.
- Capabilities controlam a apresentação, **não a autorização**. Revalidar papel, objeto, concurso/local e versão em cada ação. Reconsultar depois de ações confirmadas.
- POST/PATCH/DELETE usam `X-CSRF-TOKEN` e `Idempotency-Key`. Backend deve persistir resultado/ID por ator+rota+chave e rejeitar reutilização com payload diferente. Cliente impede duplo envio e conserva a chave em retentativas do mesmo payload após falha ambígua durante a vida da página. Nova página requer reconciliação pelo backend e consulta antes de repetir. Não depender apenas do botão desabilitado.
- Timeout do cliente: 20 segundos. Jobs retornam 202 rapidamente; processamento prossegue fora da requisição. Cancelar GET obsoleto; filtros preservam query string. Atualização de jobs é manual e explícita nesta fase, sem polling em background.
- Não logar fonte, nascimento, credenciais ou tokens. Respostas privadas e exportações: `Cache-Control: no-store`; exceções genéricas no cliente e detalhes somente em logs restritos sem dados sensíveis.
- Recurso não entregue: 404/503 e UI de indisponibilidade com tentativa novamente. Não devolver fixtures nem `can_*:true` antes da implementação efetiva.

## Exemplos e divisão de responsabilidade

[Fixtures executáveis](../../tests/Frontend/fixtures/features.json) contêm respostas GET completas para todas as telas, **exclusivamente para testes/prévia local**. Os schemas descritos nas specs são o contrato proposto; fixtures não são dados nem autorização de produção. Tipos/campos novos precisam ser implementados pelo backend antes da ativação.

Ordem sugerida: preservar #48 já integrada → #46 e #47 → #43, com isolamento #49 como gate de ativação de envios; #42, #44 e #45 são independentes entre si. #43 depende da privacidade de #47 antes de expor estatísticas por pessoa. #44 depende da confirmação de versão do consumidor Animeitor.

## Aceite transversal

1. Navegar só por teclado; campos com labels, erros associados e foco no primeiro erro; código preservado em falha; revogação/publicação com confirmação.
2. Testar 375, 768 e 1440 px, claro/escuro, zoom e nomes longos; nada depende apenas de cor. Tokens existentes: 4,5:1 em texto e 3:1 em controles/foco.
3. Testar 401/403/404/409/419/422/429/503, resposta não JSON, queda de conexão, resposta atrasada, listas vazias, último item de paginação e duplo clique.
4. Backend testar isolamento entre concursos, locais e usuários, inclusive IDs adivinhados e permissões revogadas entre GET e POST.
5. Contratos GET devem incluir arrays vazios e capacidades false quando não há opções. Não exibir zero como substituto de uma estatística desconhecida.

## Validação e prévia

- `npm run test:frontend`: testes de comportamento dos componentes Vue reais (compilados por `@vue/compiler-sfc`), formulários compartilhados e contraste. Requer Node 24 (o projeto já usa Vite 8).
- `npm run build`: chunks das funcionalidades carregados somente quando a página correspondente abre.
- `php vendor/bin/phpunit tests/Feature/FeatureWorkspaceTest.php --no-coverage`: rotas/papéis/ausência de mutações fictícias. Este último teste caracteriza a entrega sem backend e deverá ser substituído por testes de contrato quando os endpoints forem implementados.
- Prévia: preparar banco local de desenvolvimento, rodar `php artisan serve --host=127.0.0.1 --port=8018` e `node tests/Frontend/preview-server.mjs`; abrir `http://127.0.0.1:8020/practice`. O proxy tem banner de dados fictícios e **recusa mutações das features**; demais rotas usam a aplicação local, portanto usar apenas banco de desenvolvimento. Não publicar esse servidor.

## Decisões de produto ainda abertas

- #47: proposta adotada na UI é liberar a elegibilidade aos 18 sem tornar dados públicos automaticamente; pergunta enviada ao mantenedor. Sem resposta, a spec conserva essa proposta e exige confirmação antes do backend implementar transição pública.
- #44: URL do Animeitor na issue devolveu 404 em 10/09/2026. Gerador BOCA oficial foi consultado; compatibilidade com uma versão real do consumidor ainda deve ser comprovada.
- Organização proprietária foi escolhida como modelo proposto da #46; etiquetas seguem array. Mudança para `owner_user_id` requer revisão conjunta do contrato antes de implementar.
