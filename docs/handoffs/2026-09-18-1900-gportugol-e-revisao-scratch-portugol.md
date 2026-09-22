# Handoff — Upstream G-Portugol, e a revisão pendente de Scratch e Portugol Studio
<!-- 2026-09-18 19:00 · microHelium @ master (0b4c004) · sessão Claude Opus 5 -->

## TL;DR

O microHelium está **limpo e em dia**: 13 PRs mesclados, 20 issues fechadas, e só
restam três issues que dependem de terceiros. O trabalho ativo migrou para o
**upstream `gportugol/gpt`**, onde abrimos **10 PRs e 11 issues**, sete delas de
segurança. **O próximo passo aqui dentro é outro:** revisar de verdade o Scratch
(#268) e o Portugol Studio (#269 parte A) — os dois estão mesclados e os testes
que os provariam **nunca rodaram**, porque são `E2E` e pulam sem o sandbox do
juiz. Ninguém confirmou que a imagem do juiz sequer constrói com eles.

## Objetivo

Duas frentes, que não competem:

1. **Upstream G-Portugol.** Ajudar o `gportugol/gpt` a ficar utilizável e seguro,
   porque é dele que depende a #296 (G-Portugol como linguagem do auto-judge).
   Restrição: somos colaboradores de fora. Abrimos issue e PR, medimos e
   propomos — **não decidimos rumo do projeto alheio**, e todo PR diz o que ficou
   de fora e por quê.
2. **microHelium.** Fechar o ciclo de Scratch e Portugol Studio: eles foram
   mesclados com teste escrito e **não executado**. Restrição do repositório, e a
   mais importante: *teste verde contra mecanismo que não pode funcionar é o modo
   de falha recorrente aqui.* Um `E2E` que pula não é um `E2E` que passa.

## Estado atual

- **microHelium:** `master` @ `0b4c004`, **working tree limpo**, nada em background.
- **Fork do gportugol:** `/private/tmp/claude-501/-Users-jobs-Dev-a-publico-microHelium/2ec49cc7-fc5b-4692-9137-a9934693cc99/scratchpad/gptfork`
  - `origin` = `matbrgz/gpt`; `master` = `f324b69` = HEAD do upstream.
  - **~123 arquivos de build não versionados** na raiz do clone (`Makefile`,
    `configure`, `*.o`, `.libs/`, os `*Walker.cpp` gerados). São inofensivos
    **desde que ninguém use `git add -A`**. Ver *Armadilhas*.
  - Worktrees: `wt29` (branch do PR #32, já empurrado) e `wt33` (branch
    `fix/33-matriz-ast-nula`, **sem commit e sem upstream**).
- **Nada rodando em background.** Todos os containers foram removidos.

## Feito (verificado)

### microHelium — 13 PRs mesclados, todos com teste

Os últimos cinco, para contexto:

| Commit | Issue |
|---|---|
| `0b4c004` | #251 limite de tempo servido por máquina |
| `10f3873` | #293 chaves estrangeiras passam a ser aplicadas |
| `3011aae` | #269-A Portugol Studio no auto-judge |
| `0db6878` | #268 Scratch no auto-judge |
| `85c2872` | #271 pacote de resultados |

### gportugol — 7 issues de segurança, cada uma validada por agente independente

| Issue | Achado | Como foi provado |
|---|---|---|
| **#27** | **Injeção de comando**: o argumento de `-o` é interpolado em `system()` (`GPT.cpp:199-212`) | 4 de 4 vetores reproduzidos (`x; touch`, `` x`touch` ``, `x$(touch)`, `x\|touch`) |
| **#33** | **38 bytes derrubam o compilador** com SIGSEGV em build normal | 10 de 66 reprodutores de fuzzing; mínimo em UTF-8 válido |
| **#29** | Leitura de não inicializada no gerador x86 (`x86.g`, regra `stm_ret`) | valgrind: `2 errors from 2 contexts` |
| **#31** | Os três construtores de `BasePortugolParser` passam `k` no lugar de `k_` | UBSan em toda execução; `k` medido = 65535 em `-O2` |
| **#26** | CI baixa o ANTLR por **HTTP puro sem hash** e o resultado entra no binário da release | cadeia confirmada ponta a ponta |
| **#25** | `configure.ac:42` zera `CXXFLAGS` e descarta as flags de quem compila | `./configure CXXFLAGS=…` → `CXXFLAGS = -O2` |
| **#28** | `nasm.exe` de **2005** vendorizado e instalado no usuário final | `NASM version 0.98.39 compiled on Jan 16 2005`, SHA-256 registrado |

**Três hipóteses minhas foram derrubadas na validação** — estão corrigidas dentro
das próprias issues: (a) `-D_FORTIFY_SOURCE` **não** se perde com o `CXXFLAGS`
zerado, porque vem por `CPPFLAGS`; (b) envenenamento de cache via PR de fork
**não se sustenta** (cache de PR não alcança o `master`); (c) o SIGSEGV da #33
**não** tem a ver com codificação — é erro de sintaxe de matriz, não acento
quebrado.

### gportugol — 10 PRs abertos

`#14` (#9 CI duplicado, **4/4 checks verdes**), `#15` (#11 manual HTML),
`#16` (#2 caminhos do instalador), `#18` (#17 guarda de UTF-8),
`#19` (#10 tarball completo), `#21` (#20 testes que não falhavam),
`#23` (#22 `GNUmakefile`), `#24` (AUTHORS), `#30` (#26 cadeia do CI),
`#32` (#29 guard no `x86.g`).

## Feito (NÃO verificado) — é aqui que mora o risco

1. **Scratch (#268) e Portugol Studio (#269-A) nunca foram exercitados.**
   Os testes existem — `tests/E2E/MultiLanguageJudgingTest.php`,
   `tests/Feature/ScratchLanguageTest.php` — mas `MultiLanguageJudgingTest`
   começa com `skipUnlessJudgeSandboxAvailable()`
   (`tests/Concerns/RequiresJudgeSandbox.php:25`), que pula sem `bwrap` usável.
   **Nesta máquina eles pulam.** Não sei se passam.
2. **A imagem do juiz nunca foi construída nesta sessão.** O `Dockerfile.judge`
   ganhou dois estágios novos — `scratch-run-builder` (node:24-alpine, webpack) e
   `portugol-studio-builder` (temurin:11-jdk, Gradle 4.10) — e ambos fixam
   commits. Que o `docker build` termine é **suposição**, não medição.
3. **Nove dos dez PRs no gportugol estão sem checks.** PR de fork espera
   aprovação de mantenedor. Só o `#14` rodou.

## Em andamento — onde parei exatamente

**O PR da família B da #33 não foi aberto.** Eu tinha despachado um subagente
para isso e **você o interrompeu** antes de ele subir o container. Estado real:

- branch local `fix/33-matriz-ast-nula` existe, **sem commit e sem push**;
- worktree em `…/1ad53830-…/scratchpad/wt33`, parado em `f324b69`;
- a correção **já está determinada e testada** pelo agente que escreveu a #33:
  um guard de uma linha em `src/modules/parser/parser.g` (`if(#tp_matriz)`), que
  leva os 66 reprodutores a 0 quebras sem alterar os exemplos.

Se for retomar, o critério que eu tinha exigido e que ainda vale: **não basta
parar de quebrar**. O arquivo de 38 bytes tem de passar a produzir mensagem de
erro de sintaxe **útil e com número de linha**. Trocar segfault por "erro
desconhecido" é correção pela metade.

## Próximos passos

1. **Construir a imagem do juiz e rodar os E2E** — é o que fecha #268 e #269-A:
   ```bash
   docker build -f Dockerfile.judge -t helium-judge:rev .
   docker run --rm helium-judge:rev sh -c 'scratch-run --version; portugol-studio-check /dev/null; echo rc=$?'
   ```
   Depois, dentro de um ambiente com `bwrap` usável:
   ```bash
   ./vendor/bin/phpunit --no-coverage --testsuite E2E --filter MultiLanguageJudging
   ```
   **Confirme que os testes RODARAM**, não que "passaram": conte os `skipped`.
   Se a contagem de testes executados for zero, o resultado é vazio.
2. **Revisar o Scratch (#268) de olho nos riscos que a própria spec registrou** —
   `docs/specs/268-scratch.md`: determinismo (`pick random`, `timer`), o limite de
   tamanho por contest, e a separação de `stderr` em `AutoJudgeService`, que
   existe porque o `scratch-run` escreve erro em stderr e o `2>&1` antigo
   contaminava o diff.
3. **Revisar o Portugol Studio (#269-A)** — `docs/specs/269-portugol-studio.md`:
   confirmar que `-no-wait` está no wrapper (`Dockerfile.judge:190`), sem o qual
   *todo programa que lê entrada vira CE e um laço trava a fila*; e que
   `portugol-studio-check` (`VerificaPortugol.java`) devolve 1 em erro de sintaxe.
4. **Decidir a #296 (G-Portugol)** — está aberta esperando decisão humana, com os
   três bloqueios já medidos. Ela é o motivo de estarmos ajudando o upstream.
5. **Retomar o PR da #33** (opcional, upstream) — ver *Em andamento*.

## Decisões e porquês

- **Ajudamos o upstream `gportugol/gpt` porque a #296 depende dele.** Não é
  desvio de escopo: sem um `gpt` que construa e não quebre, não há G-Portugol no
  auto-judge.
- **Um achado por issue, validado por agente independente antes de publicar.**
  Foi assim que três hipóteses minhas caíram antes de virar afirmação pública.
- **PR de terceiro não decide rumo alheio.** Cada PR diz o que deixou de fora:
  o #16 não inclui o job de CI (ninguém tinha Windows para verificar), o #30 não
  troca o `GH_RELEASE_TOKEN` (depende de config do repositório).
- **`GNUmakefile` e não `Makefile`** no #23: o nome está ocupado pelo autotools.
- **Scratch e Portugol Studio entraram com o editor fora do sistema.** O juiz roda
  um interpretador headless; nenhum código de editor entra no microHelium.

## Armadilhas / gotchas

- **A rede bridge do Docker desta máquina trava o `apt`.** Sintoma: `apt-get
  update` fica minutos sem progresso e o container parece vivo. Use
  `docker run --network host`. Com `sources.list` enxuto (`noble`,
  `noble-updates`, `noble-security`, `main universe`, em
  `http://ports.ubuntu.com/ubuntu-ports`) o update cai para ~5s.
- **Mais de dois containers em paralelo brigam por banda e nenhum termina.**
- **`libantlr-dev` não basta**: o `runantlr` que o `configure` procura vem do
  pacote **`antlr`**, que o `libantlr-dev` apenas **recomenda**. Com
  `--no-install-recommends` o apt passa e a falha só aparece no `configure`.
- **Nunca `git add -A` no clone do gportugol** — ele tem ~123 artefatos de build
  sem versionar. Adicione pelo caminho explícito e confira com
  `git show --stat HEAD` antes do push. Prefira `git worktree` a trabalhar no
  clone compartilhado (foi o que salvou o PR #32).
- **Fuzzing com `-fno-sanitize-recover` dá resultado vazio.** Aconteceu: 5.000
  execuções, "0 crashes", e nada tinha sido testado — o UB do construtor abortava
  tudo na largada. **Todo fuzzing precisa de controle positivo**; sem ele, "não
  achou nada" e "não testou nada" são indistinguíveis.
- **`./configure CXXFLAGS=…` no gportugol é descartado** (issue #25). Para ligar
  avisos, use `make CXXFLAGS=…`. Com o método errado saem 9 avisos; com o certo,
  **120**.
- **`phpunit` precisa de `--no-coverage`** neste repositório.
- **PR em português precisa de `Closes #N` em inglês** — o GitHub não reconhece
  "Fecha #N".

## Como validar

```bash
# microHelium: suíte principal
cd /Users/jobs/Dev/a-publico/microHelium
./vendor/bin/phpunit --no-coverage --exclude-testsuite E2E
npm run test:frontend

# quanto do que importa está sendo PULADO (a pergunta que importa aqui)
./vendor/bin/phpunit --no-coverage --testsuite E2E --filter MultiLanguageJudging --testdox

# estado do upstream
gh pr list --repo gportugol/gpt
gh issue list --repo gportugol/gpt --limit 30
```

## Em aberto / bloqueios / perguntas pro usuário

1. **#296 (G-Portugol) espera decisão sua** — os três bloqueios estão medidos na
   issue; falta escolher o caminho.
2. **#266 (AGPL-3.0)** depende de consentimento dos titulares de copyright.
3. **#252 (event feed)** depende de um deploy real para verificar.
4. **Retomar ou não o PR da #33** no upstream — você interrompeu o agente; a
   correção está pronta e não foi commitada.
5. **Os 9 PRs sem checks no gportugol** dependem de um mantenedor aprovar a
   execução dos workflows. Não há nada a fazer do nosso lado.
