# #42 — Análise de similaridade

Status: implementado. `GET/POST /api/frontend/similarity*` existem de fato
(routes/frontend_api_similarity.php), com migrations, job assíncrono e
motor JPlag real por trás de `Similarity.vue`, cobertos por
`tests/Feature/SimilarityTest.php`. Ver "Estado da implementação" ao final
para o que foi decidido/simplificado e o que ainda depende de outra issue
ou de decisão do time.

## Contexto e objetivo

Hoje `Run.source_hash` identifica duplicatas, mas não compara equipes. Entregar comparação assíncrona por problema/linguagem para administração, com rastreabilidade e revisão humana. Interface: `/backend/similarity`, componente `Similarity.vue`.

## Escopo funcional e estados

1. Admin escolhe problema entre concursos autorizados, linguagem suportada e limite percentual inteiro 0–100 (padrão visual 80; não é um critério de culpa).
2. Servidor captura a última AC de cada equipe nesse problema/linguagem, desempate por ID, excluindo envios de prática. Se equipe é representada por `User` neste modelo, usar `user_id` consistentemente; não alternar com a tabela legada de times.
3. Job passa por `queued → running → completed|failed`; resposta HTTP não espera o JPlag. Persistir snapshot dos run IDs/hash e versão/configuração do analisador para impedir mudanças silenciosas entre execução e revisão.
4. Resultado lista pares acima do limite com similaridade média percentual, equipes e links autorizados aos dois códigos. Zero pares é sucesso sem correspondências, não erro. Nova execução gera novo histórico; não sobrescrever relatório anterior.
5. Sem punição, publicação pública, comparação entre concursos ou execução automática a cada submissão. Comparador visual por linhas e decisão disciplinar ficam fora desta fase.

## Dados e API

`GET /api/frontend/similarity?page=1`: `{problems:[{id,name,contest_name,languages:[{id,name}]}], capabilities:{can_start}, items:[Check], meta}`.

`Check = {id,problem_name,language_name,status,team_count,created_at,threshold,error_message:null|string,pairs:[{id,team_a,team_b,run_id_a,run_id_b,source_a_url,source_b_url,similarity_score}]}`. `pairs=[]` durante processamento. Ordenar checks por criação/ID decrescentes e pares por percentual/ID. Limitar relatório no job a um número configurável de pares; se necessário paginar pares em contrato futuro, nunca truncar silenciosamente.

`POST /api/frontend/similarity/checks`: `{problem_id,language_id,threshold}` + Idempotency-Key → 202 `{data:{id,status:"queued"}}`. Menos de duas equipes elegíveis, linguagem não suportada ou limite inválido: 422 no campo correspondente. Execução já em andamento para o mesmo snapshot: 409 com ID em `data` opcional para reconciliação; manter payload de erro comum.

Tabelas propostas: `similarity_checks` (ator, contest_id, problem_id, language_id, state, timestamps, threshold, team_count, snapshot JSON, engine_version, options, safe_error_code), `similarity_pairs` (check_id, run_id_a, run_id_b, score). Unique `(check_id, min(run_a,run_b), max(...))`. Não apagar evidência por exclusão de relatório sem política explícita; privilégios de leitura de fonte continuam independentes dos links.

## Não funcionais e casos de borda

