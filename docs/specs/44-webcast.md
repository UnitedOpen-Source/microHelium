# #44 — Transmissão BOCA e cerimônia

## Contexto e objetivo

Oferecer exportação do placar completo e credenciais revogáveis de leitura, limitadas a um concurso. Interface: `/backend/webcast`.

## Estado da implementação (backend)

Implementado nesta entrega: `GET/POST/DELETE /api/frontend/webcast*` (sessão admin + CSRF, `routes/frontend_api_webcast.php`), emissão/listagem/revogação real de credenciais (`webcast_credentials`, `App\Models\WebcastCredential`), o serializador BOCA completo (`App\Services\BocaWebcastZipBuilder`), o endpoint de export (`GET /api/frontend/webcast/export`) e um guard de autenticação por credencial totalmente separado (`App\Http\Middleware\AuthenticateWebcastCredential`) expondo só o placar descongelado do próprio concurso em `GET /api/webcast/scoreboard`. Testes: `tests/Feature/WebcastControllerTest.php`, `tests/Feature/WebcastExportTest.php` (formato de bytes verificado via round-trip real de ZIP), `tests/Feature/WebcastCredentialAuthTest.php` (isolamento entre concursos e entre endpoints).

`capabilities.can_export` permanece `false` por padrão (`config('webcast.export_enabled')`, `WEBCAST_EXPORT_ENABLED`) — ver "Gate de integração" abaixo; isso não bloqueou a implementação do endpoint em si, só a afirmação de compatibilidade.

**Mecanismo de autenticação do consumidor (decisão tomada):** `Authorization: Bearer <segredo>` verificado contra `webcast_credentials.token_hash` (sha256 do segredo, comparação com `hash_equals`), como uma pergunta aberta que a spec original deixava para o backend decidir. A rota correspondente não pertence aos grupos `web` nem `api` do Laravel — é registrada separadamente em `routes/webcast_consumer.php` via `bootstrap/app.php`, com sua própria pilha de middleware (`throttle:20,1` executando antes do guard, para não deixar tentativas erradas de token fora do limite; depois `webcast.auth`). O guard nunca chama `Auth::login()`, então uma credencial de transmissão não obtém identidade reconhecida por nenhum outro guard/rota do app — tentar reaproveitar o mesmo header em `/submit`, `/judge/runs`, `/backend/users` etc. falha exatamente como uma requisição anônima falharia.

## Escopo funcional

- Admin seleciona concurso, baixa webcast e emite credencial com rótulo e validade. Listar somente metadados; segredo aparece apenas na resposta de criação, permanece só em memória/DOM até ocultar/sair e nunca em localStorage/query string.
- Revogação exige confirmação e efeito imediato, inclusive invalidando caches. Role/scoped principal de transmissão lê placar descongelado do seu concurso e nada mais: não pode submeter, listar problemas/testes, julgar, gerenciar usuários ou usar outras APIs administrativas.
- Não reutilizar role admin nem mudar o comportamento do placar normal. Gestão de fotos/música não está nesta fase.

## API da interface

`GET /api/frontend/webcast?contest_id=&page=1`: `{contests:[{id,name}],contest:null|{id,name},capabilities:{can_export,can_manage_credentials},export_url:null|string,items:[{id,label,status:"active"|"expired"|"revoked",expires_at,last_used_at:null|string}],meta}`. Sem seleção válida retornar contest null/itens vazios. Só listar concursos administráveis. `export_url` é caminho local, autenticado por sessão de admin, sem token no URL.

`POST /api/frontend/webcast/credentials`: `{contest_id,label,expires_at}` → 201 `{data:{id,secret}}`. label 1–80, validade futura com teto configurável. UI envia instante ISO8601 UTC a partir do horário local. 422 por campo. Repetição idempotente precisa reconciliar metadados sem reexibir segredo já entregue; se emissão ficou ambígua, retornar 409 e instruir revogar/reemitir, ou manter resposta cifrada temporária com política definida — nunca armazenar token em claro indefinidamente.

`DELETE /api/frontend/webcast/credentials/{id}` → 200 `{data:{id,revoked:true}}`. Idempotente; credencial de outro concurso/ator 403. GET com segredo ativo, expirado ou revogado deve ser indistinguível na mensagem pública de negação.

`GET {export_url}` → application/zip, Content-Disposition attachment, Cache-Control no-store. Erro retorna página de erro própria com status adequado, não HTML dentro de ZIP. Cliente usa download nativo sem anunciar conclusão falsa.

Dados implementados: `webcast_credentials(id,contest_id,label,token_hash,expires_at,revoked_at,last_used_at,created_by)` (migration `2026_09_11_000200_create_webcast_credentials_table.php`). Segredo gerado com `Str::random(40)`, armazenado só como `sha256`; comparação com `hash_equals`; rate limit `throttle:20,1` na leitura do consumidor, aplicado antes do guard (ver acima). Sem token na URL — o consumidor usa `Authorization: Bearer`, nunca query string.

Idempotência de `POST /credentials` implementada via `App\Services\IdempotencyGuard` (tabela genérica `idempotency_keys`, reaproveitável por outras rotas `/api/frontend/*`): mesma chave + mesmo payload reconcilia metadados sem repetir o segredo (campo `secret` vem `null` na repetição); mesma chave + payload diferente → 409; uma corrida entre duas requisições com a mesma chave (colisão na constraint única) cai no mesmo caminho de reconciliação em vez de estourar erro. Uma falha de validação (422) libera a chave imediatamente para nova tentativa.

## Formato BOCA: evidência e limite conhecido

