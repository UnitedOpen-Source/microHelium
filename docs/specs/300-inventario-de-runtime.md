# Issue #300 — inventário das dependências de runtime do auto-judge

**Revisão de 21/09/2026.** Medido contra o `master` de hoje (`fd98b93`), nas
imagens `helium-judge:rev21c` e `helium-app:rev21c`, construídas de `9cdbc0a`
— o último commit que tocou um Dockerfile, conferido por `git log`.
Nada aqui foi copiado da revisão anterior: cada número foi remedido, e os que
mudaram estão marcados na seção *O que mudou desde 20/09*.

> **Por que este arquivo existe, e por que ele não é mais o corpo da issue.**
> O conteúdo desta página é **derivado do repositório** — contagem de
> linguagens, pinos de versão, procedência. Ele apodrece no minuto em que
> `app/Models/Language.php` ou um dos três Dockerfiles muda, e de 19 a
> 21/09/2026 isso aconteceu **onze vezes** (PRs #336, #344, #345, #346, #351,
> #353, #358, #360, #364, #367 e #369 — contado por `git log` sobre esses
> quatro arquivos, e não de memória). **Três delas entraram enquanto esta
> revisão era escrita**, e as três foram apanhadas por mecanismo e não por
> atenção: o #358 fez o CI reprovar com *"a entrada ativa `cs_dotnet10` não
> está citada no inventário"* e mais quatro asserções; o #367 e o #369
> chegaram no rebase seguinte, e o #369 ainda corrigiu uma medição deste texto
> que tinha consultado o repositório errado do Alpine.
>
> Um corpo de issue não pode ser guardado por teste; um arquivo
> pode. Quem guarda este é
> `tests/Unit/Judge/InventarioDeRuntimeTest.php`, e ele reprova quando o
> repositório e este texto discordam. A #309, ao contrário, descreve estado
> de repositório **de terceiro**, que nenhum teste daqui pode conferir — e
> por isso continua sendo issue.

## Por que este inventário existe

O microHelium é uma plataforma de maratona de programação — o mesmo papel do
BOCA e do DOMjudge. O **auto-judge** pega cada submissão, compila, executa
contra os casos de teste fechados e devolve `AC`, `WA`, `TLE`, `RE`, `CE` ou
`MLE`. Isso quer dizer que **cada linguagem habilitada é uma promessa**: a de
que existe, dentro da máquina de julgamento, uma cadeia de ferramentas capaz
de compilar e executar aquele código de forma isolada e reprodutível.

Nunca houve um lugar só que dissesse o que são essas dependências, de onde
cada uma vem e o que está fixado. Este arquivo é esse lugar.

## Os números, medidos hoje

<!-- Guardados por InventarioDeRuntimeTest. Não edite sem remedir. -->

- **78** entradas no catálogo `Language::getDefaultLanguages()`
- **51** ativas (`is_active => true`)
- **27** inativas
- **39** linguagens distintas entre as ativas — C tem três entradas, C++
  quatro, e C#, Fortran, Prolog e Common Lisp duas cada, e Java três
- **56** executáveis distintos que o catálogo ativo chama (primeiro token de
  `compile_command` e `run_command`, pela regra de
  `ToolchainManifest::executableOf()`)
- **38** pacotes `apk` exigidos por linguagem ativa, mais **3** de
  infraestrutura (`bash`, `bubblewrap`, `libstdc++`) = **40** distintos,
  porque o `bash` é as duas coisas (exigência do `sh`, do C# e do JavaScript,
  e o `bash -c` que embrulha todo comando julgado) — que é exatamente a lista
  do perfil `completo` de `docs/specs/306-perfis-de-toolchain.md`
- **40** pacotes fixados no estágio final do `Dockerfile.judge` — exatamente
  os 40 acima. Até 24/09/2026 eram 42: `erlang27` e `elixir` ficavam
  instalados, fixados e **não oferecidos** (#339), e saíram das três imagens
  quando o pino morreu no Alpine — ver a seção própria

O conjunto de extensões ativas mudou **uma vez** desde a revisão de 20/09: o
PR #358 acrescentou `cs_dotnet10` (.NET 10 LTS ao lado do 8). As outras 48 são
as mesmas, conferido por diff do catálogo entre `383b0ba` e `9cdbc0a`. O resto
do que mudou foram comandos, imagens e guardas.

## Como o julgamento acontece: são DOIS caminhos, não um

Esta seção é a origem da #302 e da #352, e desde o PR #361 ela é a única do
inventário com **job de CI cobrindo os dois lados**.

| Caminho | Quem roda | Imagem | Onde está definido | Job de CI |
|---|---|---|---|---|
| Daemon dedicado | `php artisan autojudge:start` | `Dockerfile.judge` | serviço `autojudge` do `docker-compose.yml` | `judge-image` |
| Fila | `php artisan queue:work` → `JudgeRunJob` → `AutoJudgeService` | `Dockerfile` | serviços `queue`/`app` | `app-image` |

Os dois chamam o **mesmo** `AutoJudgeService`. O que muda é a imagem embaixo.

**O buraco, dito em uma linha:** até o PR #361 nenhum job construía o
`Dockerfile` da aplicação, e as duas vezes em que a imagem da aplicação
divergiu da do juiz — #302 (Scratch e Portugol Studio faltando: submissão
**correta** virava `CE`) e #352 (a correção da #301 não aplicada: erro de
execução continuava virando `WA` mudo) — foram descobertas **à mão**. O job
`app-image` do `.github/workflows/ci.yml` agora constrói a imagem da
aplicação e roda `MultiLanguageJudging|LanguageVerdictFidelity` dentro dela.

