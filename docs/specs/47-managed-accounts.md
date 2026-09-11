# #47 — Contas gerenciadas e privacidade

Status: **implementado** (backend real, sem fixtures). Endpoints, policy de
privacidade e fluxo de ativação abaixo existem em código e têm testes
automatizados (`tests/Unit/ProfilePrivacyPolicyTest.php`,
`tests/Feature/ManagedAccountsApiTest.php`,
`tests/Feature/AccountActivationTest.php`). As perguntas na seção "Perguntas
para decisão" permanecem em aberto e nada nesta entrega assume uma resposta
a elas.

## Contexto e objetivo

Permitir cadastro pela organização sem identidade externa e aplicar
privacidade por política etária. Interface administrativa
`/backend/managed-accounts`. A idade de 18 é requisito de produto proposto
pela issue; este documento não afirma que ela esgota obrigações legais ou de
consentimento.

## Regras e decisão pendente

- Cadastro gerenciado começa privado, incluindo nascimento não informado.
  Não solicitar nascimento no registro público existente nesta fase (o
  registro público em `/register` não foi alterado).
- birthdate é DATE nullable, sem timezone e sem derivar idade no cliente.
  **Decisão de timezone (implementada):** a idade é calculada em
  `config('app.timezone')` (variável `APP_TIMEZONE`, padrão `UTC`) — o mesmo
  fuso que o resto da aplicação usa em `now()`. **Decisão de 29/02
  (implementada e testada):** quem nasce em 29 de fevereiro completa cada
  idade em 1º de março nos anos não bissextos (não existe 29/02 nesse ano
  para "completar aniversário"). Ambas as decisões estão em
  `App\Support\ProfilePrivacyPolicy::ageStatus()`/`anniversary()`, com testes
  cobrindo o dia imediatamente antes/depois do aniversário e o caso 29/02
  (`tests/Unit/ProfilePrivacyPolicyTest.php`). Datas futuras ou impossíveis
  (ex.: `2023-02-30`) são rejeitadas na validação (`date_format:Y-m-d` +
  `before_or_equal:today`). Nascimento nunca aparece em listagem, placar,
  biblioteca ou perfil público — ver "Aplicação da policy" abaixo.
- Menor de 18: perfil/histórico privado e vinculação externa bloqueada. Data
  desconhecida: conservar restrição até revisão — implementado como o
  terceiro estado `unknown` em `ProfilePrivacyPolicy`, que nunca resolve para
  `adult`. UI exibe `privacy_locked` e `privacy_reason` retornados pelo
  servidor; o servidor nunca deduz a partir do nome/data no cliente.
- **Proposta que segue aguardando decisão do mantenedor:** aos 18, a
  implementação atual libera apenas a *elegibilidade*
  (`external_linking_allowed` passa a `true`) e mantém `visibility`/
  `profile_visibility` em `private` — não existe, nesta entrega, nenhum
  endpoint ou botão que publique o perfil (`profile_visibility` só é gravado
  como `'private'`; não há rota de escrita para outro valor). A exceção
  "admin torna menor público" citada na issue não foi implementada; segue
  sem política explícita. Ausência de resposta do mantenedor não foi tratada
  como aprovação de nada além do que está descrito aqui.
- Regras de visibilidade e elegibilidade são campos/conceitos separados:
  adulto pode continuar privado (é o único estado possível hoje). Aniversário
  não exige edição manual (recalculado a cada request, sem cache) e não gera
  anúncio público.

## API e dados (implementado)

`GET /api/frontend/managed-accounts?q=&page=1` — admin apenas (middleware
`auth`+`admin`, mesmo guard de sessão web das telas `/backend/*`; 403 para
outros papéis, 401 para visitante sem redirecionar para HTML). Resposta:
`{data:{capabilities:{can_create},contests:[{id,name,sites:[{id,name}]}],items:[{id,fullname,username,contest_name,site_name,privacy_locked,visibility,external_linking_allowed,privacy_reason}],meta}}`.
Nunca inclui birthdate, email, senha ou token (`birthdate` está em `$hidden`
no model `Helium\User`, então nenhuma serialização acidental o expõe — ver
"Aplicação da policy"). Busca administrativa por nome/usuário
(`q`, case-insensitive). Paginação: 20 por página.

