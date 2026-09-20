# Manual do Organizador

Para quem tem papel **`admin`** e responde pela competição: montar o evento,
cadastrar gente e problemas, conduzir o ciclo da prova e publicar o resultado.

Papéis vizinhos: [juiz](juiz.md) (julgamento), [staff](staff.md) (operação e
sede), [participante](participante.md) (o que a equipe vê).

---

## 1. Papéis do sistema

O papel fica em `users.user_type` e decide o que a pessoa alcança.

| Papel | Para quem | Alcança |
|---|---|---|
| `admin` | organização | tudo: `/backend/*`, e também `/judge/*`, `/staff/*`, `/site/*` |
| `judge` | banca técnica | `/judge/*` — fila, verificação, pausa de problema, rejulgamento |
| `staff` | apoio do evento | `/staff/*` — tarefas, impressão, S.O.S., relatório |
| `site` | coordenação de uma sede | `/site/*` — equipes, tarefas, clarificações e S.O.S. daquela sede |
| `team` | equipe competidora | superfície de competidor |
| `score` | painel/telão | leitura de placar |
| `system` | contas de serviço | tratado como `admin` na autorização |

**Princípio:** dê o menor papel que resolve. Um coordenador de sede não
precisa de `admin` para tocar a sede dele — `site` existe exatamente para
isso.

---

## 2. Montar a competição

### Caminho rápido: o assistente

`/backend/contest-wizard` conduz a criação de um contest do zero. Serve para
começar; tudo que ele cria continua editável depois.

### Ordem que funciona

**1. Contest.** `/backend/contest/{id}/edit` — nome, início, duração,
congelamento, penalidade por envio errado, tamanho máximo de arquivo, e se é
**público** ou **privado**.

> O congelamento é dado em **minutos antes do fim** — `freeze_time: 60` numa
> prova de 5 horas congela na quarta hora. **`freeze_time: 0` significa "não
> congela"**, e não "congela no fim".

> `is_public` nasce **falso**. Um contest privado não aparece para visitante
> anônimo — nem o placar, nem a lista de problemas, nem o relógio. Isso é
> proposital. Marque público só quando quiser que o placar seja aberto.

**2. Sedes.** `/backend/sites`. Toda instalação tem pelo menos uma. Na sede
você pode configurar a **faixa de IP**: contas ligadas àquela sede só entram
de dentro daquela rede.

**3. Linguagens.** `/backend/languages`. Só o que estiver cadastrado *e*
instalado no judgehost pode ser usado. Cadastrar uma linguagem que o
judgehost não tem produz erro de compilação em prova.

**4. Problemas.** Três caminhos:

- `/backend/import-package` — pacote no formato **ICPC/Kattis**
  (`problem.yaml`, `data/`, `submissions/`). É o formato que o Polygon
  exporta e que o resto do mundo usa.
- `/backend/import-boca` — pacote **BOCA**, por upload ou a partir do GitHub.
- `/backend/exercises` — cadastro manual.

> **Sobre o limite de tempo no pacote ICPC:** o importador exige que o pacote
> **diga** o limite — em `domjudge-problem.ini` (`timelimit`), num arquivo
> `.timelimit` na raiz, ou em `limits.time_limit` no `problem.yaml`. Se o
> pacote não disser, a importação é **recusada**, e isso é de propósito: o
> formato legacy deriva o limite das soluções de referência, derivar ainda
> não existe aqui, e escolher um número por conta própria seria escolher
> vereditos.

**5. Banco de problemas.** `/backend/problem-bank` guarda problemas para
reuso entre edições. `/backend/bank-governance` controla quem é dono do quê
e o que é compartilhado.

**6. Usuários.** `/backend/users`. Criação individual ou em lote, definição
de papel, vínculo com contest e sede. `/backend/users/{user}/reset-link` gera
link de ativação/redefinição — é como entregar senha sem mandar senha por
mensagem.

`/backend/managed-accounts` cuida de contas administradas por terceiros (ex.:
uma instituição que gerencia as contas dos alunos dela).

**7. Organizações.** `/backend/organizations` — as instituições que as
equipes representam. É o que aparece no placar e nos relatórios da ICPC.

**8. Judgehosts.** `/backend/judge-machines` lista as máquinas de julgamento,
o que cada uma sabe rodar e se estão vivas.

> Antes da prova, rode `php artisan judgehost:selftest` **em cada máquina que
> vai julgar**. Ele prova naquela máquina que o sandbox confina de verdade.
> Uma máquina que reprova no selftest não deve julgar.

**9. Ativar.** `/backend/contest/{id}/activate` marca o contest como o evento
ativo. É o que o relógio e o placar passam a mostrar.

---

## 3. Conduzir a prova