- Fonte ausente/inacessível: registrar exclusão e falhar claramente se sobram menos de duas equipes; não expor path. Relatório deve guardar contagens de elegíveis/excluídos, com motivo seguro na mensagem, para não sugerir cobertura completa.
- Executar JAR em diretório temporário isolado, sem rede, com teto de tempo/memória/arquivos e argumentos estruturados (não interpolação de nomes/comandos). Escolher imagem e release fixos, verificar checksum. Limitar quantidade de equipes por job e concorrência por concurso antes de enfileirar.
- O [README oficial do JPlag](https://github.com/jplag/JPlag) consultado em 10/09/2026 descreve execução local, seleção de linguagem e modo `RUN`; o main exige Java SE 25. Não assumir que o Java existente no judge suporta a release escolhida. Validar saída `.jplag`/parser contra a versão fixada antes de persistir percentuais; normalizar escala 0–1 para 0–100 exatamente uma vez.
- Comparar linguagens separadamente; código base comum deve poder ser excluído quando configurado pelo administrador no backend. Sanitizar erros e nunca subir código para serviço externo.

## Critérios de aceite

- Duas equipes com AC elegíveis geram um check com snapshot estável; repetir a chave não duplica job.
- Pending/WA posteriores à última AC não substituem o snapshot aceito; empate escolhe ID maior.
- Admin recebe pares, participante recebe 403 e não consegue baixar a fonte pelo URL adivinhado.
- Falha/timeout do worker termina o check em failed; nenhum check fica running indefinidamente.
- Fixture controlada com um par conhecido verifica cálculo e escala; zero pares e linguagem inválida têm comportamentos distintos.

## Perguntas para decisão

Release do JPlag, teto de equipes/pares, retenção dos relatórios e direito de revisão das fontes precisam ser fixados pelo time antes da ativação. A UI não presume que 80% prove cópia.

## Estado da implementação

**JPlag e JDK.** Fixado em v6.2.0 (`config/similarity.php`, `Dockerfile`) —
a última release construída para JDK 21, antes de v6.3.0 exigir JDK 25 (ver
notas de release de ambas as tags no GitHub). O `Dockerfile` principal
(usado por `app`/`queue`/`scheduler`, conforme `docker-compose.yml`) já
instala `openjdk21-jdk` para o autojudge de Java, então v6.2.0 evita bump de
JDK. O jar é baixado e verificado por sha256 **no build da imagem** (evita
dependência de rede em tempo de execução) e o sha256 é conferido de novo
**a cada execução** por `App\Services\Similarity\JplagSimilarityEngine`
antes de rodar `java -jar`, contra o mesmo checksum que o GitHub atesta
para o asset da release (campo `digest` de
`GET /repos/jplag/JPlag/releases/tags/v6.2.0`, conferido em 2026-09-11).
`Dockerfile.judge` (imagem legada, não usada pelos serviços `queue`/
`scheduler` atuais) não foi alterado.

**Saída do JPlag.** A struct `.jplag` (zip) e seus `runInformation.json`/
`topComparisons.json` foram inspecionados rodando a v6.2.0 real localmente
contra um par de fontes conhecidas (não durante os testes automatizados,
que usam `Tests\Support\Similarity\FakeSimilarityEngine`, sem rede nem jar
real). `topComparisons[].similarities.AVG` é a fração 0.0–1.0 usada como
"similaridade média"; a normalização para 0–100 acontece exatamente uma
vez, em `RunSimilarityCheckJob`. `-n` (native do JPlag) implementa o teto
de pares do relatório e `-m` filtra por limiar já na origem.

**Tetos escolhidos** (`config/similarity.php`, todos por env var):
máx. 60 equipes por job, máx. 300 pares por relatório, máx. 2 checks
`queued`/`running` simultâneos por concurso. Não há política de retenção
implementada (relatórios não expiram nem são apagados) — fica como decisão
pendente do time, conforme a pergunta original.

**Linguagens suportadas**: mapeadas em
`App\Services\Similarity\SimilarityLanguageMap` a partir de
`Language::getDefaultLanguages()` para os identificadores de CLI do JPlag
v6.2.0 (cpp, java, python3, javascript, typescript, kotlin, scala, csharp,
rust, golang, swift, rlang). Uma linguagem fora do mapa é rejeitada com 422
em `language_id`, nunca tentada silenciosamente.

**Exclusão de envios de prática**: não implementada — é um no-op. A tabela
`runs` ainda não tem uma coluna que distinga um envio de prática de um de
concurso (issue #43 não chegou a esse ponto). `EligibleRunFinder` está
comentado no local exato onde esse filtro deve entrar quando #43 adicionar
a coluna.

**Isolamento de rede**: o JPlag em modo `RUN` não faz chamadas de rede por
conta própria (nenhuma flag usada aqui habilita o recurso separado de
upload para o report-viewer hospedado). Negar rede no nível de processo/
container (namespace de rede, política de egress no worker de fila) é uma
tarefa de infraestrutura fora do escopo desta mudança em PHP e fica como
follow-up operacional.

**Base code comum**: suportado via `SIMILARITY_BASE_CODE_PATH` (config do
servidor, não um campo do formulário/contrato da API — `Similarity.vue`
não foi alterado). Desabilitado por padrão.

**Download de fonte**: `GET /api/frontend/similarity/runs/{run}/source`
replica exatamente a checagem de autorização de
`Api\RunController::downloadSource()` (admin/judge ou dono do run), sob o
grupo de rotas `['auth','admin']` já usado pelas demais rotas desta
feature — um participante recebe 403 antes mesmo de a checagem interna
rodar.

**Corrida entre requisições concorrentes**: `POST /checks` sem
`Idempotency-Key` (duas abas de admin, por exemplo) usa um `Cache::lock`
por `contest_id` em volta da checagem de concorrência + duplicata +
`SimilarityCheck::create()`, já que nenhuma dessas checagens tinha
suporte de constraint única no banco capaz de pegar uma corrida real de
INSERT/INSERT. Se o lock não for adquirido em `SIMILARITY_LOCK_WAIT_SECONDS`
(padrão 5s), a resposta é 409.

**`safe_error_code` de interrupções não previstas**: `RunSimilarityCheckJob::failed()`
só atribui `engine_timeout` quando a exceção recebida é de fato
`Illuminate\Queue\TimeoutExceededException`; qualquer outra interrupção
(worker morto por OOM, conexão de banco perdida) usa o código genérico
`worker_interrupted`, para não afirmar um timeout que não ocorreu.

**Achado corrigido fora do escopo direto da issue**: `bootstrap/providers.php`
não existia neste repositório, então `Helium\Providers\AppServiceProvider`
nunca era de fato registrado pelo Laravel 11 (ver
`Illuminate\Foundation\Application::configure()`). Sem isso, o binding de
`SimilarityEngineInterface` não teria efeito em produção/fila real. O
arquivo foi criado listando apenas esse provider. Também foi corrigido um
vazamento de ambiente em `tests/Unit/QueueConfigTest.php` (issue #61's
teste): ele usava `putenv($key)` sem valor para "restaurar" `QUEUE_CONNECTION`
no `finally`, o que na verdade REMOVE a variável — deixando o dotenv real
(`.env`, `QUEUE_CONNECTION=redis`) vazar para todos os testes seguintes no
mesmo processo do PHPUnit. Isso nunca tinha se manifestado porque nenhum
teste anterior despachava um job real (não fake) esperando rodar de fato
em `sync`; `RunSimilarityCheckJob` foi o primeiro. Corrigido para
salvar/restaurar o valor original em vez de removê-lo.
