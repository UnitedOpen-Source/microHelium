# #44 — Transmissão BOCA e cerimônia

## Contexto e objetivo

Oferecer exportação do placar completo e credenciais revogáveis de leitura, limitadas a um concurso. Interface: `/backend/webcast`. O endpoint de ZIP e a identidade de transmissão ainda precisam ser implementados pelo backend.

## Escopo funcional

- Admin seleciona concurso, baixa webcast e emite credencial com rótulo e validade. Listar somente metadados; segredo aparece apenas na resposta de criação, permanece só em memória/DOM até ocultar/sair e nunca em localStorage/query string.
- Revogação exige confirmação e efeito imediato, inclusive invalidando caches. Role/scoped principal de transmissão lê placar descongelado do seu concurso e nada mais: não pode submeter, listar problemas/testes, julgar, gerenciar usuários ou usar outras APIs administrativas.
- Não reutilizar role admin nem mudar o comportamento do placar normal. Gestão de fotos/música não está nesta fase.

## API da interface

`GET /api/frontend/webcast?contest_id=&page=1`: `{contests:[{id,name}],contest:null|{id,name},capabilities:{can_export,can_manage_credentials},export_url:null|string,items:[{id,label,status:"active"|"expired"|"revoked",expires_at,last_used_at:null|string}],meta}`. Sem seleção válida retornar contest null/itens vazios. Só listar concursos administráveis. `export_url` é caminho local, autenticado por sessão de admin, sem token no URL.

`POST /api/frontend/webcast/credentials`: `{contest_id,label,expires_at}` → 201 `{data:{id,secret}}`. label 1–80, validade futura com teto configurável. UI envia instante ISO8601 UTC a partir do horário local. 422 por campo. Repetição idempotente precisa reconciliar metadados sem reexibir segredo já entregue; se emissão ficou ambígua, retornar 409 e instruir revogar/reemitir, ou manter resposta cifrada temporária com política definida — nunca armazenar token em claro indefinidamente.

`DELETE /api/frontend/webcast/credentials/{id}` → 200 `{data:{id,revoked:true}}`. Idempotente; credencial de outro concurso/ator 403. GET com segredo ativo, expirado ou revogado deve ser indistinguível na mensagem pública de negação.

`GET {export_url}` → application/zip, Content-Disposition attachment, Cache-Control no-store. Erro retorna página de erro própria com status adequado, não HTML dentro de ZIP. Cliente usa download nativo sem anunciar conclusão falsa.

Dados propostos: `webcast_credentials(id,contest_id,label,token_hash,expires_at,revoked_at,last_used_at,created_by)`. Gerar segredo criptográfico; comparação resistente a timing; rate limit. Forma de autenticação no consumidor (header/token ou adaptação `webcastcode`) deve ser confirmada. Se URL token for imprescindível, restringir referrer/logs e documentar a decisão; a UI não constrói tal URL hoje.

## Formato BOCA: evidência e limite conhecido

Fonte primária consultada em 10/09/2026: [BOCA src/admin/report/webcast.php](https://github.com/cassiopc/boca/blob/master/src/admin/report/webcast.php). Os bytes foram lidos via conteúdo base64 da API do GitHub: separador de campos **FS, byte 0x1C**, não vírgula nem os caracteres visíveis `^\` que alguns terminais exibem.

O produtor gera arquivos `contest`, `runs`, `version`, `time`, `icpc` no ZIP. `version` contém `1.0` com newline. `contest` contém nome, duração/lastmileanswer/lastmilescore/penalidade em minutos, quantidades de equipes/problemas, linhas de equipe (ID, instituição, nome) e linhas de configuração finais. `runs` tem ID, tempo convertido para minutos, ID da equipe, problema e resultado; códigos observados: Y aceito, ? pendente, X para erros não penalizados específicos, N para demais respostas. Fonte consulta resultado completo, sem corte de freeze. `icpc` está vazio nesse produtor.

**Não copiar cegamente as constantes contest/site=1 do script legado.** Mapear IDs multi-site de forma estável, impedir colisões e validar que todos os runs referenciam equipes do mesmo export. Nome/instituição não podem conter FS/newlines que corrompam registros; preservar acentos no encoding acordado. Definir mapeamento CE/CS/X e unidades de `time` por fixture real do consumidor; `dateconvminutes` e `$st.currenttime` precisam ser seguidos até sua definição antes de finalizar o serializador. O script não constitui sozinho um teste de compatibilidade.

A URL [Animeitor indicada na issue](https://github.com/cassiopc/animeitor-rs) retornou 404 nesta revisão. **Gate de integração:** obter repositório/release correto ou binário+fixture conhecido; fixar commit do produtor e versão do consumidor e importar ZIP gerado em teste. Até isso, manter `can_export=false`. Não afirmar compatibilidade homologada.

## Critérios de aceite

- Export autorizado contém o placar descongelado; placar público permanece congelado.
- Token de A não lê B, não submete, não vê problemas/fontes; expirado/revogado falha imediatamente.
- Segredo ausente em GET/listagens/logs; revogação repetida é segura; disputa revogar/baixar tem política atômica documentada.
- ZIP de concurso vazio, multi-site e com acentos é importado por versão fixada do consumidor; tempos/penalidades/resultados conferem com scoreboard original.
- Nenhum campo de nascimento, e-mail privado ou fonte entra no arquivo. Nome público de equipe segue política #47.

## Perguntas para decisão

Qual versão/repositório do Animeitor será suportado? Qual limite de validade e mecanismo de autenticação ele aceita? Estas decisões bloqueiam apenas a homologação do export, não a implementação da tela.