`/backend/contest/{contest}/operations` é o painel de operação. As ações
também existem em `/backend/configurations`.

### O ciclo

```
Rascunho → Agendado → Em andamento → Congelado → Encerrado → Finalizado → Publicado
```

**Acabar e congelar são coisas diferentes.** Encerrar a prova não publica o
resultado: o placar pode continuar congelado até a cerimônia. É isso que
permite revelar o pódio ao vivo.

### As operações

| Operação | Rota | O que faz |
|---|---|---|
| **Congelar agora** | `POST /backend/contest/freeze` | congela o placar imediatamente, antes do horário automático |
| **Encerrar agora** | `POST /backend/contest/end` | encerra imediatamente |
| **Revelar** | `POST /backend/contest/{contest}/unfreeze` | descongela o placar — é a cerimônia |
| **Finalizar** | `POST /backend/contest/{contest}/finalize` | fecha o resultado e deriva a premiação |

**"Encerrar agora" encerra agora** — não no fim do minuto corrente. Isso já
foi um defeito (a prova aceitava envios por até 59 segundos depois) e foi
corrigido. Se você clicou, acabou.

### Ajustes de tempo

`POST /backend/contest/{contest}/time-adjustments` — para quando algo
interrompe a prova: queda de energia numa sede, problema com defeito, atraso
na largada.

O ajuste **não reescreve os horários já gravados**. Ele registra um intervalo
removido, e o fim da prova passa a considerá-lo. Por isso o horário de
término vem do servidor: `início + duração` deixa de bastar quando há
ajuste.

`DELETE .../time-adjustments/{adjustment}` desfaz um ajuste — e a remoção é
reversível e auditável, porque alguém vai perguntar depois por que aquela sede
teve quarenta minutos a mais.

### Global ou por sede

O ajuste tem **escopo**, e os dois casos existem no mesmo mecanismo:

| Escopo | O que é | Quando usar |
|---|---|---|
| **Prova inteira** | o intervalo removido que a ICPC especifica | algo parou a competição toda |
| **Uma sede** | a extensão por sede, como o MOJ faz | queda de energia numa sede só |

O segundo é o caso real da Maratona: o regulamento manda somar uma fração do
tempo de parada **daquela sede**, e sem isso a sede faz a conta no papel e a
classificação não bate com o placar.

### Duração e congelamento próprios de uma sede

Além do ajuste de tempo, cada sede pode ter **duração** e **congelamento**
próprios, em **Sedes → editar**. Campo em branco quer dizer "segue o contest",
que é o padrão e o que você quer na maioria dos casos.

| Campo | O que faz | Em branco |
|---|---|---|
| Duração própria | a sede corre esse tanto de minutos | segue a duração do contest |
| Congelamento próprio | contado do fim **dessa sede** | segue o congelamento do contest |

Zero no congelamento é diferente de branco: zero quer dizer **sem
congelamento nessa sede**, branco quer dizer "usa o do contest".

> **Cuidado, e é o motivo de este campo vir vazio:** uma sede com duração ou
> congelamento próprios vê o placar congelar em **horário diferente** das
> outras. Quem olha de uma sede pode ver congelado enquanto quem olha de outra
> já vê o quadro aberto. Para quem não tem sede — visitante anônimo, telão
> público, a Contest API — o sistema responde de forma **conservadora**:
> congelado enquanto **qualquer** sede ainda estiver escondendo, porque
> revelar antes entregaria o que aquela sede esconde.
>
> Para **devolver tempo perdido** num incidente, prefira o **ajuste de tempo**
> acima: é registrado, auditável e reversível. A duração própria serve para o
> caso diferente de uma sede que roda um formato mais curto de propósito.

### Máquinas de velocidades diferentes