Fonte primária consultada em 10/09/2026: [BOCA src/admin/report/webcast.php](https://github.com/cassiopc/boca/blob/master/src/admin/report/webcast.php). Os bytes foram lidos via conteúdo base64 da API do GitHub: separador de campos **FS, byte 0x1C**, não vírgula nem os caracteres visíveis `^\` que alguns terminais exibem.

O produtor gera arquivos `contest`, `runs`, `version`, `time`, `icpc` no ZIP. `version` contém `1.0` com newline. `contest` contém nome, duração/lastmileanswer/lastmilescore/penalidade em minutos, quantidades de equipes/problemas, linhas de equipe (ID, instituição, nome) e linhas de configuração finais. `runs` tem ID, tempo convertido para minutos, ID da equipe, problema e resultado; códigos observados: Y aceito, ? pendente, X para erros não penalizados específicos, N para demais respostas. Fonte consulta resultado completo, sem corte de freeze. `icpc` está vazio nesse produtor.

**Não copiar cegamente as constantes contest/site=1 do script legado.** Mapear IDs multi-site de forma estável, impedir colisões e validar que todos os runs referenciam equipes do mesmo export. Nome/instituição não podem conter FS/newlines que corrompam registros; preservar acentos no encoding acordado. Definir mapeamento CE/CS/X e unidades de `time` por fixture real do consumidor; `dateconvminutes` e `$st.currenttime` precisam ser seguidos até sua definição antes de finalizar o serializador. O script não constitui sozinho um teste de compatibilidade.

A URL [Animeitor indicada na issue](https://github.com/cassiopc/animeitor-rs) retornou 404 nesta revisão. **Gate de integração:** obter repositório/release correto ou binário+fixture conhecido; fixar commit do produtor e versão do consumidor e importar ZIP gerado em teste. Até isso, manter `can_export=false`. Não afirmar compatibilidade homologada.

**O que este PR implementou como interpretação própria, documentada e não confirmada por fixture real** (`App\Services\BocaWebcastZipBuilder`), além dos campos que o parágrafo acima já cita textualmente:

- `lastmileanswer`/`lastmilescore` gravados como `0` (sem equivalente no modelo `Contest` desta aplicação).
- Uma linha por problema em `contest` (`id`, `short_name`, `name`) além das linhas de equipe — a spec só cita a *contagem* de problemas, não linhas de detalhe; `runs` precisa referenciar o problema por algum identificador, então este serializador usa o `id` numérico do problema.
- "Linhas de configuração finais" reduzidas a uma única linha com a contagem de sites.
- Mapeamento de resultado: `CE` (Compilation Error) é o único código tratado como X (erro não penalizado); qualquer outra resposta não aceita é `N`.
- Encoding UTF-8 (acentos preservados, sem transliteração).
- Runs de contas que não são do tipo `team` (ex.: um admin criando uma run de teste via `POST /api/runs`) são excluídas do arquivo `runs` em vez de abortar o export inteiro.

Qualquer uma dessas escolhas pode estar errada frente ao consumidor real — é exatamente o que o gate de integração abaixo existe para resolver. `tests/Feature/WebcastExportTest.php` verifica o formato byte a byte (separador FS, contagens, mapeamento de resultado, sanitização, concurso vazio, multi-site com acentos) contra ESTA implementação, não contra um Animeitor real.

## Critérios de aceite

- ✅ Export autorizado contém o placar descongelado (`Leaderboard::getScoreboard()`, sem corte de freeze); placar público (`/scoreboard`) não foi alterado.
- ✅ Token de A não lê B (`WebcastCredentialAuthTest::test_credential_cannot_read_another_contests_scoreboard_only_its_own`), não submete/julga/gerencia usuários (mesma classe, várias rotas testadas); expirado/revogado falha imediatamente com a mesma mensagem de token inexistente.
- ✅ Segredo ausente em GET/listagens (`WebcastControllerTest`); nunca logado (não passa por nenhum `Log::`/`report()` neste código); revogação repetida é segura (idempotente). Disputa revogar/reemitir: política escolhida é reconciliação sem segredo + 409 em corrida ambígua — ver "Dados implementados" acima; documentada, não "atômica" no sentido de lock distribuído (não há necessidade real de um nesta escala).
- ⏳ **Não verificado**: ZIP importado por uma versão real e fixada do consumidor Animeitor. `tests/Feature/WebcastExportTest.php` verifica o formato byte a byte (separador FS, contagens, resultado, sanitização, concurso vazio, multi-site+acentos) desta implementação isoladamente — ver gate de integração acima. `can_export` permanece `false` até isso ser resolvido.
- ✅ Nenhum campo de nascimento, e-mail ou fonte entra no arquivo (`BocaWebcastZipBuilder` só lê `user_id`, `fullname`, `site.name`, dados de `Run`/`Answer`/`Problem` já públicos no placar). Política #47 de nome público de equipe não foi alterada por este PR — quando #47 mudar o nome exibido, `BocaWebcastZipBuilder::teams()` deve ser revisado para usar o mesmo campo.

## Perguntas para decisão

Qual versão/repositório do Animeitor será suportado? Qual limite de validade e mecanismo de autenticação ele aceita? Estas decisões bloqueiam apenas a homologação do export, não a implementação da tela — **isto continua em aberto** após este PR.

Decisões já tomadas nesta entrega (documentadas acima, revisáveis quando o consumidor real for confirmado): mecanismo de autenticação = `Authorization: Bearer` contra `sha256(token)`; teto de validade padrão = 30 dias (`WEBCAST_MAX_CREDENTIAL_LIFETIME_DAYS`); política de repetição idempotente = reconciliar metadados sem repetir o segredo.
