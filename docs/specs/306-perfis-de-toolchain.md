# Issue #306 — perfis de toolchain: de "estas são as linguagens homologadas" para "esta é a lista de instalação"

**Decisão:** o recorte por prova passa a ser **computável e conferível**
antes de existir qualquer imagem — um perfil declara o que a organização
homologou, e o manifesto do #353 resolve isso na lista de instalação
correspondente. **Nenhum Dockerfile muda neste passo**, e a razão está
medida abaixo: o que faltava para decidir entre as opções (a), (b) e (c) da
issue era o número, e o número não existia.

## O que já estava pronto, e o que faltava

O #353 entregou o manifesto (`app/Support/Judge/ToolchainManifest.php`): por
linguagem, **de onde** sai cada programa que o catálogo chama. Ele já sabia
responder *"o que o catálogo inteiro exige"*. O que ele não sabia responder é
a pergunta que a #306 faz: **"e se a prova homologou apenas cinco?"**.

A issue lista três peças prontas — linguagem por prova
(`languages.contest_id`), roteamento por capacidade (`MachineCapabilities` +
`Judgehost::canJudge()`) e a linguagem guardada na submissão — e diz que
*"o que falta é a ponta de construção"*. Este passo entrega a **metade
declarativa** dessa ponta: o recorte. A metade imperativa (um `--build-arg`,
um Dockerfile parametrizado) continua de fora, e a última seção diz por quê.

## O que cada perfil custa, medido

A #306 registra `Dockerfile.judge` em **~1,0 GB** e pede a medição do que
cada perfil custaria. A medição foi feita **sem construir imagem nenhuma**:
`apk add --simulate` resolve a árvore de dependência de verdade e informa o
tamanho instalado, em segundos em vez das dezenas de minutos de um build.

```
php artisan judge:toolchain-profile <perfil> --apk \
  | xargs docker run --rm --network host php:8.3-cli-alpine \
      apk add --simulate --no-cache
```

| Perfil | Homologadas | Roda | Pacotes `apk` | Instalado | Acima da base |
|---|---:|---:|---:|---:|---:|
| (imagem base, `php:8.3-cli-alpine`) | — | — | 40 | 21,3 MiB | — |
| `maratona` | 5 | 11 | 7 | **582,6 MiB** | +561 MiB |
| `scripting` | 12 | 17 | 13 | 710,6 MiB | +689 MiB |
| `funcional` | 20 | 25 | 20 | 2630,7 MiB | +2609 MiB |
| `completo` | 51 | 51 | 40 | **5799,4 MiB** | +5778 MiB |

**Remedido em 23/09/2026**, com a mesma receita e a mesma imagem base, em
`aarch64`. As três primeiras linhas voltaram **idênticas** — `579,2`, `710,6` e
`2630,7 MiB` —, o que é o controle de que o método não mudou. (O `maratona`
mudou depois, no passo 3, por outro motivo: o `bash` virou infraestrutura, e
com ele vieram `sh` na coluna `Roda` e +3,4 MiB. Ver abaixo.) Só o `completo` se
moveu, de `4938,4` para `5799,4 MiB`, e a conta fecha nos **+861,0 MiB** de duas
linguagens que entraram no catálogo ativo depois da primeira medição: o
`dotnet10-sdk` do #358 (+602 MiB) e o `openjdk17-jdk` do #370 (+259 MiB).

Isso é exatamente o que esta linha tem de ruim: ela é derivada do catálogo
ativo — o `completo` é o único perfil definido como `homologadas = null`, isto é,
"tudo que está ligado" —, então **todo `is_active => true` novo a envelhece**, e
até aqui nada reprovava por isso. Desde 23/09/2026 as três colunas que o
repositório sabe calcular (`Homologadas`, `Roda`, `Pacotes apk`) são conferidas
por `tests/Unit/Judge/PerfisDeToolchainTest.php`, pelo mesmo motivo e no mesmo
formato do `InventarioDeRuntimeTest` sobre a spec da #300. A coluna `Instalado`
continua sendo medição de fora, e por isso o teste que guarda as outras três diz,
quando reprova, que ela também precisa ser remedida.

Três leituras, e as três mudam a conversa da issue:

1. **O desperdício é maior do que a issue estimou.** A prova típica de cinco
   linguagens custa **10,0% do que a imagem instala hoje** (era 11,7% na
   medição de 18/09; a fatia caiu porque o denominador cresceu, não porque a
   prova ficou mais cara). Não é "a maior parte da imagem": é quase toda ela.