Quando instituições parceiras emprestam o que têm (#53), o parque fica
heterogêneo — e a mesma solução recebia **TLE numa máquina e AC noutra**. A
tela de **calibração** avisava; a equipe que competiu já tinha levado o
veredito.

Desde o #251 o limite é **servido por máquina**:

```
efetivo = clamp(digitado × fator, digitado × 0,5, digitado × 2,0)
```

O fator sai da medição que a própria prova produz — os **envios aceitos**, a
mesma solução medida em máquinas diferentes. Máquina mais lenta ganha limite
maior; mais rápida, menor. O que se iguala é a **dificuldade**, e não o número
de segundos.

> **Isto muda veredito**, dentro da faixa. O valor que você digita continua
> sendo a referência — a medição só o ajusta entre metade e o dobro, então
> nenhuma máquina fica livre para inventar o próprio limite.

| Situação | O que acontece |
|---|---|
| máquina sem medição ainda | usa o valor digitado, e a calibração avisa que ela está cega |
| prova sem envio aceito | idem — não há o que comparar |
| um problema só, numa máquina só | não é comparação, e não entra na conta |

**Para desligar** e voltar ao comportamento anterior (medir e avisar, sem
agir), ponha piso e teto em 1:

```
JUDGEHOST_LIMIT_FLOOR=1.0
JUDGEHOST_LIMIT_CEILING=1.0
```

### Instituição de cada equipe

**Em Usuários → editar, campo "Instituição".** É por qual universidade ou
escola aquela equipe compete — e é a chave de qualquer agregado nacional por
instituição.

> **Não confunda com as permissões do banco de problemas.** São duas coisas
> diferentes, e antes do #270 o sistema confundia: a instituição era *inferida*
> de `organization_memberships`, a tabela que diz **quem pode editar o acervo
> de** uma instituição. Quem editava o banco de uma universidade e competia por
> outra aparecia com a errada — e parecia certo.

Antes da prova, rode:

```bash
php artisan affiliations:report
```

Ele sai com **erro** enquanto houver equipe sem instituição, de propósito: um
checklist que passa com afiliação faltando não checa nada. E separa dois casos:

| O que ele diz | O que fazer |
|---|---|
| *"a migração não pôde decidir"* | a equipe tem **mais de uma** instituição na governança do banco. O sistema **não escolhe por você** — quem edita o acervo de uma pode competir por outra. Escolha à mão. |
| *"sem pista nenhuma"* | a equipe nunca tocou o banco de problemas. Preencha a instituição. |

Se você já rodava uma versão anterior, a atualização **derivou** a instituição
de quem tinha exatamente uma — ali a inferência antiga e a resposta certa
coincidem. Rodar de novo não desfaz escolha que você já fez à mão.

### Premiação

A finalização deriva a premiação. **As medalhas vêm zeradas por padrão** —
quantas de cada tipo é decisão de quem organiza, e o sistema não arbitra isso
por você. Configure antes da cerimônia.

---

## 4. Acompanhar

| Tela | Para quê |
|---|---|
| `/backend/submissions` | todos os envios da prova |
| `/backend/clarifications` | fila de clarificações, com resposta e broadcast |
| `/judge/health` | saúde do julgamento: fila, máquinas, atrasos |
| `/backend/judge-machines` | máquinas, capacidades e comparação de velocidade |
| `/backend/similarity` | detecção de semelhança entre códigos |
| `/backend/logs` | registro de operações sensíveis |
| `/backend/webcast` | transmissão do placar |
| `/staff/report` | relatório operacional |

### Calibração de máquinas

A tela de máquinas compara a velocidade dos judgehosts usando os **envios
aceitos que a própria prova produz**. Se uma máquina estiver muito mais lenta
que as outras, a mesma solução pode receber TLE nela e AC na outra.

Hoje o sistema **mede e avisa** — não compensa. Se a divergência for grande,
**tire a máquina do parque** antes da prova. A decisão de derivar limite por
calibração está registrada em
[`docs/specs/251-limite-de-tempo-derivado.md`](../specs/251-limite-de-tempo-derivado.md).

---

## 5. Relatórios e exportações

### O arquivo que você envia depois da prova

O **relatório de classificação da ICPC** — `icpc_id`, colocação, problemas
resolvidos, tempo total e tempo do primeiro AC, uma linha por equipe, na ordem
final. É o mesmo formato que o BOCA produz, e é o arquivo que quem organiza
envia quando a prova acaba.

Duas formas de obter, e a segunda existe por um motivo prático:

```sh
php artisan contest:icpc-report <id-do-contest> --output=classificacao.csv
```

ou baixando por `GET /api/frontend/contests/{contest}/icpc-report`.

> **Confira os `icpc_id` ANTES da prova, não depois.** Equipe sem
> identificador sai do relatório sem servir para nada — e descobrir isso com a
> sala já vazia significa caçar gente para preencher cadastro. O download
> responde quantas equipes estão sem identificador no cabeçalho
> `X-Icpc-Teams-Missing-Id`; ele fica fora do CSV de propósito, porque o CSV
> tem colunas fixas que a ferramenta de quem recebe espera.
>
> O campo fica no cadastro do usuário, em `/backend/users/{user}/edit`.

Prova de treino não tem classificação e não produz este relatório.

### Scratch como linguagem

A partir do #268 o `.sb3` do participante é julgado como qualquer outro
programa. O projeto vira um programa que lê `stdin` e escreve `stdout` por
uma convenção de blocos:

| Bloco | Efeito |
|---|---|
| `say [texto]` | escreve o texto e uma quebra de linha |
| `think [texto]` | escreve **sem** quebra de linha |
| `ask [read_token] and wait` | lê **um token** separado por espaço |
| `ask [qualquer outra pergunta] and wait` | lê **uma linha inteira** |

O valor lido fica no reporter `answer`.

> **Ao escrever o problema, evite blocos que tornam o julgamento
> irreprodutível:** `pick random`, `timer`, `days since 2000` e
> `current [hora]`. Dois envios idênticos precisam receber o mesmo veredito,
> e esses blocos quebram isso — o programa passa numa execução e falha na
> seguinte, sem ninguém ter mudado nada.

> **Scratch é ordens de grandeza mais lento que C++.** Use o limite de tempo
> **por linguagem** (`problem_language_limits`) em vez de afrouxar o limite do
> problema para todo mundo. Atenção: o limite do sandbox é **tempo de CPU**, e
> não de relógio.

Duas coisas que ficam de fora nesta fase, de propósito: **similaridade** não é
oferecida para Scratch (a tela responde 422 e não quebra), e o **Treino Livre**
não aceita envio binário.

Quem não quiser Scratch numa prova desativa a linguagem em **Linguagens**,
naquele contest.

### Portugol Studio como linguagem

A partir do #269 o `.por` do Portugol Studio (UNIVALI) é julgado como qualquer
outro programa. Duas coisas que **quem escreve o problema precisa saber**, e
que não são detalhe:

> **Um valor por linha.** O console do Portugol Studio lê com `nextLine()` —
> **uma linha inteira por `leia`**. Um caso de teste com `3 5` na mesma linha
> não preenche duas variáveis: o programa simplesmente não produz saída, em
> silêncio. Escreva os casos com um valor por linha.

> **Dê mais tempo, por linguagem.** O Portugol Studio **compila o programa
> para Java em tempo de execução** — chama o `javac` e sobe uma segunda JVM.
> Duas partidas de JVM não cabem em um segundo de CPU. Use o limite **por
> linguagem** (`problem_language_limits`) em vez de afrouxar o limite do
> problema para todas: medido, 30 segundos é folgado e 1 segundo não passa.

Detalhes de formatação que mudam a saída esperada:

| | |
|---|---|
| `escrever(real)` | independente de locale (`3.14`, não `3,14`), mas **notação científica** fora de `[10⁻³, 10⁷)` — `10000000.0` sai `1.0E7` |
| booleano | sai em português: `verdadeiro` / `falso` |

Erro de sintaxe dá **CE** e diz a linha e a coluna — não vira "resposta
errada". Similaridade não é oferecida para Portugol (responde 422, não
quebra), e o Treino Livre não entra nesta fase.

