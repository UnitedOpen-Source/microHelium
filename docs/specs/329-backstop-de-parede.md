# Issue #329 — o backstop de tempo de parede, e por que ele virava `CS`

**Decisão:** o backstop de parede da execução passa a ser **multiplicativo**
sobre o limite do problema, com um fator de contenção derivado de medição;
o teto da compilação **continua um número único** — e isso também é uma
decisão medida, não uma omissão.

O #342 já tinha feito o **caminho 1** da issue: criou
`JudgeWallClockTimeoutException` (mensagem com etapa e limite, no lugar da
linha de comando do `bwrap` dentro da exceção do Symfony) e fez o backstop da
compilação ler `autojudge.compile_timeout` — uma chave que existia, dizia
*"Maximum time for compilation in seconds"* e **não era lida por ninguém**.

O que ficou aberto foram os **caminhos 2 e 3**: recalibrar. É o que este
documento decide.

## O defeito que sobrou tem uma forma, e a forma estava errada

Os dois backstops tratavam a folga como uma **soma**:

```php
Process::timeout($timeLimit + 5)   // execução
```

Carga não **soma** tempo de parede. Ela o **multiplica**.

O `ulimit -t` do sandbox conta **CPU**; o backstop conta **parede**. Numa
máquina ociosa as duas quase coincidem, e é por isso que os dois casos da
issue *passam quando rodados sozinhos*. Numa máquina cheia a parede é a CPU
vezes quanto se espera na fila — e uma folga fixa de 5 s não tem como
absorver um fator.

Com o limite padrão de 10 s, a folga aditiva dava **1,5x** de relógio.

## Quanto a parede infla, medido

O fator não foi escolhido: o **#126** já tinha medido esta mesma imagem,
privilegiada, 10 CPUs e MySQL, com K processos `autojudge:start` de verdade
sobre 24 submissões CPU-bound. A inflação por run é K dividido pelo *speedup*
que aquela tabela registrou:

| K workers | speedup medido | inflação de parede por run |
|---:|---:|---:|
| 1 | 1,00x | 1,00x |
| 2 | 1,67x | 1,20x |
| 4 | 2,67x | 1,50x |
| 8 | 3,64x | **2,20x** |
| 12 | 3,67x | 3,27x |
| 16 | 3,53x | 4,53x |
| 24 | 4,45x | 5,39x |

Um backstop que dá **1,5x** onde a máquina precisa de **2,2x** dispara por
carga. O preço é um `CS` num envio que tinha veredito.

## A compilação: por que 20 s falhava, com a conta

O `compile_command` das **48 linguagens ativas** foi medido nesta imagem
(aarch64, máquina ociosa, mediana de três, programa a+b). As seis mais caras:

| | CPU | parede |
|---|---:|---:|
| `go` | **7,64 s** | 1,99 s |
| `kt` | 6,53 s | 3,23 s |
| `cr` | 6,42 s | 1,87 s |
| `scala` | 5,27 s | 1,75 s |
| `zig` | 3,82 s | 3,46 s |
| `groovy` | 1,84 s | 0,67 s |

As outras 42 ficam abaixo de 1,1 s de CPU.

Repare que nas quatro primeiras **a CPU é várias vezes a parede**: são
compiladores **paralelos**. Numa máquina ociosa eles espalham o trabalho
pelos núcleos e terminam em 2–3 s; numa máquina de julgamento cheia esses
núcleos não estão livres, o paralelismo some e a parede sobe em direção à
CPU — e depois passa dela, pela fila.

Daí a explicação numérica do que a issue observou. A medição dela deu
**7,92 s de CPU** para o `kotlinc`; contra o backstop antigo de 20 s:

```
7,92 s × 2,20 (8 workers para 10 CPUs) = 17,4 s   contra 20 s
```

**13% de margem.** Uma margem de 13% é exatamente o que produz um `CS` a cada
cento e poucos julgamentos — e a issue observou 2 em 225.