`POST /api/frontend/managed-accounts` (`App\Http\Controllers\Api\Frontend\ManagedAccountsController::store`)
— `{fullname,username,birthdate:null|"YYYY-MM-DD",contest_id,site_id}` → 201
`{data:{id,activation_url}}`. `fullname` ≤255, `username` ≤80, único por
comparação case-insensitive (`LOWER(TRIM(username))`, não apenas o índice
único do banco). `site_id` precisa pertencer a `contest_id` (422 em
`site_id` caso contrário). Qualquer `user_type`/`visibility`/
`profile_visibility` enviado pelo cliente é ignorado — o servidor sempre
grava `user_type=team`, `profile_visibility=private`. Conta criada com
`is_enabled=false` até a ativação (ver abaixo). Idempotência obrigatória via
header `Idempotency-Key` (ver "Idempotência" abaixo) — sem o header, 400;
repetir a mesma chave com o mesmo payload replica a resposta original sem
criar conta duplicada; a mesma chave com payload diferente é 409.

### Fluxo de ativação (implementado, real — não é stub)

- Migration `account_activations`: `id, user_id, token_hash, expires_at,
  used_at, created_at, updated_at`. Só o hash SHA-256 do token é
  persistido.
- Criação gera um token aleatório de 64 caracteres
  (`Illuminate\Support\Str::random(64)`), grava `token_hash =
  hash('sha256', $token)` e `expires_at = now()->addHours(72)`
  (**decisão de prazo: 72 horas**, escolhida como padrão institucional
  razoável para entrega manual do link; ajustável em
  `ManagedAccountsController::store()` se o responsável pelo produto
  definir outro valor). `activation_url` retornado é `/activate/{token}`
  (token puro só existe nesta resposta única e na URL entregue).
- `GET /activate/{token}` e `POST /activate/{token}`
  (`App\Http\Controllers\AccountActivationController`) — **fora** do
  middleware `auth` (o usuário ainda não tem senha utilizável) e **fora**
  do prefixo `api/frontend` (não é uma tela JSON da SPA; é um formulário
  Blade normal com redirecionamento, conforme o contrato comum permite
  para este caso específico). `GET` mostra o formulário de definir senha ou
  uma mensagem de link inválido/expirado/usado, sem revelar qual dos três.
  `POST` revalida o token (existe, não expirado, não usado), define a senha
  real do usuário, marca `used_at`, habilita a conta (`is_enabled=true`) e
  faz login da sessão diretamente (`Auth::login`) — não reutiliza
  `/password/email` (que continua stub) nem `/login`.
  - **Login direto em vez de redirecionar para `/login`:** contas
    gerenciadas nunca têm e-mail (o payload de criação não coleta e-mail, só
    fullname/username/birthdate/contest/site), e a rota `/login` existente
    autentica por e-mail. Fazer login diretamente na sessão evita esse
    descasamento e ainda satisfaz o requisito de "logar o usuário ou
    redirecionar para login" do enunciado original. **Lacuna aberta e não
    resolvida nesta entrega:** como a conta não tem e-mail, ela não tem hoje
    nenhum caminho de login *futuro* (após a sessão expirar) pela tela
    `/login` atual. Isso não está coberto pelos critérios de aceite da
    issue #47 e não foi alterado aqui para não ampliar o escopo/risco de uma
    rota compartilhada; fica registrado como dependência para quem tratar
    login/autenticação de contas gerenciadas no futuro.
  - Reuso/expiração: qualquer tentativa após `used_at` preenchido ou após
    `expires_at` é rejeitada com uma mensagem genérica e não altera a senha
    nem o `used_at`. Rate limit: `throttle:30,1` nas duas rotas de ativação.
  - O token nunca aparece em listagem, log ou cache, e não permite trocar
    papel (`user_type` permanece `team`) ou concurso/site — o endpoint de
    ativação só grava senha, `is_enabled` e `used_at`.