Quem não quiser Portugol numa prova desativa a linguagem em **Linguagens**,
naquele contest.

### Recursão profunda: o Bash não aguenta, e não há o que configurar

Uma busca em profundidade recursiva sobre um grafo de 10 mil vértices é
código banal — e **em Bash ela não roda**, em nenhum limite que faça sentido
numa prova. Isto é o que sobrou da #327 depois que Python, Node e TypeScript
foram resolvidos: aquelas três tinham botão, o Bash não tem.

Medido nesta imagem do juiz (aarch64, `bash` 5.3.9), a recursão
`f(n) = n + f(n-1)` escrita do jeito natural — com `$(...)`, que é como se
devolve valor de uma função em shell:

| profundidade | CPU total |
|---:|---:|
| 100 | 0,06 s |
| 200 | 0,30 s |
| 300 | 1,09 s |
| 400 | 3,12 s |
| 500 | **7,37 s** |

O custo é da ordem de `n⁴`. Ou seja: **por volta da profundidade 450 o
programa já estourou um limite de 5 segundos de CPU**, e a profundidade 10⁴
da issue está fora de alcance por várias ordens de grandeza. Pior: a partir de
cerca de mil níveis o Bash não falha limpo — ele imprime
`arithmetic syntax error: operand expected` e devolve **saída vazia**, que
para o juiz é resposta errada e não erro de execução.

> **Não existe `ulimit` nem variável que conserte isso.** O `FUNCNEST` do Bash
> só serve para **limitar** o aninhamento, nunca para aumentá-lo: medido,
> `FUNCNEST=100000` não muda nada e `FUNCNEST=50` faz falhar antes.
> `shopt` e `set -o` não têm nada sobre profundidade. Não há equivalente ao
> `sys.setrecursionlimit` do Python nem ao `--stack-size` do Node.

Reescrita sem `$(...)` — passando o resultado por variável global, que já não
é a forma natural — o Bash chega a 10⁴, mas só com a pilha aumentada
(`ulimit -s` de 8192 dá *segmentation fault*; a partir de 32768 funciona) e
gastando **26,4 s de CPU**. Continua fora de qualquer limite de prova. Subir a
pilha troca um `RE` por um `TLE`, e não um `TLE` por um `AC`.

**O que fazer ao montar a prova:**