2. **O número de ~1,0 GB da issue é anterior à #305.** O catálogo ativo hoje
   manda instalar **5,7 GiB só de `apk`**, e o `Dockerfile.judge` instala o
   catálogo ativo inteiro. Quem for decidir sobre banda no dia da prova
   (#53) precisa desse número, e não do antigo.
3. **A fronteira cara é entre `scripting` e `funcional`, e é o GHC.** Medido
   isolado nesta mesma base, `ghc` sozinho são **1698,0 MiB** — 34% de tudo.
   A #305 tinha medido +1442 MiB; a ordem de grandeza confere.

**O que estes números não são:** tamanho de imagem publicada. Eles medem o
que o `apk` instala, e deixam de fora as camadas do PHP e da aplicação, os
artefatos baixados (Kotlin, Scala, Groovy, Dart, FPC) e os estágios
compilados (SWI-Prolog, GNU Prolog, scratch-run, Portugol Studio). Nenhuma
imagem foi construída para escrever esta tabela, e a diferença entre
"instalado" e "publicado" não foi medida.

## O desenho: duas listas, e a segunda é o ponto

`App\Support\Judge\ToolchainProfile` declara, por perfil:

- **`homologadas`** — a escolha da organização;
- **`deGraca`** — o que aquela escolha **arrasta sem ter pedido**.

A segunda lista existe porque descobrir isso depois é pior. Três descobertas
que só apareceram quando o recorte virou conta:

- **quem instala `gcc` ganha `c99_gcc` junto de `c_gcc13`**, e `cpp14_gpp` e
  `cpp17_gpp` junto de `cpp_gpp13`: são o **mesmo compilador** com outro
  `-std`. Recusá-los exigiria apagar entradas do catálogo, não pacotes da
  imagem;
- **não existe imagem de juiz mais enxuta que `php` + `sed`.** A base é
  `php:8.3-*-alpine` e o `sed` é o do busybox: as duas linguagens estão lá
  em qualquer perfil, inclusive num que não homologasse nenhuma. Fingir o
  contrário seria mentir sobre o que a máquina roda;
- **quem homologa C# ou JavaScript ganha `sh`**, porque os dois chamam
  `bash {judge_runtime}/...` e `bash` é exatamente o que o `sh` precisa.

`runs() = homologadas ∪ deGraca` é a afirmação do perfil, e
`ToolchainProfileTest` exige que ela seja **exatamente** o que a lista de
instalação satisfaz. A igualdade tem dente dos dois lados:

- **de menos** — o perfil homologa Kotlin e a lista não manda instalar o
  JDK. O `canJudge()` protege do veredito errado (o host não reivindica),
  mas a submissão fica sem quem a julgue, e isso só aparece no dia;
- **de mais** — a lista já cobre uma linguagem que o perfil não admitiu. A
  imagem passa a oferecer à equipe uma linguagem fora do edital, e ninguém
  decidiu isso.

Os perfis também são **encaixados**: `maratona ⊂ scripting ⊂ funcional ⊂
completo`, em linguagens e em pacotes. Sem isso, "subir de perfil" poderia
**tirar** uma linguagem da imagem — a forma mais silenciosa de quebrar uma
prova.

## A sonda e o perfil leem o comando pela mesma função

O #357 fez `MachineCapabilities::executableOf()` delegar a
`ToolchainManifest::executableOf()`. Isso importa aqui mais do que parece:
**o que a máquina reivindica** e **o que o perfil calcula** passam a extrair o
executável de um `compile_command` pela mesma linha de código. Uma divergência
entre as duas leituras — a forma mais sutil de um perfil mentir — deixou de
ser possível por construção, e não por dois trechos parecidos mantidos em
paralelo.

O que sobra de diferença é deliberado e vai num só sentido: o perfil também
segue os scripts de `{judge_runtime}` que o comando cita, e a sonda não. O
perfil exige portanto **mais** do que a sonda confere, nunca menos.

## O limite desta análise, dito antes de alguém tropeçar nele

O manifesto descreve a **ordem de instalação**, não a árvore de dependência
do `apk`. `apk add npm` traz `nodejs` junto sem que nada no manifesto saiba
disso. Logo:

- **o que se prova**: *"esta imagem não recebeu ordem de instalar `ghc`"* — a
  forma de ausência que decide tamanho e tempo de construção, e a que a
  tabela acima mede;
- **o que não se prova**: *"este programa não existe no sistema de arquivos
  final"*. Quem responde isso roda **dentro** da imagem e já existe:
  `tests/E2E/MultiLanguageJudgingTest.php`.

É o mesmo critério do #353, pela mesma razão: ler arquivos custa
milissegundos e roda na suíte de sempre; construir custa dezenas de minutos e
só responde depois que alguém lembrou de rodar.

## O que fica para os próximos passos, e por que não é este

*(Escrito no passo 2. O passo 3, no fim deste documento, responde aos quatro
itens.)*

A #306 lista quatro decisões abertas, e **três delas bloqueiam a mudança de
build, não o recorte**:

1. **Rejulgamento de prova antiga (#303).** Reconstruir a imagem enxuta de
   uma prova de 2025 só dá o mesmo veredito se os toolchains estiverem
   fixados. `ToolchainProfileTest` já cobra o pino em **toda** lista gerada,
   então o gerador não pode reintroduzir "depende do dia da construção" — mas
   fixar o pino não é o mesmo que ter guardado qual imagem julgou o quê, e
   isso continua sem existir no código.
2. **Mudança de linguagem depois da imagem pronta.** O admin habilita Rust na
   véspera. Reconstrói? Há um host de reserva no perfil `completo`? Hoje a
   UI não impede habilitar uma linguagem que nenhum judgehost declara, e essa
   é decisão de produto, não de build.
3. **Treino e prática.** `Practice/PracticeContest.php` oferece um conjunto
   amplo por natureza; provavelmente o ambiente de treino quer `completo` e
   só a prova oficial quer o enxuto. Nada neste passo assume um ou outro.
4. **A rede de regressão por perfil.** A issue propõe rodar
   `MultiLanguageJudgingTest` **contra cada perfil**, e é o que provaria a
   promessa nova de ponta a ponta — *esta imagem julga estas, e não afirma
   julgar nenhuma outra*. Isso exige um build parametrizado (que não existe)
   e um job que o construa (o #361, já no `master`, montou a metade que constrói a
   imagem da aplicação no CI e roda a suíte multi-linguagem dentro dela). É o passo seguinte natural, e é maior que
   este.

**E a escolha entre (a) perfis pré-construídos e (b) imagem gerada por
prova?** Continua aberta de propósito, e agora com um número para decidir:
`maratona` e `scripting` diferem em **131 MiB** — pouco o bastante para que
um punhado de perfis publicados cubra quase todo o ganho sem um build por
prova. A distância que importa é a de `funcional` para baixo, e é um degrau,
não uma rampa. Quem for implementar (b) tem de mostrar que o degrau a mais
paga o custo de construir uma imagem por prova.

## Passo 3: a imagem por perfil (o que fecha a #306)

*Plano escrito antes do código, em 23/09/2026. As medições deste passo entram
na seção "Medido" abaixo quando existirem, e não antes.*

### A decisão (a) × (b)

**(a), perfis pré-construídos.** Os números acima já decidiam: `maratona` →
`scripting` custa 131 MiB, e a fronteira cara é um degrau (o GHC), não uma
rampa. Quatro perfis publicados cobrem o ganho sem um build por prova. (b)
continua possível depois, porque o mecanismo abaixo aceita qualquer perfil que
o `ToolchainProfile` saiba resolver — mas quem a quiser tem de mostrar o que ela
ganha sobre (a).

### O contrato

```
docker build -f Dockerfile.judge --build-arg JUDGE_PROFILE=maratona .
```

- `JUDGE_PROFILE` aceita os nomes de `ToolchainProfile::all()`. O padrão é
  `completo`, e com ele a imagem instala **o mesmo que antes** — nenhum
  pacote, download ou estágio a menos. (Não é byte a byte a mesma: ela passa a
  carregar o script `perfil`, os `.corta` e o `ENV JUDGE_PROFILE`, alguns
  kilobytes.)
- Nome desconhecido **reprova o build**. Um perfil com erro de digitação que
  virasse `completo` em silêncio seria exatamente o defeito que esta issue
  existe para evitar, ao contrário.
- A imagem **carrega o nome do perfil** (`ENV JUDGE_PROFILE`), porque é da
  imagem que a máquina parceira fala, e não do repositório.

### O mecanismo, e por que os pinos não saem do lugar

Os pinos continuam **na lista literal** do `RUN` de `apk` do
`Dockerfile.judge`. Essa lista é a fonte de verdade que o `DockerfileToolchain`
lê, que o `ToolchainManifestParityTest` compara com as outras duas imagens e
que o `InventarioDeRuntimeTest` conta. Mover os pinos para um arquivo por
perfil quebraria essas três guardas de uma vez para ganhar nada.

O que o perfil muda é **o que é cortado**, e não o que é fixado:

1. `docker/judge/perfil/<perfil>.corta` lista, uma por linha, as exigências
   do `completo` que aquele perfil **não** tem: `apk:<pacote>`,
   `download:<PREFIXO>`, `npm:<pacote>`. É **gerado**
   (`php artisan judge:toolchain-profile <perfil> --corta`) e **conferido por
   teste** contra o que o `ToolchainProfile` resolve — o mesmo formato "o
   arquivo é uma vista do código" da #300.
2. `docker/judge/perfil/perfil` é um script POSIX `sh` (o `bash` ainda não está
   instalado quando ele roda) com dois verbos: `apk-add`, que tira da linha de
   comando os pacotes cortados e chama `apk add`; e `inclui <exigência>`, que
   decide se um download ou pacote `npm` entra.
3. O `RUN apk add` vira `RUN .../perfil apk-add`, com a **mesma lista**; os
   blocos de download (Kotlin, Scala, Groovy, Dart, Free Pascal) e o
   `npm install -g typescript` passam a rodar dentro de
   `if .../perfil inclui ...`. Os `ARG` de versão e hash não mudam de lugar.

**Os estágios compilados** (SWI-Prolog, GNU Prolog, G-Portugol, scratch-run,
Portugol Studio) continuam entrando em todo perfil nesta versão: `COPY --from`
não é condicional sem mudar o formato que o `DockerfileToolchain` lê, e o custo
deles é medido abaixo para que a decisão de cortá-los ou não seja tomada com
número.

### O que a imagem declara: prometido ∩ encontrado

A sonda de capacidade (#117) só confere se o **executável existe**. Numa imagem
enxuta isso mente: os invocadores de `docker/judge/bin` e os estágios
compilados estão em todo perfil, então uma imagem `maratona` declararia saber
julgar Scratch — o `scratch-run` existe — sem ter Node. Ela reivindicaria
trabalho que não sabe fazer, que é a #125 de novo.

Por isso `MachineCapabilities::detect()` passa a declarar **o que o perfil da
imagem promete E a sonda encontra**. Com `completo` (o padrão, e o de toda
máquina que não diga nada) a promessa é o catálogo inteiro e **nada muda**.

### O que não pode ser cortado nunca

O `apk` que não é de linguagem nenhuma (`git`, `coreutils`, `procps`, …) não
está no manifesto e por isso não entra em corte nenhum. O perigo é o que **é**
de uma linguagem e **também** é do juiz: o `bash`. Ele é exigência do `sh`, do
C# e do JavaScript — e é também o que o `AutoJudgeService` usa para embrulhar
**todo** comando (`bash -c`). Resolvido hoje, o perfil `maratona` o cortaria, e
a imagem subiria sem julgar nada. `bash` entra em
`ToolchainManifest::shared()`, ao lado de `bubblewrap` e `libstdc++`. Efeito
colateral correto: `sh` passa a rodar em todo perfil, como `php` e `sed`.

### A rede de regressão

- **Unidade** (suíte rápida): cada `.corta` versionado bate com o que o
  `ToolchainProfile` resolve; o `completo.corta` é vazio; nenhum `.corta` corta
  exigência de `shared()`; o script `perfil` filtra a lista como o gerador
  manda (rodado de verdade, com `sh`); a sonda restringe ao prometido.
- **Dentro da imagem** (CI, um job por perfil, `--fail-on-skipped`): as
  suítes que percorrem linguagens ativas passam a percorrer **as que a imagem
  promete**, e um teste novo afirma nos dois sentidos o que a issue pede —
  *esta imagem julga estas linguagens* (tudo que o perfil promete é encontrado)
  *e não afirma julgar nenhuma outra* (a sonda não declara nada fora dele).

### As outras três decisões da issue

1. **Rejulgamento de prova antiga.** Os pinos de toda lista gerada são os do
   `Dockerfile.judge` daquele commit, e a imagem carrega o perfil; logo
   `(commit, perfil)` identifica os toolchains de uma imagem e reconstruí-la
   dá os mesmos. **Fica fora**, e virou a #392: registrar em cada
   julgamento com que versão ele foi feito (o #373 registrou a versão que o
   host declara *agora*, que um re-registro sobrescreve).
2. **Linguagem habilitada depois da imagem pronta.** O `canJudge()` já impede o
   veredito errado; com a sonda honesta deste passo, ele também impede que uma
   imagem enxuta *pareça* capaz. O que resta é produto — a submissão espera
   sem quem a julgue —, e a resposta operacional é a da própria issue: ter um
   host `completo` de reserva. Não é código deste passo.
3. **Treino e prática.** Usam `completo`, que é o padrão. Nada muda.

### Medido (23/09/2026, `aarch64`, Docker Desktop, build local)

**A imagem `maratona` construída de verdade**, com
`docker build -f Dockerfile.judge --build-arg JUDGE_PROFILE=maratona .`:

- o log do build mostra o recorte acontecendo onde devia: a linha do `apk`
  saiu sem `ghc`, `rust`, `dotnet*`, `nodejs` e companhia, e com `bash`,
  `gcc`, `g++`, `openjdk21-jdk` e `python3`; os blocos de Scala, Groovy, Dart,
  Free Pascal e do `typescript` imprimiram `perfil maratona: pula ...`; o
  Kotlin, que o perfil homologa, foi baixado e passou no hash;
- **tamanho: 2137,4 MiB**, contra **9391,1 MiB** da imagem `completo`
  construída na mesma máquina, do mesmo commit — **22,8%**. Isto é imagem
  publicada, com PHP, aplicação e estágios, e não só o `apk` da tabela do
  passo 2; os ~355 MiB do achado abaixo estão dentro dos dois números, e os
  ~86 MiB da BEAM também — ela saiu das três imagens no #400, depois desta
  medição, e como não era de linguagem ativa, nenhum perfil a cortava;
- a imagem `completo` também passou no `PerfilDaImagemTest` e no
  `ToolchainVersionsMatchCatalogTest` (52 de 52, `--fail-on-skipped`), que é o
  que o job `judge-image` do CI vai rodar;
- dentro dela, com `--fail-on-skipped`, a suíte por perfil passa **32 de 32**:
  as 11 linguagens prometidas julgam uma solução certa como AC, as 9 com
  versão no nome estão na versão que o catálogo promete, e os quatro casos do
  `PerfilDaImagemTest` passam.

**O que a sonda restrita evita, medido na mesma imagem.** Sem a restrição ao
prometido, a sonda de capacidade encontraria **22** linguagens numa imagem que
promete **11**. As 11 a mais:

```
c_clang17 cpp_clang scratch portugol_studio gportugol clj
cs_dotnet10 cs_dotnet perl prolog_swi prolog_gnu
```

Cada uma tem uma explicação diferente, e nenhuma é "a linguagem funciona": os
invocadores de C# (`csharp-net8`, `csharp-net10`) e de Clojure são scripts de
`docker/judge/bin` e existem sem .NET e sem Clojure; `scratch-run` existe sem
Node; os Prologs e o G-Portugol vêm de estágios que todo perfil copia; `perl`
chega como **dependência** do `git`; e o **Clang** — o `clang22` que o perfil
corta — volta pela porta dos fundos: o `postgresql18-dev` depende de `clang22`
e `llvm22` (medido com `apk info -r`), e o `postgresql-dev` está na lista de
infraestrutura só para compilar a extensão `pdo_pgsql`. Uma máquina `maratona` que declarasse
isso reivindicaria submissões de C# e as devolveria uma a uma — a #125 de
novo, agora em escala de parque.

**Um achado de tamanho que não é deste passo.** Esse Clang não é só um falso
positivo da sonda: são **~355 MiB** (`llvm22` 107 MiB, `llvm22-libs` 167 MiB,
`clang22-libs` 79 MiB, `clang22` 1,8 MiB, por `apk info -s`) que entram em
**toda** imagem de juiz, de qualquer perfil, para compilar uma extensão do PHP.
Os 2137,4 MiB do `maratona` incluem isso. Tirá-los é mexer na infraestrutura
da imagem (compilar o `pdo_pgsql` num estágio, ou remover o `-dev` depois de
compilar), e não no recorte por perfil — por isso fica registrado aqui e vai
para a #391, em vez de entrar calado neste PR.

> **Resolvido pela #391.** O `postgresql-dev` virou dependência virtual do
> passo que compila as extensões do PHP, instalada e removida no **mesmo**
> `RUN`, e a lista de infraestrutura passou a instalar só o `libpq` de
> runtime. Medido sobre a base `php:8.3-cli-alpine`: num perfil que corta o
> Clang, o `clang22` e o `llvm22` deixam de existir na imagem (+446 MiB → +0);
> no `completo`, o `clang22` continua, porque o catálogo o oferece, e sai só o
> `llvm22` (+446 → +306 MiB). Com isso `c_clang17` e `cpp_clang` saem da lista
> de falsos positivos acima — a porta dos fundos fechou. A guarda é
> `tests/Unit/Judge/PostgresqlDevSoNaCompilacaoTest.php`.
