# Issue #251 — o limite derivado, e o que "por host" quer dizer

**Decisão:** a fase 2 do #196 deriva **um** limite por problema, calibrado na
máquina mais lenta do parque, a partir das soluções de referência do pacote.
Não serve um limite diferente por máquina a cada run.

Isto não contraria o que já estava registrado — **confirma**. O
`docs/specs/196-tempo-medido.md` já dizia, na seção "Fase 2, não entregue":

> Limite por host com o máximo servido (**a máquina mais lenta define o limite**,
> e ninguém é punido por sorteio de fila) muda veredito, e depende de os pacotes
> carregarem soluções de referência.

O que faltava era fonte e ordem de construção. O título da issue #251 — "o
limite de tempo servido por host" — se lido sozinho sugere um limite por
máquina por run, que é coisa diferente e que as fontes não sustentam. Este
documento fecha essa ambiguidade.

## O que dizem as fontes

### O formato de pacote da ICPC/Kattis

A especificação [2025-09](https://www.kattis.com/problem-package-format/spec/2025-09.html)
tem **um** limite por problema, derivado de tempos medidos, com margens:

- `ac_to_time_limit`, padrão **2.0** — `T_ac * ac_to_time_limit <= time_limit`,
  onde `T_ac` é o tempo da submissão aceita mais lenta do pacote;
- `time_limit_to_tle`, padrão **1.5** — e também
  `time_limit * time_limit_to_tle <= T_tle`, onde `T_tle` é o tempo da submissão
  TLE mais rápida;
- `time_resolution`, padrão **1.0** — o limite é o menor múltiplo inteiro dela
  que satisfaz as duas desigualdades, e *"It is an error if no such multiple
  exists"*.

A recomendação é explícita:

> Since time multipliers are more future-proof than absolute time limits, avoid
> specifying `time_limit` whenever practical.

O formato trata o valor absoluto como exceção e o derivado como caso normal. É
o oposto do que esta instalação faz hoje, onde o limite é digitado à mão.

### DOMjudge

A [documentação 8.3](https://www.domjudge.org/docs/manual/8.3/overview.html)
resolve heterogeneidade por uniformidade:

> Having all judgehosts be of uniform hardware configuration helps in creating a
> fair, reproducible setup; in the ideal case they are run on the same type of
> machines that the teams use.

Li as páginas *Overview* e *Installation of the judgehosts* das versões 8.3 e
8.2 e não encontrei mecanismo de limite por judgehost. Isso é ausência de
evidência nas páginas que li, não prova de que não exista em lugar nenhum.

### MOJ

O `196-tempo-medido.md` cita o MOJ como quem **serve o limite calibrado** e usa
**o maior caso de teste** para calibrar. É o precedente mais próximo do que esta
instalação quer, e vem da mesma família de sistema que este projeto.

## Por que um limite por máquina por run está fora

Faz o veredito depender de **qual máquina pegou o run** — o que o #117/#130
decidiu evitar ao estabelecer que divergência é *avisada, não compensada*. Duas
equipes com a mesma solução receberiam limites diferentes, e o julgamento
deixaria de ser reproduzível depois da prova.

Calibrar na máquina mais lenta é outra coisa: produz **um** limite, igual para
todo mundo, escolhido de modo que nenhuma máquina do parque reprove solução
correta.

## Ordem de construção

1. **Ler as margens do pacote.** `IcpcPackageReader` já lê `problem.yaml` e já
   coleta `submissions/accepted`, e o comentário dele já aponta para cá:

   > `limits.time_limit` vem em SEGUNDOS e ja e o limite final -- nao o tempo da
   > solucao de referencia. O #196 e quem vai medir.

   Falta ler `ac_to_time_limit`, `time_limit_to_tle` e `time_resolution`, e
   coletar `submissions/time_limit_exceeded`, que é de onde sai `T_tle`.

2. **Medir na máquina de calibração.** As colunas da fase 1 já existem —
   `runs.measured_wall_ms` e `runs.measured_cpu_ms`, com índice por
   `(problem_id, language_id, judgehost_id)`. Compara-se por **CPU**, que é o
   número que não é enganado por uma máquina ocupada com outro julgamento; o de
   parede é o que a equipe sente. Do pacote, o **maior caso de teste**, como o
   MOJ faz e como o `196-tempo-medido.md` já registrou.

3. **Derivar.** Menor múltiplo de `time_resolution` que satisfaz as duas
   desigualdades. Se não existir, é **erro de pacote**: a importação falha
   dizendo isso, em vez de escolher um número.

4. **Guardar como derivado.** O valor digitado continua existindo como
   *override* explícito e com aviso visível de que foi digitado. Um limite
   derivado sobrescrito em silêncio é o defeito de volta.

5. **A tela de calibração muda de papel.** Deixa de ser informativa e passa a
   responder: *"esta máquina é X% mais lenta que aquela em que o limite foi
   derivado"* — para **excluir** a máquina ou **rederivar** o limite, nunca para
   compensar o run.

### Qual é a máquina de calibração

A mais lenta do parque, ou uma designada que não seja mais rápida que nenhuma
das que vão julgar. Derivar na mais rápida e julgar na mais lenta produz TLE em
solução correta — o erro na direção que custa a prova.

## O que reabre esta decisão

- Uma edição futura do formato, ou uma implementação de referência, passar a
  definir limite por máquina.
- Um parque tão heterogêneo que excluir as máquinas fora da faixa deixe a prova
  sem capacidade de julgamento. Aí a escolha é entre reduzir o parque e aceitar
  veredito irreprodutível — decisão de quem organiza, mas tomada sabendo o que
  se troca.

## O que este documento não entrega

Código nenhum. A fase 1 continua sendo o que existe: o sistema **mede e avisa**.
Até a fase 2 ser construída, o limite segue digitado à mão e a divergência entre
máquinas segue aparecendo só na tela de calibração — depois de a equipe já ter
recebido o veredito.
