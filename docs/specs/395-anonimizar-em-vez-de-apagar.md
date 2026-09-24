# #395 — Anonimizar em vez de apagar, e exportar os próprios dados

## O defeito

`Backend\UserController::destroy()` chamava `$user->delete()`. `Helium\User`
não usa `SoftDeletes` (a coluna `users.deleted_at` existe desde 2017, mas o
model não a lê), então era um `DELETE` de verdade, e o banco fazia o resto.
As chaves estrangeiras para `users.user_id` com `cascadeOnDelete()`:

| Tabela | Migration |
|---|---|
| `runs` | `2025_11_25_000008_create_runs_table.php` |
| `clarifications` | `2025_11_25_000009_create_clarifications_table.php` |
| `tasks` | `2025_11_25_000010_create_tasks_table.php` |
| `backups` | `2025_11_25_000011_create_backups_table.php` |
| `scores`, `leaderboard` | `2025_11_25_000013_create_scores_table.php` |
| `account_activations` | `2026_09_11_000201_create_account_activations_table.php` |
| `organization_memberships` | `2026_09_11_010001_create_organization_memberships_table.php` |
| `sos_calls` | `2026_09_14_000100_create_sos_calls_table.php` |

`contest_logs` (tabela `logs`) é `nullOnDelete`: a trilha ficava, sem dizer
de quem era.

Consequência: atender um pedido de exclusão de uma equipe **tirava a linha
dela do placar de uma prova finalizada** e subia todo mundo que estava abaixo.
E excluir um administrador apagava as cópias de segurança que ele tinha
gerado (`backups.user_id`), que são dumps do banco inteiro, não dados dele.

## O contrato

### "Excluir" passa a ser anonimizar

