# 195 — A Contest API da ICPC, fase 1

## A decisão que a issue pede antes de começar

> Isto só vale se alguém quiser rodar o resolver da ICPC ou um shadow contra esta instalação. Se a resposta for "não", a coisa honesta é fechar este issue com essa frase escrita.

**A resposta é sim, para a fase 1 — e a fase 1 não habilita o resolver.** Essa distinção é a parte importante, e vem da própria documentação do ICPC Tools:

> the only part of the Contest API that is strictly required is the event feed and any file references that the feed refers to

O resolver lê o **event feed**. Sem ele, o resolver não funciona contra esta instalação, e dizer o contrário seria vender uma coisa que não existe.

O que a fase 1 habilita é o que fala REST: shadow comparando submissões e julgamentos, analisadores, e o Contest Data Server ingerindo os endpoints. Isso é útil por si, é pequeno, e é pré-requisito da fase 2 de qualquer forma — o tradutor de objetos é o mesmo.

E o endpoint `api` **diz isso em voz alta**, no campo `provider.notes`. Um consumidor que espere o resolver precisa descobrir que o feed não existe aqui lendo a raiz da API, e não com um 404 no meio de uma cerimônia.

## O piso da spec

> The only required endpoints are metadata: `api` and `access`. […] All other endpoints and properties are optional.

Entregues: `api`, `access`, `contests`, `contests/{id}`, `state`, `problems`, `teams`, `organizations`, `groups`, `languages`, `judgement-types`, `submissions`, `judgements`, `scoreboard`, `awards`.

## Um tradutor, não um segundo modelo de dados

Tudo deriva do que já existe. Um `judgements` calculado por caminho próprio poderia discordar do placar da casa, e **duas verdades sobre o mesmo veredito é pior do que uma incompleta**.

Por isso `/scoreboard` chama `Leaderboard::getScoreboard()` com a mesma flag de congelamento do resto do sistema, `/teams` usa `ScoreboardTeams` (#212), e `/awards` usa `ContestAwards` (#202).

## O congelamento, que é normativo

- `/judgements` **público** não inclui envios da janela de congelamento. Um consumidor anônimo que recebesse esses vereditos saberia, pelo `/judgements`, o que o `/scoreboard` esconde — a classificação pela porta dos fundos.
- `/submissions` **continua listando** os envios da janela. A spec esconde o *julgamento*, não a submissão, e é o que faz o placar poder mostrar uma célula pendente (#211).
- `/scoreboard` público é o congelado.
- `/awards` só existe depois de finalizada (#202). Antes disso, a lista de medalhas é a classificação final dita de outro jeito.

Quem se autentica e é da organização recebe a versão descongelada **pelo mesmo URL** — que é o que um shadow da própria organização precisa. A regra de "quem é da organização" é `Run::viewerSeesWithheldVerdicts()`, a mesma do #211: não pode existir em duas versões.

## `/state` depende do #189 e do #202

`started` / `frozen` / `ended` / `thawed` / `finalized` é exatamente o vocabulário que não existia. Antes daquelas duas issues não havia `thawed` nem `finalized` para publicar, e o congelamento nem sobrevivia ao fim da prova — publicar `/state` ali seria publicar um estado errado, que é pior do que não publicar nenhum.

**`frozen` é um instante, não um booleano.** A spec é explícita, e o booleano é o erro que o consumidor não detecta: ele lê "truthy" e segue.

## Os vereditos

O BOCA chama de `YES`/`NO` o que a spec chama de `AC`/`WA`. Sem a tabela de tradução, um consumidor recebe `NO` onde espera `WA` e trata como veredito desconhecido.

`CS` — o veredito que a própria infraestrutura produz ao desistir (#45) — vira `JE` (Judging Error), que é exatamente o que o #202 exige que não exista para poder finalizar.

## Por que não está no `openapi.yaml`

Porque isto implementa uma especificação **externa e versionada**, cujo contrato normativo mora em `ccs-specs.icpc.io`. Redescrevê-la no arquivo daqui produziria uma segunda cópia local da spec de outra pessoa, e o modo de falha de uma cópia é divergir do original enquanto parece autoritativa. O que está documentado aqui é o **mapeamento** — este arquivo.

Mesmo raciocínio já escrito para `/api/frontend/*`, um passo mais forte.

## Duas coisas que a verificação encontrou

**Um `language_id` que `/languages` não listava.** O teste de integridade referencial pegou na primeira execução — era artefato do fixture (`RunFactory` cria a linguagem com contest próprio), mas é exatamente o defeito que quebra um consumidor em silêncio: uma linha faltando num painel.

**Duas fontes para o mesmo cabeçalho CORS.** Uma mutação mostrou que remover `Access-Control-Allow-Origin` do middleware não derrubava teste nenhum — porque a configuração do framework já cobre `api/*`. A linha saiu; o requisito continua fixado por teste, então estreitar `config/cors.php` falha aqui em vez de falhar no navegador de um consumidor.

## Fase 2

O event feed (NDJSON, streaming, `since_token`, `end_of_updates`) exige um log de mudanças por objeto que não existe hoje. É o que habilita o resolver.
