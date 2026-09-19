# Issue #322 — a política de penalidade do erro de compilação

## O que era

Uma submissão com erro de compilação custava uma penalidade inteira — 20
minutos, ou o `penalty` da prova. Isso não vinha de nenhuma linha que tratasse
de `CE`: vinha de `Answer::getDefaultAnswers()`, onde o `CE` nasce com
`is_accepted = false`, e de `Score::reduceCell()`, que conta como tentativa
todo run que chega até ela.

O problema **não era o comportamento**. Era que **a decisão não existia**: não
estava em `docs/specs/`, não estava no SRS (F12 não menciona `CE`), não estava
em comentário no código. Este repositório documenta decisão no código —
inclusive decisões bem menores que esta. Esta não estava documentada em lugar
nenhum, o que quer dizer que ninguém a tomou.

## A decisão

**O padrão continua sendo penalizar**, e agora está escrito.

O argumento é o BOCA. A tradição da ICPC é conhecida e ambígua na prática: o
DOMjudge torna isso configurável (`compile_penalty`) exatamente porque as duas
escolhas existem em regionais diferentes. O BOCA — que é o sistema em que a
Maratona roda, e de cujo esquema este projeto herda a modelagem — trata `CE`
como resposta normal e penaliza. Mudar o padrão seria mudar, sem pedido, o que
a banca brasileira espera do placar.

O texto normativo não decide por nós. Ele fala em *"20 penalty minutes for
every previously **rejected** run"* e não define o que é um run rejeitado.

**E passou a ser configurável por prova**, que é a outra metade do que a issue
pedia: penalizar `CE` numa prova de treino ou numa seletiva interna só
desanima, e hoje não havia como não penalizar.

## Onde o mecanismo mora, e por que não cabe em outro lugar

A primeira tentativa óbvia — pular o `CE` dentro de `Score::reduceCell()`, por
`short_name` — **não derruba teste nenhum e não muda nada**. Os dois chamadores
da redução carregam a relação como `answer:id,is_accepted`, então a redução
não tem como saber *qual* veredito está contando: só se ele aceita ou não.

Ou seja: *"CE não conta"* não é um `if` que falta na redução. É uma mudança em
**quais runs chegam até ela**, e o lugar é `Run::scopeCountingTowardsScore()`.

A marca é uma coluna em `answers`:

```
answers.counts_as_attempt  boolean, default true
```

`answers` **já é por prova** — cada contest tem o seu catálogo de vereditos —,
então a configuração por prova sai de graça, sem coluna nova em `contests` e
sem um segundo lugar onde a política possa divergir.

A mesma marca responde à issue irmã **#321**: o `CS` escrito pela nossa própria
infraestrutura quando o julgamento falha nasce com `counts_as_attempt = false`,
porque uma falha nossa não é tentativa da equipe. Um mecanismo, duas perguntas.

## Como mudar a política numa prova

Desligar a marca na linha `CE` **daquela prova**:

```php
Answer::where('contest_id', $contest->id)
    ->where('short_name', 'CE')
    ->update(['counts_as_attempt' => false]);
```

Nada mais muda: o veredito continua existindo, continua aparecendo para a
equipe e continua não sendo aceito. Ele só deixa de custar tentativa e
penalidade.

## O que fixa a decisão

Dois testes em `tests/Feature/ScoreboardIcpcRulesTest.php`, um para cada lado:

- `test_erro_de_compilacao_conta_como_tentativa_incorreta` — o padrão. A
  mutação que ele pega (executada): trocar o `counts_as_attempt` do `CE` em
  `Answer::getDefaultAnswers()` para `false`. Falhou.
- `test_a_prova_pode_decidir_que_o_erro_de_compilacao_nao_penaliza` — a
  configuração. A mutação que ele pega (executada): remover o
  `->whereHas('answer', ...)` de `Run::scopeCountingTowardsScore()`, ou seja,
  fazer a marca não fazer nada. Falhou.

Os dois vereditos vêm do catálogo padrão, e não de uma factory com valores
escolhidos no teste: quem decide a política é `Answer::getDefaultAnswers()`, e
um fixture próprio testaria uma política que nenhuma prova real usa.