- Se o problema admite solução recursiva profunda, **escreva a solução de
  referência de forma iterativa** e verifique que ela cabe no limite. Isso vale
  para todas as linguagens, não só para o Bash.
- **Não conte com recursão profunda em Bash.** Se o enunciado depende disso,
  ou o Bash sai daquela prova (em **Linguagens**, naquele contest), ou o
  problema aceita solução iterativa.
- O limite **por linguagem** (`problem_language_limits`) resolve *partida de
  runtime* lenta — é para isso que ele existe, e é o que o Portugol Studio, o
  Scala e o Groovy usam. Ele **não** resolve este caso: aqui o custo cresce com
  a entrada, e nenhum limite praticável alcança.

Para referência, as outras três linguagens da #327 — Python, Node e
TypeScript — **foram resolvidas** e aguentam 10⁴ níveis. O Bash é a exceção
que sobrou *entre elas*, e sobrou por não haver o que configurar.

**Mas o Bash não é a única linguagem do catálogo que não aguenta 10⁴ níveis.**
A #327 nasceu olhando 24 linguagens; hoje são 48, e a varredura completa
(`tests/E2E/LanguageConformanceTest.php`, o mesmo programa em todas, julgado
pelo juiz de verdade) encontrou mais sete. Nenhuma é defeito do juiz — é o
que cada runtime aguenta:

| Linguagem | O que acontece | O teto, medido | Onde mora o teto |
|---|---|---|---|
| `sh` | `TLE` | ~450 níveis em 5 s de CPU | custo `n⁴`, ver acima |
| `groovy` | `RE` | passa em 800, estoura em 900 | `StackOverflowError` |
| `tcl` | `RE` | 1000 | `interp recursionlimit` |
| `lisp_clisp` | `RE` | passa em 3000, estoura em 5000 | pilha do CLISP 2.49 |
| `r` | `RE` | passa em 2000, estoura em 5000 | `options(expressions)` |
| `nim` | `RE` | 2000 | `nim c` sem `-d:release` |
| `clj` | `RE` | corte não medido | `StackOverflowError`, quadro gordo do Clojure |
| `portugol_studio` | `RE` | corte não medido | o próprio interpretador detecta e aborta |

As duas últimas são mais novas que as outras seis, e entraram aqui pelo
caminho que este manual recomenda: a tabela de conformidade afirmava que elas
aguentavam, a suíte rodou dentro da imagem do juiz, o juiz respondeu `RE` nas
duas, e quem estava errado era a tabela.

Dois controles que valem a pena conhecer, porque eles mostram que o problema é
da implementação e não do programa: o `lisp_sbcl` compila **a mesma fonte** do
`lisp_clisp`, byte a byte, e faz os 10⁴ níveis; e o `scala`, na **mesma JVM**
do `groovy` e do `clj`, também faz — das três linguagens de JVM do catálogo,
só o Scala passa, o que localiza o custo no quadro de cada linguagem e não na
pilha da máquina virtual. As outras 40 linguagens ativas passam.

O `nim` continua sendo o único dos oito com conserto **medido** do nosso lado:
o teto de 2000 quadros é do *build de depuração*, e o mesmo programa compilado
com `nim c -d:release` devolve o resultado certo. Fica registrado aqui porque
mudar o comando do catálogo é decisão de quem mantém a imagem, não deste
manual.

O `clj` é candidato ao mesmo tratamento, e a diferença de palavra importa:
`docker/judge/bin/clojure-run` chama `java -cp .../clojure.jar clojure.main`
**sem `-Xss`**, então a pilha é a padrão da JVM — mas ninguém mediu se um
`-Xss` maior resolve, e enquanto não medir este manual não vai dizer que
resolve. O `portugol_studio` não tem esse botão: quem barra é o próprio núcleo
do Portugol, e não a pilha por baixo dele.

> **Se um problema seu depende de recursão profunda em Portugol, leia a
> mensagem que a equipe recebe.** O núcleo do Portugol Studio diagnostica o
> estouro como *"existe alguma função do programa que está sendo chamada de
> forma recursiva sem uma condição de parada"* e mostra um exemplo de recursão
> infinita. Numa recursão legítima de 10⁴ níveis **esse diagnóstico está
> errado** — a condição de parada existe, o que faltou foi pilha. A equipe vai
> caçar um defeito que não tem.

### O que cada linguagem aguenta, medido

A recursão acima é uma de onze propriedades medidas em **todas** as linguagens
ativas por `tests/E2E/LanguageConformanceTest.php`, com o juiz de verdade e o
sandbox ligado. Cada item tem **controle positivo** — o `a+b` correto da mesma
linguagem, pelo mesmo caminho —, sem o qual um `RE` não distinguiria "o juiz
detectou o erro" de "o juiz reprova tudo nessa linguagem". Os números saem em
`storage/logs/conformidade-linguagens.jsonl`. Medido em `aarch64`, com cgroup
v2 delegado.

