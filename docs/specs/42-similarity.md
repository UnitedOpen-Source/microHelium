# #42 — Análise de similaridade

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