Com o teto atual de 30 s e o fator de 3, o pior caso medido fica em
`7,64 × 3 = 23 s`. Cabe.

## O caminho 3 foi medido e **rejeitado**

A issue propunha um backstop de compilação **por linguagem**, no precedente de
`problem_language_limits`. A medição acima o derruba:

- entre o compilador mais caro (7,64 s) e o teto (30 s) há **3,9x de folga**;
- apertar o teto das 42 linguagens rápidas **não compra nada**.

Um backstop de parede é uma guarda de **vivacidade**: existe para que um passo
travado não segure a máquina para sempre, e **não para julgar ninguém**. Um
teto apertado no `gcc` só muda em quanto tempo se percebe um travamento,
enquanto o preço de errá-lo para menos é um `CS`. A assimetria é toda de um
lado.

Custo de manter 48 números a cada mudança de imagem; benefício nenhum.

> Isto **não** é o mesmo caso de `problem_language_limits`. Lá o que se
> corrige é a **partida** de um runtime lento (o Portugol Studio sobe duas
> JVMs), e o limite é sobre o programa da equipe. Aqui é uma guarda de
> infraestrutura, e afrouxá-la não dá CPU a ninguém — dá paciência ao juiz.

## O terceiro backstop, que o #342 não pegou

`runCompareScript()` continuava com um `Process::timeout()` cru. Um comparador
que travasse sob carga devolvia a linha de comando inteira do `bwrap` dentro
da mensagem do Symfony — o exato sintoma que a issue chama de ilegível. Agora
passa pelo mesmo invólucro, com etapa própria (`comparacao`), para que quem
investiga saiba **qual dos três passos** estourou.

## O que depende de hardware de produção, e não foi inventado aqui

O fator padrão **3** é o medido **neste parque**: uma máquina, 10 CPUs,
aarch64, MySQL. O número certo para uma sede depende do hardware dela e de
quantos workers ela sobe, e sai da **mesma medição do #126 repetida lá**.

O que este patch conserta é a **forma** do backstop, que estava errada em
qualquer hardware. O **valor** continua sendo decisão de quem opera — agora
com a conta escrita, e não com um número herdado de `time_limit * 2`.

Quem roda mais workers que CPUs sobe o fator **e sobe o `compile_timeout`
junto**. A relação `compile_timeout >= pior CPU medida × fator` está fixada em
`AutoJudgeServiceTest` justamente para que os dois não se soltem em silêncio.

## Rede de regressão

Sem teste de carga, **de propósito**. Um teste que depende de temporização é
instável, este repositório já pagou por um (#250) e a auditoria da própria
#329 manteve a recusa. O que se prova é o **mecanismo**:

| Guarda | Mutação que ela pega |
|---|---|
| `test_o_backstop_de_parede_da_execucao_e_multiplicativo` | a conta volta a ser aditiva; o fator é ignorado |
| `test_a_execucao_recebe_o_relogio_derivado_do_limite_efetivo` | o sítio de chamada volta a `limite + folga` |
| `test_o_backstop_da_comparacao_diz_que_foi_a_comparacao` | a comparação volta ao `Process::timeout()` cru |
| `test_o_teto_da_compilacao_cobre_o_compilador_mais_caro_medido` | sobem o fator sem subir o teto |

As cinco mutações foram aplicadas e cada uma reprovou na guarda certa.

## O que reabre esta decisão

- Um compilador mais caro que `go build` entrar na imagem: o número medido no
  teste muda junto, e é por isso que ele está **escrito** e não inferido.
- Um parque que precise rodar mais workers que CPUs de forma sustentada —
  aí o fator sobe, medido lá, e o teto da compilação com ele.
- O julgamento distribuído (#53) pôr máquinas de capacidades muito diferentes
  no mesmo conjunto: o fator deixaria de ser um número só.
