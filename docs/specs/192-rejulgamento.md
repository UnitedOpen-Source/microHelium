# 192 — Rejulgamento em lote, com prévia

## O cenário

Não é rejulgar um envio. É: descobre-se no meio da prova que o caso de teste 7 do problema C está errado, conserta-se o pacote, e **todos os envios do problema C precisam voltar para a fila**. Com `POST /api/runs/{run}/rejudge` isso é abrir run por run.

Os requisitos de CCS pedem duas coisas que não existiam:

> The CCS must support rejudging of submissions, selected by any combination of: submission, problem, language, team, time range, judgement, and judging machine.

> It must be possible to **preview the effect of a rejudgement without committing to it**.

## Por que a prévia obriga a forma de conjunto

Não há como saber o que muda sem **julgar**. Então julga-se antes de aplicar, guardando o resultado fora do run — e enquanto a organização decide, a equipe continua vendo o veredito que já via e a classificação não se mexe. Aplicar é uma segunda decisão; cancelar não deixa rastro.

```
POST /contests/{c}/rejudgings/dry-run   → quantos envios o critério pega
POST /contests/{c}/rejudgings           → monta o conjunto, julga em sombra
GET  /rejudgings/{r}                    → a prévia: quantos mudariam, e quais
POST /rejudgings/{r}/apply              → grava os vereditos novos
POST /rejudgings/{r}/cancel             → descarta, sem tocar em nada
```

O `dry-run` existe separado porque montar um conjunto **dispara julgamento de verdade**: descobrir que o filtro pegou 4000 envios em vez de 40 depois de a fila já estar cheia é tarde.

## O veredito antigo sobrevive

Hoje o rejulgamento de um envio **apaga** o anterior (`answer_id = null`), então não há antes/depois nem como comparar. O DOMjudge guarda:

> When a submission is rejudged, the old judging data is kept but marked as invalid.

Aqui o "antigo" mora nas linhas de `rejudging_runs`, gravado **no momento em que o conjunto é montado** e não na hora de aplicar. Se fosse lido no apply, um run julgado por outra pessoa durante a decisão entraria como se fosse o estado original, e o antes/depois mentiria sobre o que o conjunto fez.

## Decisões que não são detalhe

**Aceitos ficam de fora por padrão.** Tirar um AC de uma equipe no meio da prova é a coisa mais cara que um rejulgamento faz, e quase nunca é o que se queria ao pedir "rejulgue o problema C". Incluir exige `include_accepted: true`, e a decisão fica gravada na linha do conjunto.

**O motivo é obrigatório.** Um rejulgamento em lote muda a classificação de gente que não pediu nada, e "por quê" é a primeira pergunta de quem contesta o resultado depois. O critério também é guardado como foi pedido, e não só a lista de runs que ele selecionou: a lista diz *o que* foi feito, o critério diz *por quê* — e "todos os envios do problema C em C++" é o que alguém vai querer ler seis meses depois.

**Um membro que falhou fica como estava.** Trocar um veredito real por uma falha de infraestrutura seria a equipe pagando por um defeito nosso — a mesma razão pela qual o watchdog do #45 não pontua o `CS` que ele próprio cria. E a falha fica *no membro*, não no conjunto: um envio que não compila mais porque o pacote mudou não pode impedir que os outros trezentos sejam decididos.

**Um veredito sem `answers` correspondente é falha, não veredito.** Gravar `answer_id` nulo com `status = judged` deixaria o run num estado que `Score::updateScore()` ignora: julgado, sem resposta, fora do placar e invisível para a equipe.

**Aplicar revoga a verificação (#138).** A assinatura que liberou o veredito anterior foi dada sobre *outro* julgamento, possivelmente contra outros casos de teste.

**Aplicar é tudo ou nada**, numa transação: metade dos runs com veredito novo e metade com o antigo não é um estado que alguém possa interpretar depois.

**A recomposição do placar vem depois de gravar todos**, uma vez por célula tocada. `Score::recomputeFor()` é uma função pura dos runs que contam (#171), então recompor dentro do laço produziria estados intermediários — uma equipe sem o AC que volta duas linhas adiante — e cada um deles dispara balão e recálculo de classificação.

## O congelamento

A issue pede que isso seja decidido antes, e não depois. A decisão:

**Aplicar durante o congelamento é permitido.**

Um rejulgamento só consegue mexer em células **anteriores ao corte**, e aqueles vereditos já eram públicos — corrigi-los é o motivo de o rejulgamento existir. O que o congelamento esconde continua escondido: o placar congelado só conta runs anteriores ao corte (#211), então um envio feito durante o congelamento permanece invisível com veredito novo ou velho.

Os dois lados estão fixados por teste — um exigindo que a célula anterior ao corte seja corrigida, outro exigindo que o envio do congelamento continue sem aparecer.

O que a organização vê é um aviso no log de contest, em nível `warning`, na criação e na aplicação.

## Quem pode

Juiz e admin, junto do rejulgamento de um envio só: quem pode rejulgar um envio pode rejulgar o problema inteiro, e a diferença entre as duas coisas é de escala e não de autoridade. O que protege contra o acidente não é o perfil — é a prévia, o motivo obrigatório e a exclusão dos aceitos por padrão.

Uma equipe não chega a nenhuma das rotas: a prévia mostra o veredito que *cada* equipe teria, isto é, a classificação inteira antes de ela existir.