**As armadilhas que mudam o enunciado**

| O que | Onde | O que fazer ao escrever o problema |
|---|---|---|
| **Recursão de 10⁴ níveis reprova** | as oito da tabela acima | uma DFS recursiva sobre 10 mil vértices não passa nelas. Se o problema exige profundidade, diga no enunciado |
| **`inteiro` do Portugol é de 32 bits** | `portugol_studio` | resposta acima de 2 147 483 647 é impossível: medido, 5000050000 sai 705082704. Dimensione o caso de teste |
| **Divisão inteira por zero não quebra em `aarch64`** | `c_*`, `cpp_*`, `f90`, `f77`, `cob` | o `sdiv` do ARM devolve 0 em vez de gerar exceção (em COBOL, sem `ON SIZE ERROR`, o resultado fica inalterado). O mesmo programa dá `RE` num juiz x86 e `WA` num juiz ARM |
| **O GNU Prolog não passa de 32 MB** | `prolog_gnu` | as pilhas do runtime são fixas; um problema que exija estrutura grande não tem solução nessa linguagem, qualquer que seja o `memory_limit` |
| **A entrada do Portugol vai uma por linha** | `portugol_studio` | o console lê com `nextLine()`, um valor por `leia` |

**Custo fixo de partida** — quanto a linguagem gasta antes da primeira linha do
programa da equipe. É este número que obriga a usar limite **por linguagem**
(`problem_language_limits`) em vez de afrouxar o limite do problema para todos.

| | parede |
|---|---|
| as 26 compiladas nativas (C, C++, Rust, Go, Pascal, Zig, Nim, Crystal, D, Haskell, OCaml, Fortran, Ada, Dart, COBOL, os dois Prolog…), mais Perl, Bash, sed, Lua, AWK, Tcl e SBCL | abaixo de 10 ms |
| Python, PHP, CLISP, Guile | ~10 ms |
| Java 21/25, Node, TypeScript, Kotlin, C#, Ruby, Scala | 20 a 50 ms |
| Scratch | ~110 ms |
| R | ~160 ms |
| Groovy | ~600 ms (o `groovy` **compila** o script antes de rodar: 1,4–1,8 s de CPU) |
| Clojure, Racket | 510 a 720 ms |
| **Portugol Studio** | **~980 ms de parede, 2,7 s de CPU** — não cabe em `ulimit -t 1` |

**Ler 10⁵ inteiros com a E/S que um competidor escreve sem pensar** — todas dão
conta dentro de 5 s de CPU; o que varia é a margem.

| | parede |
|---|---|
| C, C++, Rust, Go, Pascal, OCaml, Perl, PHP, AWK, D, Fortran, Ada, Python, Lua, COBOL, Prolog | até 20 ms |
| SBCL, Crystal, C#, Zig, Haskell, TypeScript, Tcl | 30 a 60 ms |
| Kotlin, Ruby, Nim, Node, CLISP, Guile, Scala, Dart | 80 a 260 ms |
| Java 21/25, R, Racket, Bash, Clojure, Groovy | 200 a 580 ms |
| Portugol Studio | ~990 ms |

**O que é garantido em todas as linguagens ativas** (medido, item a item): laço
infinito vira `TLE`; alocação sem limite vira `MLE`; programa sintaticamente
inválido vira `CE` **com a linha do erro**; programa que morre em execução vira
`RE`; programa que imprime a resposta certa e sai com código diferente de zero
vira `RE`; `printf("%.2f")` e equivalentes imprimem `3.14` e nunca `3,14`; o
que o programa escreve em `stderr` não entra na saída comparada e chega ao juiz
como diagnóstico; e toda linguagem roda também no caminho **sem** cgroup v2
delegado, que é o de um contêiner não privilegiado.

**Uma ressalva honesta sobre carga.** Num judgehost saturado, os *backstops* de
tempo de parede do juiz podem disparar e produzir `CS` — que não é veredito
sobre o programa da equipe. Foi medido acontecendo com `kotlinc` e `g++` numa
máquina com vários contêineres disputando CPU. É mais um motivo para não rodar
prova num judgehost compartilhado com outra coisa.

### O pacote de resultados, para um ranking nacional

```
GET /api/frontend/contests/{contest}/results-bundle
```

Um ZIP com quatro arquivos, gerado **somente de prova finalizada**:

| Arquivo | O que é |
|---|---|
| `manifest.json` | identidade da prova, datas, e o **SHA-256 de cada arquivo** |
| `standings.json` | classificação, equipes, problemas e premiação, formato CLICS |
| `standings.csv` | as cinco colunas do BOCA, inalteradas |
| `organizations.json` | as instituições referenciadas |

