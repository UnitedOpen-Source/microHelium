# Issue #269, parte A — Portugol Studio como linguagem do auto-judge

## O que entrou

`.por` do Portugol Studio (UNIVALI) — o pseudocódigo em português usado para
ensinar algoritmos no Brasil, com mais de 200 mil usuários em universidades e
institutos.

A **parte B (G-Portugol)** não entrou. As medições que bloquearam estão na
issue própria; resumo ao final deste documento.

## A etapa de compilação não podia ser o console, e isso foi medido

A issue previu o defeito, e ele se confirmou ao pé da letra. Quatro medições
contra o console real:

| Invocação | Resultado |
|---|---|
| `-no-wait`, programa **inválido** | **código 0** ← o defeito |
| sem `-no-wait`, programa inválido | código 1 |
| sem `-no-wait`, programa **válido que lê** | **código 1** — falso CE (stdin fechado vira `NoSuchElementException`) |
| sem `-no-wait`, laço infinito | **trava** — o console executa |

Ou seja: **não existe combinação de flags que analise sem executar.**

Com `-no-wait`, um erro de sintaxe passaria da compilação e viraria **WA** —
dizendo à equipe que a resposta está errada quando o programa nem compilou.
Sem `-no-wait`, todo programa que lê entrada vira CE e um laço trava a fila.

`docker/judge/portugol/VerificaPortugol.java` chama
`Portugol.compilarParaAnalise()`, a fachada do núcleo que **analisa e não
executa**. Sem stdin, sem laço, e com o código de saída que o
`AutoJudgeService` lê para decidir CE. Imprime **linha e coluna de cada
erro**: o `getMessage()` do `ErroCompilacao` é genérico, e quem recebe CE
precisa saber onde consertar.

O módulo `checker` do Portugol Studio **não serve** para isto: é um corretor
automático (`Corretor`, `Comparador`, `CasoFalho`), não um validador.

## Duas características da linguagem que mudam como se escreve o problema

Nenhuma das duas é defeito da integração; as duas apareceram ao fazer o
julgamento real passar, e ambas estão no manual do organizador.

**1. Uma linha por `leia`.** O console lê com `Scanner.nextLine()`. Um caso de
teste com `3 5` na mesma linha **não** preenche duas variáveis — o programa
não produz saída nenhuma, em silêncio. A suíte de linguagens ganhou entrada
declarável por linguagem por causa disso, em vez de mudar a entrada de todas.

**2. Compila para Java em tempo de execução.** O console chama `javac` e sobe
uma segunda JVM. Medido: com o `ulimit -t 1` que o problema de teste aplica, o
console engole a falha como *"Erro na compilação!"* — um erro que **parece**
de compilação e é de tempo.

Isso também é a razão de a imagem não poder trocar `openjdk21-jdk` por
`-jre`: sem `javac` a execução morre com *"Cannot run program javac"*. Está
escrito no `Dockerfile.judge`, onde alguém tentaria a troca.

A saída é `problem_language_limits`, que existe exatamente para isso, e usá-la
no teste é o que prova que ela funciona — afrouxar o limite do **problema**
daria mais tempo a todas as linguagens, que é o que aquele campo existe para
evitar.

## Empacotamento

Os releases publicam só instaladores de ~123 MB, **sem jar do console**, então
não há o que baixar: construído do fonte, com **commit fixado** (o upstream
está parado desde abril de 2023, e sem release para acompanhar o pino importa
ainda mais).

JDK 11 no estágio de construção e não 21: o projeto usa **Gradle 4.10**
(2018), que não roda em JDK moderno. O jar resultante é bytecode 11 e roda
sob o JDK 21 da imagem final — medido.

`:portugol-console` e não `:console`: o `settings.gradle` renomeia todo
subprojeto com o prefixo `portugol-`, e `:console` responde *"Project 'console'
not found"*.

O layout `lib/` ao lado do jar é preservado porque
`Console.getClassPathParaCompilacao()` monta o classpath lendo aquele
diretório — achatar quebra a execução.

## Por que dois invocadores, e não um

`MachineCapabilities::executableOf()` olha o **primeiro token** do comando e o
sonda com `command -v`. Comandos escritos como `java -jar ...` anunciariam a
capacidade `java`, e não `portugol_studio` — o roteamento mandaria runs de
Portugol para máquinas que só têm Java.

## Parte B (G-Portugol) — por que ficou de fora

Três bloqueios independentes, cada um medido:

| Tentativa | Onde parou |
|---|---|
| build no Alpine | `configure` exige `antlr-config` — o **runtime C++** do ANTLR 2.7, não empacotado |
| build no Debian | passa do `configure`; o `make` morre em `Token stream error reading grammar(s)` |
| binário oficial 1.2.0 | **só x86_64** — não cobre judgehost arm64 |

A issue dizia para decidir com o resultado da tentativa, e o resultado é este.
Registrado em issue própria.