- `capabilities.can_create` é `true` porque este fluxo de ativação é real e
  testado ponta a ponta (criação → visita ao link → definição de senha →
  login autenticado), não um stub.

### Robustez sob concorrência (revisão de código, implementado)

A checagem de `username` único e a checagem `isValid()` do token de ativação
são leituras simples, não locks — uma revisão de código identificou que duas
requisições verdadeiramente concorrentes (não apenas a mesma
`Idempotency-Key` retentada em sequência) poderiam ambas passar por essas
checagens antes de qualquer uma commitar. Correções aplicadas:

- `ManagedAccountsController::store()` envolve a transação de criação em
  `try/catch(QueryException)`: se a checagem prévia perder a corrida e o
  índice único do banco em `users.username` disparar, a exceção é
  convertida na mesma resposta 422 `{"errors":{"username":[...]}}` em vez de
  vazar como um 500 não tratado. Testado deterministicamente forçando a
  corrida via um listener `User::creating`
  (`test_concurrent_duplicate_username_race_returns_clean_422_not_a_500`).
- `AccountActivationController::store()` não faz mais "ler `isValid()`,
  depois salvar" — a marcação de uso é um único `UPDATE ...
  WHERE used_at IS NULL AND expires_at > now()` atômico. Só a requisição
  cujo `UPDATE` afeta a linha pode definir a senha; uma segunda submissão
  concorrente do mesmo token afeta zero linhas e é rejeitada com a mesma
  mensagem de link inválido/expirado, em vez de ambas concederem acesso.
- `contest_id`/`site_id` agora usam `Rule::exists(...)->whereNull('deleted_at')`
  (mesmo padrão já usado por `Backend\UserController::store()` para
  `site_id`) em vez de `exists:tabela,id` simples — um concurso ou local
  com soft-delete não pode mais ser associado a uma conta nova, o que antes
  passava porque a regra `exists` ignora `deleted_at`.
- Listagem: `privacy_locked`/`external_linking_allowed`/`privacy_reason`
  agora computam `ageStatus()` uma vez por usuário
  (`ProfilePrivacyPolicy::reasonForLockedState()`) em vez de até três vezes.

### Idempotência (implementado, mecanismo compartilhado)

`App\Support\IdempotencyStore::handle()` + tabela `idempotency_keys`
(`user_id, route, idempotency_key, payload_hash, response_status,
response_body`, únicos por `(user_id, route, idempotency_key)`). Introduzido
por esta issue mas escrito para ser reutilizado por qualquer futuro
POST/PATCH/DELETE de `/api/frontend/*`, conforme o contrato comum exige. A
checagem de chave repetida ocorre **antes** de rodar validação/regra de
negócio, para que uma retentativa idêntica após timeout não seja rejeitada
por um estado que a própria primeira tentativa já criou (ex.: "username já
em uso" contra a conta que ela mesma criou).

## Campos em `users` (implementado)

`birthdate` DATE nullable, `managed_by` nullable FK (`users.user_id`),
`profile_visibility` string default `'private'`, `managed_at` timestamp
nullable — migration
`database/migrations/2026_09_11_000200_add_privacy_fields_to_users_table.php`.
`birthdate` está em `$hidden` no model `Helium\User` (não apenas fora do
payload deste endpoint) e tem cast `date`. `isMinor()`/`isAdult()`/
`privacyLocked()`/`externalLinkingAllowed()` no model delegam para
`App\Support\ProfilePrivacyPolicy`, a policy central; nenhum deles jamais
resolve nascimento desconhecido como autorização pública. Mudança de
birthdate continua fora desta tela (não existe endpoint de edição).

## Aplicação da policy em outras saídas (issue #47, requisito transversal)

Ao adicionar a coluna `birthdate`, foi conferido onde `Helium\User` já é
serializado para JSON/array fora deste endpoint:

- `App\Models\Leaderboard::getScoreboard()` (usado pelo placar,
  `Api\ScoreboardController` e pelo `ScoreboardController` web) inclui
  `'user' => $entry->user`, ou seja, o model inteiro. Antes desta issue isso
  já vazava qualquer atributo não oculto do usuário; agora `birthdate` está
  em `$hidden`, então continua fora da resposta mesmo com a nova coluna.
- `Api\RunController` (`$run->load([..., 'user', ...])`) tem o mesmo padrão.
- `SiteController`/`StaffController` já usam `select` explícito
  (`user:user_id,fullname,username`) e nunca pediram `birthdate`.

Como a proteção está no model (`$hidden`), ela vale para qualquer código
atual ou futuro que serialize `Helium\User` sem selecionar colunas
explicitamente — inclusive #43 (Treino Livre/histórico) e #44 (exportação de
webcast), que ainda não existem como funcionalidades de backend. Quem
implementar essas issues deve chamar
`ProfilePrivacyPolicy::privacyLocked()`/`externalLinkingAllowed()` para
decidir o que expor por usuário, em vez de reimplementar a checagem de
idade — a dependência está registrada aqui propositalmente porque #47 não
constrói #43/#44.

## Requisitos e bordas

- Policy aplicada em todas as saídas relevantes hoje existentes (ver seção
  acima); #43/#44 ainda não existem para serem cobertas na prática, mas a
  policy está pronta para uso por elas.
- Token de ativação não vai para listagem/logs/cache nem permite trocar
  papel/concurso (implementado: hash apenas, sem log do token puro, sem rota
  que altere `user_type`/`contest_id`/`site_id`). Reuso/expiração negados;
  rate limit `throttle:30,1`. A entrega institucional (compartilhar o link
  com o participante) continua sendo um processo manual fora do sistema — a
  tela apenas mostra o link uma vez e permite ocultá-lo.
- Transição etária: sem cache de capabilities nesta entrega (cada resposta
  GET recalcula `ageStatus()` a partir do banco), então não há "cache para
  invalidar" — a permissão já é reavaliada a cada request, inclusive em
  qualquer futuro endpoint de publicação/vinculação que chamar a policy.
- Retenção/correção de nascimento e autorização para cadastro administrado
  continuam sem definição do responsável pelo produto — nenhuma tela de
  edição foi criada.

## Critérios de aceite

1. **Atendido.** Conta criada começa privada (`profile_visibility=private`,
   `is_enabled=false`) e sem vinculação externa; `visibility`/`user_type`
   enviados no corpo da requisição são ignorados
   (`tests/Feature/ManagedAccountsApiTest::test_created_account_starts_private_locked_and_ignores_manipulated_visibility_and_user_type`).
2. **Atendido.** Testes imediatamente antes/depois do 18º aniversário e do
   caso 29/02 em `tests/Unit/ProfilePrivacyPolicyTest.php`; desconhecido
   permanece restrito
   (`test_unknown_birthdate_remains_locked_regardless_of_time`).
3. **Atendido.** Participante/juiz/site recebem 403 na listagem
   (`test_participant_judge_and_site_get_forbidden_on_listing`); a listagem
   nunca inclui birthdate/email/senha/token
   (`test_listing_never_exposes_birthdate_email_password_or_token`).
4. **Atendido.** Site de outro concurso é 422 em `site_id`; username
   duplicado (case-insensitive) é 422 em `username`.
5. **Atendido.** Token expira (72h), é de uso único
   (`test_token_is_single_use`) e não concede privilégio administrativo
   (`test_activation_does_not_change_role_or_contest_and_grants_no_admin_privilege`).
   Repetição da mesma chave de idempotência não cria conta extra
   (`test_repeating_the_same_idempotency_key_and_payload_does_not_create_a_second_account`).

## Perguntas para decisão (ainda em aberto — não respondidas por esta entrega)

Confirmar comportamento aos 18 (eligibilidade liberada, `visibility`
permanece `private` é a proposta implementada, não uma decisão final),
exceção administrativa de publicação de menores (não implementada, sem
política), tratamento de nascimento desconhecido/29/02 (implementado
conforme decisões documentadas acima, mas ainda sujeito a revisão do
responsável pelo produto), prazo do token (implementado como 72h, ajustável)
e procedimento institucional de entrega do link (continua manual, fora do
sistema). Até definição em contrário, o comportamento desta entrega é:
manter privado e capacidades públicas desabilitadas.