Dois consumidores num pacote só: a ferramenta que lê CLICS, e quem já espera o
arquivo do BOCA.

**Por que só de prova finalizada.** Exportar resultado provisório — com
rejulgamento pendente, ou com o placar ainda congelado — envenena o agregado.
A rota recusa com o motivo escrito, e não em silêncio.

**A propriedade que torna o pacote confiável:** exportar a mesma prova duas
vezes produz **os mesmos bytes**, exceto `generated_at`, que fica isolado num
campo só do manifesto. O site nacional não precisa *confiar* no arquivo que
recebeu — ele pede a reexportação e compara. Adulteração aparece como
divergência, não como suspeita.

> **O que o pacote leva de pessoal, e o que não leva.** A régua é: *não
> exporta mais do que o placar público já mostra, mais a chave de agregação*.
> O `icpc_id` da equipe **entra**, porque é o que torna a agregação possível.
> Data de nascimento, e-mail e qualquer campo de privacidade de perfil (#47)
> **não entram**, e há teste que varre o ZIP inteiro procurando por eles.

Antes de enviar, confira `affiliations:report` (acima) — equipe sem instituição
ou sem `icpc_id` entra no pacote sem a chave que o agregador usa, e o próprio
`standings.json` lista quem ficou de fora em `teams_missing_icpc_id`.

**Edição e fase** (em Configurações → editar contest) valem a pena preencher:
uma regional e uma final nacional não são o mesmo tipo de evento no agregado, e
o nome da prova sozinho não carrega essa distinção.

### As outras saídas

| O quê | Onde |
|---|---|
| Placar em CSV | `/scoreboard/export` |
| Relatório por sede | `/api/frontend/reports/site` |
| Histórico de julgamento | `/api/frontend/reports/judge-history` |
| Transmissão do placar (formato BOCA) | `/api/frontend/webcast/export` |
| Pacote de um problema | `/api/problems/{problem}/export` |
| Ponto de restauração | `php artisan backup:create` |

---

## 6. Integrações

- **Contest API (ICPC/CLICS)** — `/api/clics/*`, incluindo o **event feed**
  (`/api/clics/contests/{id}/event-feed`), que é o que um *resolver* lê para
  a cerimônia.
- **API autenticada** — tokens Sanctum, emitidos e revogáveis pelo titular.
  `/api/health` para monitoramento, `docs/api/openapi.yaml` para a
  especificação.

> **Antes de usar o resolver numa prova real**, rode a homologação descrita em
> [`docs/runbooks/252-event-feed-streaming.md`](../runbooks/252-event-feed-streaming.md).
> O streaming do feed depende de configuração de proxy que não dá para
> verificar com teste automatizado.

---

## 7. Backup

`php artisan backup:create` gera um ponto de restauração.

Faça um **antes** da prova e **depois** dela. Um backup que nunca foi
restaurado não é um backup — teste a restauração antes do evento, não durante.

---

## 8. Checklist do evento

### Semanas antes
- [ ] Contest criado, com início, duração, congelamento e penalidade
- [ ] Sedes cadastradas, com faixa de IP se for usar trava de rede
- [ ] Linguagens cadastradas **e** instaladas nos judgehosts
- [ ] Problemas importados; enunciados abrem; limites conferidos
- [ ] Organizações cadastradas
- [ ] `judgehost:selftest` passou em **cada** máquina de julgamento
- [ ] Máquinas comparadas na calibração; as destoantes fora do parque

### Dias antes
- [ ] Contas criadas e distribuídas; equipes conseguem entrar
- [ ] **`icpc_id` preenchido em todas as equipes** — conferir agora, não
      depois da prova (seção 5)
- [ ] Ambiente de treino (`/practice`) aberto e testado
- [ ] Prova de carga de envios feita
- [ ] Backup criado **e restaurado** num ambiente de teste
- [ ] Event feed homologado, se for usar resolver
- [ ] Papéis conferidos: ninguém com `admin` sem precisar

### No dia
- [ ] Relógio do servidor conferido
- [ ] Contest ativado
- [ ] Juízes e staff conseguem acessar as telas deles
- [ ] Alguém de plantão na fila de clarificações e na de S.O.S.

### Durante
- [ ] Fila de julgamento sem acúmulo (`/judge/health`)
- [ ] Clarificações sendo respondidas
- [ ] Congelamento aconteceu no horário, e a tela **diz** que está congelado

### Depois
- [ ] Encerrar
- [ ] Conferir se há rejulgamento pendente antes de revelar
- [ ] Revelar (cerimônia)
- [ ] Finalizar e conferir a premiação
- [ ] Gerar o **relatório de classificação da ICPC** e conferir que nenhuma
      equipe ficou sem `icpc_id` (seção 5)
- [ ] Exportar placar e demais relatórios
- [ ] Backup final

---

## 9. O que pode dar errado, e o que fazer

**A fila de julgamento parou de andar.**
Veja `/judge/health`. Provavelmente um judgehost caiu. Há um *watchdog* que
recupera runs presas, mas ele recupera em silêncio — a fila crescendo é o
sinal. Suba outro judgehost; o sistema distribui sozinho.

**Um problema está com defeito.**
Peça ao juiz para **pausar** o problema (`/judge/problems/{problem}/pause`).
Enquanto pausado, os envios esperam em vez de receber veredito errado.
Corrija, retome, e rejulgue o que já tinha veredito.

**Uma sede caiu.**
Registre um **ajuste de tempo** com escopo **daquela sede** — é o caso que o
mecanismo foi feito para atender, e é o que o regulamento da Maratona manda
fazer. Veja *Global ou por sede*, na seção 3. (Uma versão anterior deste
manual dizia que o ajuste era só do contest inteiro; estava errado, e a
coluna de escopo existe desde o #198.)

**Envios estão virando `CS` (erro de julgamento) quando a prova aperta.**
`CS` quer dizer *"a nossa infraestrutura falhou"* — nunca é um veredito sobre
o programa da equipe, e quem recebeu um precisa ser **rejulgado**.

Desde o #329 a mensagem diz o que aconteceu. Abra o envio e leia
`auto_judge_stderr`: se estiver escrito *"O julgamento excedeu N s de tempo de
parede na etapa de …"*, o que estourou foi o **relógio do juiz**, e a etapa
(`compilacao`, `execucao` ou `comparacao`) diz onde.

| A etapa que aparece | O que ajustar |
|---|---|
| `compilacao` | `AUTOJUDGE_COMPILE_TIMEOUT` (padrão 30 s) |
| `execucao` | `AUTOJUDGE_WALL_CONTENTION_FACTOR` (padrão 3) |
| `comparacao` | idem — o comparador usa o mesmo fator |

> **Não é o limite de tempo do problema.** Esses são relógios de segurança da
> máquina de julgamento; afrouxá-los **não dá mais CPU a ninguém** e não muda
> o veredito de quem estourou o limite do problema — esse continua saindo do
> `ulimit -t`, como `TLE`.

A causa quase sempre é **workers demais para os núcleos que a máquina tem**.
Medido (#126), passar da contagem de CPUs não adiciona vazão nenhuma — só
fila, e a fila é o que vira `CS`. Antes de subir os números acima, confira
quantos `autojudge:start` estão rodando.

**Precisa corrigir vereditos em lote.**
Rejulgamento em lote, com **prévia obrigatória**: o juiz vê o que vai mudar
antes de aplicar, e pode cancelar. Veja o [manual do juiz](juiz.md).

**O placar está estranho depois de um rejulgamento.**
O rejulgamento recalcula a pontuação. Se ainda parecer errado, `/backend/logs`
mostra o que foi feito e por quem.

**Nada está sendo julgado, e não há mensagem de erro.**
Rode `php artisan judgehost:selftest` na máquina que deveria julgar: é a
autoridade sobre "esta máquina pode julgar". As três causas que ele pega
antes de qualquer teste:

| O que ele diz | O que aconteceu |
|---|---|
| `AUTOJUDGE_USE_BWRAP esta DESLIGADO` | alguém desligou o sandbox |
| `bwrap ausente` | a máquina não tem bubblewrap |
| `Rodando como root` | o julgamento está numa imagem que não baixou privilégio |

> **O compose de desenvolvimento não julga, de propósito** — ele não tem
> serviço de juiz. Use `docker-compose.yml` quando o julgamento importar.
> Desde o #282 isso não é mais silencioso: o daemon **recusa subir** numa
> máquina que não confina, e um envio que chegue à fila ali fica **pendente
> com o motivo escrito**, visível no envio e em `/backend/logs`.
>
> E **não tente "fazer funcionar"** instalando o bubblewrap na imagem da
> aplicação: medido, nesse arranjo o `/etc/shadow` fica legível de dentro do
> sandbox. Você estaria executando código de competidor sem confinamento
> nenhum, achando que estava confinando.

**Alguém se cadastrou sozinho e não consegue entrar.**
É o comportamento correto. `/register` cria a conta **desabilitada e sem
sessão** — quem libera é você, em `/backend/users`. Se a sua instalação não
deve aceitar auto-cadastro nenhum, ponha `REGISTRATION_OPEN=false` no `.env`:
a rota passa a responder 404 e o link desaparece da tela de login.

**Uma equipe não consegue entrar.**
Nesta ordem: senha; trava de IP da sede; conta ativada; papel correto;
bloqueio por excesso de tentativas.
