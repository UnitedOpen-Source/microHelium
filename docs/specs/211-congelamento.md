# 211 — O congelamento do placar

## O que havia

`Leaderboard::getScoreboard(int $contestId, bool $frozen = false)` aceitava o parâmetro e **nunca o lia**. O corpo do método não mencionava `$frozen` depois da assinatura.

Medido antes de consertar — prova de 5h, congelamento na última hora, um AC julgado aos 270 minutos:

```
CONGELADO: [{"problem_id":1,"is_solved":true,"solved_time":270,...}]
AO VIVO   : [{"problem_id":1,"is_solved":true,"solved_time":270,...}]
API is_frozen=true solved=true
```

A API anunciava `is_frozen: true` e entregava o solve pós-congelamento na mesma resposta. A página pública `/scoreboard` nem chegava a perguntar: chamava sem o parâmetro. A única ocorrência de "congelado" nas views era uma entrada de FAQ explicando ao público o que um congelamento significaria.

O #189 tinha acabado de consertar **quando** `isFrozen()` é verdadeiro. Ninguém havia conferido o que era feito com a resposta.

## O desenho

O congelamento é um **corte por tempo de prova**, não uma coluna. As células são recalculadas contando apenas os runs com `contest_time` anterior ao momento do congelamento; nada do que está em `scores` serve, porque aquelas linhas são reescritas a cada veredito.

A regra da célula mora num lugar só. `Score::reduceCell()` foi extraída de `recomputeFor()` — dados os runs que contam, em ordem de submissão, quanto vale a célula — e o congelamento aplica a **mesma** redução sobre o subconjunto anterior ao corte. A alternativa era escrever a contagem de tentativas e a penalidade de novo do lado do congelamento, e este arquivo já documenta, no #171, um caso em que duas formulações da mesma regra que por acaso concordavam foram encontradas em revisão antes de divergirem.

### A célula congelada não é "sem informação"

A ICPC mostra as tentativas feitas durante o congelamento como **pendentes** — a célula fica em aberto, com quantas — e é isso que faz a revelação ter graça. Esconder a existência da submissão seria um congelamento diferente e pior: a equipe não saberia nem que o adversário tentou.

Uma célula **resolvida antes do corte** reporta zero pendentes. Mostrar "resolvido + 3 pendentes" diria que a equipe continuou submetendo um problema que já tinha passado, o que a ICPC não conta e a equipe não fez.

Um run **ainda sem veredito**, submetido antes do corte, também está em aberto: o placar não pode contar o que ninguém julgou.

### A ordem e as medalhas também

As posições são recalculadas a partir das células congeladas. Servir a ordem ao vivo junto de células congeladas diria, pela ordem, o que as células esconderam.

A marca de primeiro a resolver é recalculada e não lida de `scores.is_first_solver`: a marca guardada pode ter sido conquistada durante o congelamento, e mostrá-la entregaria o solve escondido. Uma medalha é um anúncio de veredito tanto quanto uma célula verde.

### Quem é isento

A regra já existia com nome: `Run::viewerSeesWithheldVerdicts()` — admin, juiz, staff e sede. Quem pode ver veredito retido de equipe (#138) pode ver o placar descongelado. Inventar uma segunda lista aqui criaria duas definições de "quem é da organização" para manter em sincronia.

A API isentava só admin; passou a usar a regra nomeada — um juiz que não pudesse ver o placar descongelado não conseguiria trabalhar durante a última hora.

Na página pública não há autenticação, então um visitante anônimo não é isento. Ao contrário da limitação de `own_site` documentada em `ScoreboardController::applySiteVisibility()`, aqui **deslogar não afrouxa nada**: um membro de equipe que saia da sessão continua vendo o placar congelado.

### Quem continua descongelado, agora por decisão

`Webcast\ScoreboardController` (#44) lê descongelado por especificação. O comentário dele dizia que `getScoreboard()` "has no freeze-cut logic — the public /scoreboard page's freeze behavior lives entirely in the frontend Blade/JS layer". A primeira metade era verdade e a segunda não; agora o corte existe e este chamador o dispensa explicitamente.

`Api\ScoreboardController::export()` e `statistics()` já eram staff-only, com um comentário em `routes/api.php` raciocinando que deixá-los abertos entregaria "the standings the freeze is there to withhold". O raciocínio estava certo e a premissa era falsa. Agora é verdadeira.

## A tela

Sem um estado próprio, uma célula congelada com submissões dentro caía no "Não tentado", que é **falso**. Existe agora um estado pendente (`?N`, em cor de aviso) entre "resolvido" e "tentativas erradas".

E a página **diz** que está congelada. Um placar que esconde sem avisar é um placar errado.

## Desfazer

`unfrozen_at` (#189) tira o corte. Sem corte, não havia o que descongelar — a cerimônia do #189 revelava um placar que nunca esteve escondido.

Um contest com `freeze_time = 0` não tem corte nenhum: a mesma armadilha de sinal oposto que o #189 fechou no predicado.