**Medido hoje, nas duas imagens construídas deste commit:**

1. **Presença.** Os 56 executáveis do catálogo ativo existem nas duas
   imagens. `command -v` em `helium-judge:rev21c` e em `helium-app:rev21c`,
   as duas construídas de `9cdbc0a`: **zero ausentes nas duas**.
2. **Identidade, que é a checagem que a revisão anterior teve de inventar.**
   `command -v` responde pelo *nome*, não pelo conteúdo — em 20/09 o
   `portugol-studio` da imagem da aplicação existia e era **outro programa**
   (o Console com `-no-wait`). Os cinco invocadores medidos hoje:

   | Invocador | md5 **igual nas duas imagens** | De onde ele sai |
   |---|---|---|
   | `/usr/local/bin/portugol-studio` | `40051ad869ea03ee6991c704328dfb76` | `printf` no Dockerfile |
   | `/usr/local/bin/portugol-studio-check` | `561e91dce160d0d04ad6aa02c6c46947` | `printf` no Dockerfile |
   | `/usr/local/bin/scratch-run` | `1291730af24719066e049a889397e679` | `printf` no Dockerfile |
   | `/usr/local/bin/csharp-net8` | `dd72e53dd012bfcf8d099784b70393f3` | `COPY docker/judge/bin/` |
   | `/usr/local/bin/csharp-net10` | `71b9fbff3c2801d022b72dff09aef8bc` | `COPY docker/judge/bin/` |

   **E a paridade entre as três imagens deixou de depender de medição.** As
   linhas de `printf` que geram os três primeiros são **byte a byte iguais**
   nos três Dockerfiles (conferido nesta revisão), e os dois últimos vêm de um
   `COPY --chmod=755 docker/judge/bin/ /usr/local/bin/` idêntico nos três.
   Quem guarda isso é
   `ToolchainManifestParityTest::test_os_invocadores_sao_os_mesmos_nos_tres_dockerfiles()`
   (PR #353), que compara a **receita** de cada invocador nos três arquivos.
   Era exatamente essa a checagem que faltava quando a #352 aconteceu: o
   `portugol-studio` existia nas duas imagens e era outro programa numa
   delas, e nenhum teste sabia comparar conteúdo.

3. **Listas de `apk`.** As **41** linhas fixadas dos três Dockerfiles são
   hoje **idênticas** — zero divergências. A única divergência que a revisão
   de 20/09 registrava (`libstdc++` fixado no `Dockerfile` e no
   `Dockerfile.dev` e **não** no `Dockerfile.judge`) foi fechada pelo PR
   #353. O que diverge entre os três são apenas pacotes **sem pino** e de
   papel diferente: `coreutils`, `libc-dev`, `musl-dev`, `procps` e
   `py3-pip` só no juiz; `mariadb-client`, `mariadb-connector-c` e
   `supervisor` só na aplicação e no dev.

Essa paridade deixou de depender de atenção humana: `ToolchainManifest` +
`DockerfileToolchain` + `tests/Unit/Judge/ToolchainManifestParityTest.php`
(PR #353) reprovam estaticamente uma imagem que não receba ordem de instalar
o que uma linguagem ativa exige.

## De onde vem cada coisa: nove origens e a imagem base

A procedência de cada programa é declarada em
`ToolchainManifest::provenance()`, e a tabela por linguagem abaixo é
**derivada dali**, nunca redigitada — uma segunda lista escrita à mão é
exatamente o defeito que a #302 mostrou, e o teste que guarda este arquivo
existe para que ela não possa virar uma. O que este inventário acrescenta
por conta própria é o agrupamento por origem externa:

| Origem | Endereço | O que vem de lá | Fixação |
|---|---|---|---|
| Pacote do Alpine | repositórios `main`/`community` | 37 pacotes (tabela abaixo) | `pacote=versão-rN` |
| Release do GitHub | `github.com/JetBrains/kotlin`, `github.com/scala/scala3` | Kotlin, Scala | versão + SHA-256 |
| Arquivo do Apache | `archive.apache.org/dist/groovy` | Groovy | versão + SHA-256 |
| CDN do Google | `storage.googleapis.com/dart-archive` | Dart SDK | versão + SHA-256 **por arquitetura** |
| Tarball do SourceForge | `sourceforge.net/projects/freepascal` | Free Pascal | versão + SHA-256 **por arquitetura** |
| Tarball do GNU | `ftp.gnu.org/gnu/gprolog` | GNU Prolog | versão + SHA-256 |
| Tarball do swi-prolog.org | `www.swi-prolog.org/download/stable/src` | SWI-Prolog | versão + SHA-256 |
| Registro npm | `npm install -g` | TypeScript | versão (`TYPESCRIPT_VERSION`); **hash não** |
| Fonte de terceiro, construído por nós | `github.com/VNOI-Admin/scratch-run`, `github.com/UNIVALI-LITE/Portugol-Studio` | scratch-run, Portugol Studio | **commit** |
| Imagem base | `php:8.3-cli-alpine` (juiz), `php:8.3-fpm-alpine` (aplicação) | `php`, `sed` (busybox) | **nada** |

### As 49 entradas ativas, uma a uma

A coluna do meio é **derivada** de `ToolchainManifest::provenance()` mais o
pino lido do `Dockerfile.judge` — não é transcrita, e o teste que guarda este
arquivo reprova se ela divergir. A da direita é medida dentro de
`helium-judge:rev21c` em 21/09/2026 (`aarch64`, `--network none`); ela não é
derivável sem construir a imagem, e por isso carrega a data.

| Entrada | De onde vem (`ToolchainManifest`) | Runtime medido na imagem |
|---|---|---|
| `c_gcc13` | apk `gcc=15.2.0-r5` | gcc 15.2.0 |
| `c_clang17` | apk `clang22=22.1.3-r2` | clang 22.1.3 |
| `c99_gcc` | apk `gcc=15.2.0-r5` | gcc 15.2.0 |
| `cpp_gpp13` | apk `g++=15.2.0-r5` | g++ 15.2.0 |
| `cpp14_gpp` | apk `g++=15.2.0-r5` | g++ 15.2.0 |
| `cpp17_gpp` | apk `g++=15.2.0-r5` | g++ 15.2.0 |
| `cpp_clang` | apk `clang22=22.1.3-r2` | clang 22.1.3 |
| `java25` | apk `openjdk25-jdk=25.0.4_p7-r0` | javac/java 25.0.4 |
| `java21` | apk `openjdk21-jdk=21.0.12_p8-r0` | javac/java 21.0.12 |
| `java17` | apk `openjdk17-jdk=17.0.20_p8-r0` | javac/java 17.0.20 |
| `py3` | apk `python3=3.14.7-r1`, repo `resources/judge-runtime/python/sitecustomize.py` | CPython 3.14.7 |
| `js_node24` | apk `nodejs=24.18.1-r0`, apk `bash=5.3.9-r1`, repo `resources/judge-runtime/node/run.sh` | Node v24.18.1 |
| `ts` | apk `npm=11.12.1-r0`, npm `typescript@7.0.2`, apk `bash=5.3.9-r1`, repo `resources/judge-runtime/node/run.sh`, apk `nodejs=24.18.1-r0` | tsc 7.0.2 sobre Node 24 |
| `scratch` | estágio `scratch-run-builder`, invocador `scratch-run`, apk `nodejs=24.18.1-r0` | scratch-run 0.1.7 (bundle webpack, 645.450 bytes) |
| `gportugol` | apk `bash=5.3.9-r1`, repo `resources/judge-runtime/gportugol/compile.sh`, estágio `gportugol-builder`, invocador `gpt`, apk `gcc=15.2.0-r5` | o `gpt` é construído do fonte no estágio próprio; o `compile.sh` traduz para C com `gpt -t` e o gcc da imagem compila a segunda etapa |
| `portugol_studio` | estágio `portugol-studio-builder`, repo `docker/judge/portugol/VerificaPortugol.java`, invocador `portugol-studio-check`, apk `openjdk21-jdk=21.0.12_p8-r0`, repo `docker/judge/portugol/ExecutaPortugol.java`, invocador `portugol-studio` | `portugol-console-2.7.5.jar` + 27 jars em `lib/`; nenhuma das duas etapas usa o Console |
| `kt` | baixado `KOTLIN_VERSION`, invocador `kotlinc`, apk `openjdk21-jdk=21.0.12_p8-r0` | kotlinc-jvm 2.4.20 (JRE 25.0.4+7) |
| `scala` | baixado `SCALA_VERSION`, invocador `scalac`, invocador `scala` | scalac 3.3.8 (LTS) |
| `groovy` | baixado `GROOVY_VERSION`, invocador `groovyc`, invocador `groovy` | Groovy 4.0.33 (JVM 21.0.12) |
| `clj` | repo `docker/judge/bin/clojure-check`, invocador `clojure-check`, apk `clojure=1.12.5-r0`, apk `openjdk21-jdk=21.0.12_p8-r0`, repo `docker/judge/bin/clojure-run`, invocador `clojure-run` | Clojure 1.12.5 (`/usr/share/clojure/clojure.jar`, 4.985.093 bytes) |
| `cs_dotnet10` | repo `docker/judge/bin/csharp-net10`, invocador `csharp-net10`, apk `dotnet10-sdk=10.0.303-r0`, repo `resources/judge-runtime/csharp/compile.sh`, repo `resources/judge-runtime/csharp/proj.csproj.template`, apk `dotnet8-sdk=8.0.131-r0`, repo `resources/judge-runtime/csharp/run.sh` | `dotnet --version` 10.0.303 |
| `cs_dotnet` | repo `docker/judge/bin/csharp-net8`, invocador `csharp-net8`, apk `dotnet8-sdk=8.0.131-r0`, repo `resources/judge-runtime/csharp/compile.sh`, repo `resources/judge-runtime/csharp/proj.csproj.template`, apk `dotnet10-sdk=10.0.303-r0`, repo `resources/judge-runtime/csharp/run.sh` | `dotnet --version` 8.0.131 |
| `rs` | apk `rust=1.96.1-r0` | rustc 1.96.1 |
| `go` | apk `go=1.26.8-r0` | go 1.26.8 |
| `d_ldc` | apk `ldc=1.42.0-r0` | LDC 1.42.0 (DMD v2.112.1, LLVM 21.1.8) |
| `nim` | apk `nim=2.2.0-r0` | Nim 2.2.0 |
| `zig` | apk `zig=0.16.0-r1` | Zig 0.16.0 |
| `php` | base `php:8.3-` | PHP 8.3.33 |
| `rb` | apk `ruby=3.4.9-r0` | Ruby 3.4.9 |
| `perl` | apk `perl=5.42.2-r1` | Perl v5.42.2 |
| `lua` | apk `lua5.4=5.4.8-r0` | Lua 5.4.8 |
| `sh` | apk `bash=5.3.9-r1` | GNU bash 5.3.9 |
| `awk` | apk `gawk=5.3.2-r2` | GNU Awk 5.3.2 |
| `sed` | base `php:8.3-` | BusyBox 1.37.0 (`/bin/sed` → `/bin/busybox`) |
| `hs` | apk `ghc=9.10.3-r2` | GHC 9.10.3 |
| `ml` | apk `ocaml=4.14.4-r0` | ocamlopt 4.14.4 (medido em 24/09/2026, depois de o Alpine tirar o 4.14.3 do repositório) |
| `r` | apk `R=4.6.0-r0` | R/Rscript 4.6.0 |
| `lisp_sbcl` | apk `sbcl=2.6.5-r0` | SBCL 2.6.5-85913ede1 |
| `lisp_clisp` | apk `clisp=2.49.95_git250727-r1` | GNU CLISP 2.49.95+ |
| `scm` | apk `guile=3.0.9-r2` | Guile 3.0.9 |
| `rkt` | repo `docker/judge/bin/racket-check`, invocador `racket-check`, apk `racket=9.2-r0` | Racket v9.2 [cs] |
| `pas_fpc` | baixado `FPC_VERSION`, invocador `fpc` | FPC 3.2.2 |
| `f90` | apk `gfortran=15.2.0-r5` | GNU Fortran 15.2.0 |
| `f77` | apk `gfortran=15.2.0-r5` | GNU Fortran 15.2.0 |
| `dart` | baixado `DART_VERSION`, invocador `dart`, apk `gcompat=1.1.0-r4` | Dart SDK 3.13.4 (stable) |
| `cr` | apk `crystal=1.20.3-r0` | Crystal 1.20.3 |
| `prolog_swi` | estágio `swipl-builder`, invocador `swipl`, apk `gmp=6.3.0-r4`, apk `ncurses-libs=6.6_p20260516-r0`, apk `zlib=1.3.2-r0` | SWI-Prolog 10.0.2 |
| `prolog_gnu` | estágio `gprolog-builder`, invocador `gplc`, repo `resources/judge-runtime/gprolog/envolucro.pro` | GNU Prolog 1.5.0 |
| `adb` | apk `gcc-gnat=15.2.0-r5` | GNATMAKE 15.2.0 |
| `cob` | apk `gnucobol=3.2-r0` | cobc (GnuCOBOL) 3.2.0 |
| `tcl` | repo `docker/judge/bin/tcl-check`, invocador `tcl-check`, apk `tcl=8.6.17-r1` | tclsh 8.6.17 |

### Fixado por versão: os 38 pacotes `apk` de linguagem ativa

<!-- Guardado por InventarioDeRuntimeTest: cada pino aqui tem de existir no
     Dockerfile.judge, e cada apk que o manifesto exige tem de estar aqui. -->

`R=4.6.0-r0`, `bash=5.3.9-r1`, `clang22=22.1.3-r2`,
`clisp=2.49.95_git250727-r1`, `clojure=1.12.5-r0`, `crystal=1.20.3-r0`,
`dotnet8-sdk=8.0.131-r0`, `dotnet10-sdk=10.0.303-r0`, `g++=15.2.0-r5`,
`gawk=5.3.2-r2`, `gcc=15.2.0-r5`,
`gcc-gnat=15.2.0-r5`, `gcompat=1.1.0-r4`, `gfortran=15.2.0-r5`,
`ghc=9.10.3-r2`, `gmp=6.3.0-r4`, `gnucobol=3.2-r0`, `go=1.26.8-r0`,
`guile=3.0.9-r2`, `ldc=1.42.0-r0`, `lua5.4=5.4.8-r0`,
`ncurses-libs=6.6_p20260516-r0`, `nim=2.2.0-r0`, `nodejs=24.18.1-r0`,
`npm=11.12.1-r0`, `ocaml=4.14.4-r0`, `openjdk17-jdk=17.0.20_p8-r0`,
`openjdk21-jdk=21.0.12_p8-r0`,
`openjdk25-jdk=25.0.4_p7-r0`, `perl=5.42.2-r1`, `python3=3.14.7-r1`,
`racket=9.2-r0`, `ruby=3.4.9-r0`, `rust=1.96.1-r0`, `sbcl=2.6.5-r0`,
`tcl=8.6.17-r1`, `zig=0.16.0-r1`, `zlib=1.3.2-r0`.

Mais três de infraestrutura, que estão no caminho de **toda** execução
julgada: `bubblewrap=0.12.0-r0` (o confinamento — sem ele o
`AutoJudgeService` se recusa a rodar código submetido), `libstdc++=15.2.0-r5`
(biblioteca de execução em C++ dos toolchains construídos em C++ — LDC e
Crystal arrastam LLVM) e `bash`, que também é de linguagem mas entrou aqui na
#306: o `AutoJudgeService` embrulha todo comando em `bash -c`, e uma imagem por
perfil que o cortasse subiria sem julgar nada.

O critério, escrito no próprio `Dockerfile.judge`: **tudo que compila ou
executa código de competidor está fixado; o que só constrói a imagem
(`git`, `curl`, os `-dev`) não está.**

### Fixado por versão + SHA-256

`KOTLIN_VERSION=2.4.20`, `SCALA_VERSION=3.3.8`, `GROOVY_VERSION=4.0.33`,
`DART_VERSION=3.13.4`, `FPC_VERSION=3.2.2`, `SWIPL_VERSION=10.0.2`,
`GPROLOG_VERSION=1.5.0`. Dart e Free Pascal publicam um hash por
arquitetura, e os dois `ARG` existem (`_AARCH64` e `_X86_64`). Os três
Dockerfiles declaram os mesmos `ARG` — conferido nesta revisão, valor a
valor.

### Fixado por commit

`SCRATCH_RUN_COMMIT=02f11e519210f8593cdcf04f257237fc0ceb7180`,
`PORTUGOL_STUDIO_COMMIT=5640a2e95de1c5d63c11f453c324812b4d6a8f6c`.

### NÃO fixado — e é o buraco que sobra

- **`php`** e **`sed`** vêm da imagem base e **não têm pino nenhum**.
  Medido hoje: PHP **8.3.33** nas duas imagens (`built: Sep 17 2026`), e
  `/bin/sed -> /bin/busybox`, **BusyBox 1.37.0** (`busybox-1.37.0-r31`).
  Note que as duas imagens partem de **tags base diferentes** —
  `php:8.3-cli-alpine` no juiz e `php:8.3-fpm-alpine` na aplicação —, então
  as duas linguagens sem pino são justamente as que podem dessincronizar
  entre os dois caminhos de julgamento sem que nada avise.
- **TypeScript** tem versão fixada e **não tem hash**.
- **Todas as imagens base entram por tag móvel, nenhuma por digest**:
  `php:8.3-cli-alpine` e `php:8.3-fpm-alpine`, `node:24-alpine` (estágio do
  scratch-run), `eclipse-temurin:11-jdk` (estágio do Portugol — JDK 11
  porque o projeto usa Gradle 4.10, de 2018, que não roda em JDK moderno) e
  `composer:latest`. Continua sendo o buraco de reprodutibilidade que sobra
  depois da #303.

## Duas linguagens do catálogo que as imagens não instalam mais

`erl` (Erlang) e `ex` (Elixir) continuam no catálogo com `is_active => false`
(#339), e **desde 24/09/2026 nenhuma das três imagens instala a BEAM**.

**Por que estão desligadas.** A BEAM não sobe de forma confiável em `x86_64`:
`sys_signal_stack.c:101:sys_sigaltstack(): Internal error: Failed to set
alternate signal stack`. A ERTS dimensiona a pilha alternativa de sinal com o
`SIGSTKSZ` **estático** da musl (8192 em x86_64); em CPU cujo kernel reporta
`AT_MINSIGSTKSZ` maior — AVX-512, AMX — o `sigaltstack()` devolve `ENOMEM` e
a ERTS aborta. Quem decide é a CPU do host. A correção
(`sysconf(_SC_MINSIGSTKSZ)`, `erlang/otp#11376`) saiu na **OTP-29.1**
(16/09/2026) e em nenhuma release das linhas 27 e 28 — conferido pelo conteúdo
do arquivo em cada tag, inclusive nas de 22/09 (`OTP-28.5.0.7`,
`OTP-27.3.4.18`, que não a carregam).

**Por que saíram das imagens.** Até 24/09 a BEAM ficava instalada e
desligada (+85,8 MiB por imagem), para que reativar fosse trocar um `false`.
Nesse dia o Alpine publicou a OTP-27.3.4.18 no `v3.24/community`, **tirou do
repositório a versão `27.3.4.17-r0` do `erlang27`** que as três imagens fixavam, e o
`elixir` passou a resolver o `erlang28`, que conflita com o `erlang27`:

```
ERROR: unable to select packages:
  erlang28-28.5.0.7-r0: conflicts: erlang27-27.3.4.18-r0[erlang=28] ...
  erlang27-27.3.4.18-r0: breaks: world[erlang27=27.3.4.17-r0]
```

Todo build **sem cache** das três imagens parava aí, por causa de dois pacotes
que nenhuma prova usava. Reativar já exige um pino novo — uma OTP `>= 29.1`,
que `ReativarABeamExigeOtpCorrigidaTest` confere nos três Dockerfiles —, então
mantê-los instalados não comprava nada e custava o risco de pino morto.

**O que o Alpine publica, remedido no `APKINDEX` de 24/09 (`x86_64`):**

| Repositório | `erlang27` | `erlang28` | `erlang29` | `elixir` |
|---|---|---|---|---|
| `v3.24/community` | `27.3.4.18-r0` | `28.5.0.7-r0` | — | `1.19.6-r0` |
| `edge/community` | `27.3.4.17-r0` | `28.5.0.6-r0` | — | `1.20.4-r0` |
| `edge/testing` | — | — | `29.0.6-r0` | — |
| `v3.24/main`, `edge/main` | — | — | — | — |

Nenhum `>= 29.1`. **O nome do pacote não responde nada; quem responde é o
pino** — um `erlang29` existe, e é `29.0.6`, anterior à correção.

**O que continua guardado.** `CatalogoBeamDesativadaTest` reprova se `erl`/`ex`
virarem `true` antes do bloqueio cair, se uma for ligada sem a outra, e — desde
a remoção — se **uma** imagem voltar a instalar a BEAM sem as outras duas, que
é a forma da #302. `ReativarABeamExigeOtpCorrigidaTest` exige OTP `>= 29.1` nas
três no dia da reativação.

## Ajudas que nós mesmos escrevemos

Não são runtimes de terceiro, mas o julgamento não funciona sem elas. Todas
estão declaradas como `repoFile` no manifesto, então apagar uma delas é uma
falha de teste e não uma surpresa no dia da prova.

- **`resources/judge-runtime/csharp/`** — o `dotnet build` exige um *projeto*
  e a submissão é um `.cs` solto. O `compile.sh` embrulha o fonte num projeto
  console descartável a partir de `proj.csproj.template`; o `nuget.config` zera
  as fontes de pacote para o `restore` não tentar rede; o `run.sh` converte o
  limite de memória em `DOTNET_GCHeapHardLimit`, porque .NET é um dos runtimes
  que `ulimit -v` não consegue limitar.
- **`docker/judge/bin/csharp-net8` e `csharp-net10`** (#358) — invocadores de
  um SDK cada. Existem pela mesma razão do `scratch-run` e do
  `portugol-studio`: com as duas entradas de C# começando o comando em
  `dotnet`, o roteamento por capacidade não teria como distinguir uma máquina
  com só um dos dois SDKs.
- **`resources/judge-runtime/node/run.sh`** (#327) — deriva o `--stack-size`
  do V8 de três quartos do `ulimit -s` herdado, em vez de fixar um número.
- **`resources/judge-runtime/python/sitecustomize.py`** (#327) — sobe o
  `setrecursionlimit` para 200.000 dentro do processo do competidor, via
  `PYTHONPATH`, sem tocar no fonte enviado.
- **`docker/judge/bin/clojure-run` e `clojure-check`** — chamam
  `clojure.main` pelo jar. A CLI `clojure` do Alpine é o `tools.deps` e
  resolve dependência no Maven Central a cada partida; dentro do sandbox
  (`bwrap --unshare-all`) isso é `Error building classpath`.
- **`docker/judge/bin/racket-check`** — o catálogo trazia `raco make`, que
  não existe no pacote do Alpine. O que roda é uma **expansão** de módulo,
  que pega erro de sintaxe sem executar o corpo.
- **`docker/judge/bin/tcl-check`** — etapa de compilação do Tcl.
- **`resources/judge-runtime/gprolog/envolucro.pro`** (#347) — entra no
  `gplc` junto com o fonte da equipe. Sem ele, divisão por zero em GNU
  Prolog sai com **código 0** e despeja `system_error(cannot_catch_throw(…))`
  no **stdout comparado** — erro de execução virava `WA` mudo.
- **`docker/judge/portugol/VerificaPortugol.java`** (#269) e
  **`ExecutaPortugol.java`** (#301) — compilam e executam chamando a API do
  núcleo do Portugol direto, sem o Console. Nenhuma das duas é o Console.
- **`/usr/local/bin/scratch-run`, `portugol-studio` e
  `portugol-studio-check`** — invocadores de uma linha. Existem porque
  `ToolchainManifest::executableOf()` olha o **primeiro token** do comando:
  escrever `node /opt/…` anunciaria a capacidade "node" e não "scratch"; e um
  comando começando em `java` faria toda máquina com JDK dizer que sabe
  julgar Clojure, Kotlin e Portugol.

## Custo: o que a imagem instala

Medido com `apk add --simulate` (sem construir imagem), reproduzido nesta
revisão e batendo com `docs/specs/306-perfis-de-toolchain.md`:

| Perfil | Homologadas | Pacotes `apk` | Instalado |
|---|---:|---:|---:|
| imagem base `php:8.3-cli-alpine` | — | 40 | 21,3 MiB |
| `maratona` | 5 | 6 | 579,2 MiB |
| `scripting` | 12 | 13 | 710,6 MiB |
| `funcional` | 20 | 20 | 2630,7 MiB |
| `completo` (é o que o `Dockerfile.judge` instala) | 49 | 39 | **5540,3 MiB** |

**O `completo` subiu 601,9 MiB desde 20/09**, e isso é o preço do .NET 10 do
#358: `dotnet10-sdk` sozinho sobre a base mede **664,1 MiB** (contra 559,6 MiB
do `dotnet8-sdk`), e a diferença entre os dois números é o que os dois SDKs
compartilham. É o segundo item mais caro do catálogo.

A fronteira cara continua sendo o **GHC sozinho: 1698,0 MiB** medidos sobre a
mesma base — 31% de tudo. É degrau, não rampa.

**O que estes números não são:** tamanho de imagem publicada. Deixam de fora
as camadas de PHP e da aplicação, os artefatos baixados e os estágios
compilados. O tamanho medido da imagem do juiz construída hoje é
**6.686 MiB** de arquivos (`du -sm /` dentro do contêiner, `aarch64`) —
eram 6.071 MiB em 20/09, e os ~615 MiB de diferença são o .NET 10 do #358.

## O que cada linguagem AGUENTA

Desde o PR #359 isto deixou de ser conhecimento de quem auditou:
`tests/E2E/LanguageConformanceTest.php` mede **11 itens** — erro de execução,
erro de compilação, TLE, MLE, código de saída, ponto decimal, entrada grande,
recursão profunda, tempo de partida, stderr separado e caminho sem cgroup
delegado — contra **todas as 49 entradas ativas**, dentro da imagem.

O que a tabela fixa hoje — **lido do provedor de dados da suíte, não de uma
execução local** (numa máquina sem delegação de cgroup os itens de memória se
pulam; quem a executa é o job `judge-image` do CI, com `--fail-on-skipped`):
**nenhum `DEFEITO_CONHECIDO`**, e **oito
`LIMITE_DA_LINGUAGEM`**, todos no item de recursão de 10⁴ níveis: `sh`,
`tcl`, `r`, `clj`, `lisp_clisp`, `groovy`, `nim` e `portugol_studio`. Seis
vieram da #327; **`clj` e `portugol_studio` vieram desta suíte** — a tabela
os declarava `CONFORME`, e na primeira execução dentro da imagem do juiz as
duas responderam `RE`. Nenhuma foi "consertada" afrouxando o teste: o que o
juiz respondeu virou a tabela, com justificativa escrita e o manual do
organizador atualizado junto.

## O que mudou desde a revisão de 20/09/2026

| Mudança | PR | Efeito no inventário |
|---|---|---|
| Sonda de capacidade parou de procurar `./{executable}` | #357 (#354) | As 22 entradas compiladas voltaram a ser declaradas por um judgehost remoto. A regra passou a ter um dono só, `ToolchainManifest::executableOf()`; `MachineCapabilities` delega. |
| `opcache.ini` da aplicação comia o orçamento de `ulimit -v` | #360 (#356) | O `run_command` de `php` virou `php -d opcache.enable_cli=0 -d memory_limit={memory}M {source}`. PHP dentro do orçamento recebia `RE`. |
| Matriz de conformidade por linguagem | #359 | 11 itens × 49 entradas, medidos na imagem. Derrubou duas afirmações: `clj` e `portugol_studio` **não** aguentam recursão de dez mil níveis. |
| CI passou a construir a imagem da **aplicação** | #361 (#355) | Fechou o buraco que deixou a #302 e a #352 passarem. É a linha nova da tabela dos dois caminhos. |
| Declarações de licença passaram a ter de concordar | #362 (#266) | — |
| `ob_flush()` do event feed | #363 (#252) | — |
| Desativação da BEAM deixou de valer só no catálogo | #364 (#339) | O `ToolchainVersionsMatchCatalogTest` ainda exercitava o `erl` porque a tabela dele era escrita à mão e não filtrada pelo catálogo. Custo da BEAM agora guardado por teste. |
| Existe um `erlang29` no Alpine, e ele não serve | #369 (#339) | **Corrigiu uma afirmação que esta revisão também tinha errado**: o `erlang29` existe, no `edge/testing`, em `29.0.6-r0` — anterior à `29.1`. O critério de reativação deixou de ser prosa e virou comparação de versão. |
| Prometer uma versão e rodar outra reprova na suíte rápida | #367 (#305) | — |
| Perfis de toolchain | #365 (#306) | O custo de cada recorte virou número medido. Corrigiu o "~1,0 GB" da #306: o catálogo ativo manda instalar 5,5 GiB só de `apk`. |
| O portão da imagem da aplicação perdeu a exceção nomeada | #366 (#355) | O job `app-image` deixou de ter caso especial no filtro de caminhos. |
| .NET 10 LTS ao lado do 8 | #358 (#305) | **A 49ª entrada ativa**, `cs_dotnet10`. Dois invocadores novos (`csharp-net8`, `csharp-net10`), porque dois comandos começando em `dotnet` não permitiriam distinguir uma máquina com só um SDK. E `+601,9 MiB` no `completo`. |

**Dois achados da revisão anterior foram FECHADOS, e os dois pelo PR #353:**

- *"A imagem da aplicação NÃO recebeu a correção da #301, e a #302 está se
  repetindo."* Fechado: o `Dockerfile` e o `Dockerfile.dev` passaram a
  compilar `ExecutaPortugol.java` e a escrever o mesmo invocador do juiz.
  Medido hoje pelos md5 idênticos, acima. A #352 está fechada.
- *"`libstdc++` está fixado no `Dockerfile` e no `Dockerfile.dev` e NÃO no
  `Dockerfile.judge`."* Fechado: `libstdc++=15.2.0-r5` está nos três. O
  `Dockerfile.judge` teve **duas** mudanças de pino entre 20 e 21/09, e as
  duas são acréscimos: esta linha (pelo #353) e o `dotnet10-sdk=10.0.303-r0`
  (pelo #358). **Nenhuma versão já existente se moveu** — conferido por diff
  dos pinos, e é o que sustenta a coluna "Runtime medido" da tabela acima.

**O terceiro achado da revisão anterior FECHOU nesta passagem, em duas mãos:**

- **O comentário sobre o `default-jvm` dizia 21, e o medido é 25.** O #358
  corrigiu a afirmação no `Dockerfile.judge` — e deixou de pé a última cópia
  velha dela, em `ToolchainManifest::provenance()`. Esta revisão corrige essa
  cópia. Medido nas duas imagens de hoje:
  `/usr/lib/jvm/default-jvm -> java-25-openjdk`, `java -version` responde
  `25.0.4`, e `kotlinc -version` responde
  `kotlinc-jvm 2.4.20 (JRE 25.0.4+7-alpine-r0)`. O `apk` aponta o link para o
  maior JDK instalado, e ele se moveu quando o 25 entrou.

  **O que NÃO mudou, de propósito:** a exigência continua sendo
  `openjdk21-jdk`. Ela responde *"o que instalar para que `java` exista"*, e
  o 21 é o suficiente mais barato; qualquer JDK roda o jar que o `kotlinc`
  produz. Quem precisa de uma versão **específica** é o catálogo, e ele pede
  por caminho absoluto desde a #303. Trocar o mapeamento mudaria a lista de
  instalação dos perfis da #306 sem corrigir nada — o que estava errado era o
  motivo escrito, e comentário que mente é como rótulo que mente.

## Como refazer

```bash
docker build --network host -f Dockerfile.judge -t helium-judge:audit .
docker build --network host -f Dockerfile       -t helium-app:audit   .

# os números do catálogo, sem construir nada
php artisan judge:toolchain-profile                 # os perfis que existem
php artisan judge:toolchain-profile completo --apk  # a lista de instalação

# todo executável que o catálogo promete existe nas DUAS imagens?
# A lista sai do catálogo pela MESMA regra que a sonda de capacidade usa, e
# não de um rol escrito à mão -- era o rol à mão que fazia a auditoria
# conferir 40 nomes quando o catálogo pede 54.
BINS=$(php -r 'require "vendor/autoload.php";
  $a = require "bootstrap/app.php";
  $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $b = [];
  foreach (App\Models\Language::getDefaultLanguages() as $l) {
      if (! $l["is_active"]) { continue; }
      foreach (["compile_command", "run_command"] as $f) {
          $e = App\Support\Judge\ToolchainManifest::executableOf((string) ($l[$f] ?? ""));
          if ($e !== null) { $b[$e] = 1; }
      }
  }
  ksort($b);
  echo implode(" ", array_keys($b));')

for img in helium-judge:audit helium-app:audit; do
  echo -n "$img: "
  docker run --rm --network none "$img" sh -c \
    "for t in $BINS; do command -v \"\$t\" >/dev/null || echo AUSENTE:\$t; done; echo ok"
done

# presente não é igual: o conteúdo dos invocadores tem de bater entre as duas
for img in helium-judge:audit helium-app:audit; do
  docker run --rm --network none "$img" \
    md5sum /usr/local/bin/portugol-studio /usr/local/bin/portugol-studio-check \
           /usr/local/bin/scratch-run /usr/local/bin/csharp-net8 \
           /usr/local/bin/csharp-net10
done

# o custo de um perfil, sem construir imagem
php artisan judge:toolchain-profile completo --apk \
  | xargs docker run --rm --network host php:8.3-cli-alpine \
      apk add --simulate --no-cache

# a matriz de conformidade (precisa de bwrap + delegação de cgroup)
docker run --rm --privileged -v "$PWD":/var/www/html -w /var/www/html \
  helium-judge:audit \
  ./vendor/bin/phpunit --no-coverage --testsuite E2E \
    --filter LanguageConformance --fail-on-skipped
```

Numa máquina sem `bwrap` utilizável os testes de julgamento **se pulam**.
Conte os `skipped`: sem `--fail-on-skipped`, uma suíte que não mediu nada é
indistinguível de uma suíte verde.

## O que NÃO foi reconferido nesta revisão

Dito em voz alta para não virar afirmação por omissão: a suíte
`E2E --filter MultiLanguageJudging` **não** foi executada nesta passagem — o
job `judge-image` do CI a roda, e é dele que vem a confiança, não desta
medição. Os vereditos por linguagem da tabela de conformidade também vêm do
CI (#359), e não de execução local: numa máquina sem delegação de cgroup os
itens de memória se pulam.