`DELETE /backend/users/{id}` continua sendo a rota, com as mesmas duas
guardas (#100: não a própria conta, não o último administrador). O que ela
faz agora é `App\Services\AccountAnonymizer::anonymize($user, $actor)`, numa
transação:

**Some da linha de `users`:**

| Coluna | Vira |
|---|---|
| `fullname` | `Participante {user_id}` (pseudônimo estável; o `user_id` já é o `id` público da equipe na Contest API) |
| `username` | `anonimo-{user_id}` |
| `email`, `birthdate`, `icpc_id`, `description`, `permitted_ip`, `last_ip`, `last_login_at`, `remember_token` | nulo |
| `password` | hash de 64 caracteres aleatórios que ninguém conhece |
| `profile_visibility` | `private` |
| `is_enabled` | `false` — derruba sessão viva pelo `EnsureAccountIsEnabled` e recusa token |
| `anonymized_at`, `anonymized_by` | quando e quem (colunas novas) |

**Some de outras tabelas:**

- Código-fonte dos envios: o arquivo em `storage/app` é apagado;
  `runs.source_file` vira `''` e `runs.filename` vira `submission.<ext>` (o
  nome original podia ser o nome da pessoa, e ele também estava no caminho).
- Arquivos de impressão (`tasks.file_path`): apagados, coluna a nulo.
- Tokens de API, links de ativação/redefinição, chaves de idempotência (o
  corpo de resposta guardado pode ter dados da conta), vínculos de editora de
  acervo (`organization_memberships`), `password_resets` do e-mail antigo.
- `logs.ip_address` das linhas da própria conta.

**Fica, de propósito:**

- `runs` (linhas, tempos, vereditos, `source_hash`), `scores`, `leaderboard`:
  é o placar. `contest_id`, `site_id`, `user_type`, `label` e
  `organization_id` também ficam, porque são o que põe a linha no placar e o
  que o placar mostra ao lado dela (sede, instituição, número do crachá).
- `clarifications`: a pergunta pode ter sido respondida para todos
  (`broadcast_all`), e o que os outros leram faz parte da prova.
- `sos_calls`: registro operacional da sede.
- `backups`: são dumps do banco inteiro. Continuam contendo o dado anterior à
  anonimização — ver "fora".

**Registra:** uma linha `info` em `contest_logs` para cada prova em que a
conta competiu ou estava inscrita, com o `user_id` de quem anonimizou.

**Placar:** `ScoreboardTeams::competitors()` excluía conta desabilitada. Uma
conta anonimizada é desabilitada, mas competiu; ela passa a entrar pelo
`anonymized_at`. Sem isso, uma equipe sem nenhum envio julgado (sem linha em
`leaderboard`) sairia do placar ao ser anonimizada.

**Irreversível:** editar a conta anonimizada e gerar link de redefinição para
ela são recusados. Um link de redefinição reabilitaria a conta
(`AccountActivationController` faz `is_enabled => true`), e a conta voltaria a
ser de alguém com o nome de um pseudônimo. Anonimizar de novo é inócuo.

**Administrador anonimizado não conta como administrador** em
`isLastAdmin()`: senão, com um administrador ativo e um anonimizado, o ativo
poderia se rebaixar e trancar a instalação.

### Exportação dos próprios dados (LGPD art. 18, II e V)

`GET /profile/export` (autenticado, só a própria conta) devolve um JSON para
download com:

- `conta`: os campos da linha de `users` que dizem respeito à pessoa,
  inclusive `birthdate` e `last_ip` (o `$hidden` do model existe para não
  vazar para terceiros; aqui o leitor é o titular).
- `submissoes`: cada envio, com o código-fonte (texto, ou base64 se binário,
  pelo mesmo critério do #268) e o veredito **com a mesma máscara** da tela
  de submissões (#138): veredito ainda retido pela verificação não sai por
  aqui.
- `esclarecimentos`: perguntas da conta; a resposta só quando o status é de
  respondida.
- `impressoes`: pedidos de impressão (metadados, sem o arquivo).
- `chamados_sos`.

**Não entra:** `scores`/`leaderboard`. São derivados dos envios, e o placar é
público; exportar a linha de `scores` durante a verificação vazaria o
veredito que a máscara acima esconde.

## Casos de teste

`tests/Feature/AccountAnonymizationTest.php`

1. Placar de prova **finalizada**, com três equipes (uma sem envio julgado),
   é idêntico antes e depois de "excluir" a primeira colocada: posição,
   `user_id`, resolvidos, tempo, células. Rodado contra o `master`, reprova:
   a primeira colocada some e a segunda sobe para `rank` 1.
2. A linha de `users` fica, sem nome, e-mail, login, nascimento, `icpc_id`,
   IP; senha não é mais a antiga; `anonymized_at`/`anonymized_by` preenchidos.
3. O arquivo do código-fonte some do disco; a linha do `run` fica com o
   veredito.
4. Tokens, ativações e vínculos de editora somem.
5. Há registro em `contest_logs`.
6. Conta anonimizada não é editável nem recebe link de redefinição.
7. As guardas antigas continuam (a própria conta; o último administrador), e
   administrador anonimizado não conta como administrador.
8. Cópias de segurança de um administrador anonimizado continuam.

`tests/Feature/PersonalDataExportTest.php`

1. Exige login.
2. Traz só os dados da própria conta (não os de outra equipe).
3. Traz o código-fonte.
4. Veredito retido pela verificação não sai.
5. Resposta de esclarecimento só quando respondida.
6. Fonte binário vai em base64 e volta byte a byte.

## Fora deste PR

- **Aviso de privacidade configurável** (proposta 3 da issue) e **retenção
  configurável** (proposta 4): cada um é uma feature com tela de
  configuração própria. Ficam para depois.
- **Base legal na conta gerenciada** (proposta 5).
- **Dumps de `backups`** continuam com os dados anteriores à anonimização.
  Apagar ou reescrever dump é uma decisão de retenção, e pertence à proposta 4.
- **Pacote de resultados (#271)**: gerado depois da anonimização, ele traz o
  pseudônimo no lugar do nome. Se o pacote deve ser gerado e guardado antes é
  a pergunta 1 da issue, e continua com o mantenedor.
- **Trocar `cascadeOnDelete` por `restrictOnDelete`** nas migrations: blindaria
  o banco contra um `delete()` futuro, mas é uma migration que altera chaves
  estrangeiras em nove tabelas, e merece PR próprio. Hoje o único caminho de
  aplicação que apagava usuário era este `destroy()` (conferido com
  `grep -rn "delete()" app`).
- Conteúdo livre que pode citar a pessoa (`clarifications.question`,
  `sos_calls.note`, `logs.context`) não é reescrito.
