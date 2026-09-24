# Issue #268 — Scratch como linguagem do auto-judge

## O que entrou

Um `.sb3` do participante é julgado como qualquer outro programa. O projeto
vira um programa que lê `stdin` e escreve `stdout` pela convenção do
[`scratch-run`](https://github.com/VNOI-Admin/scratch-run) (VNOJ), e
`--check` é a etapa de compilação — uma validação de verdade para uma
linguagem que não compila, que é o que este pipeline exige.

## A premissa da issue estava errada, e isso foi medido

A issue dizia:

> é **binário único autocontido** (empacotado com `pkg`), com builds para
> Linux x64 e arm64 — **a imagem do juiz não precisa de Node** para isso

**O binário publicado não roda nesta imagem.** Medido, em três passos:

```
$ file scratch-run
ELF 64-bit LSB executable, ARM aarch64, ... interpreter /lib/ld-linux-aarch64.so.1

$ ./scratch-run --version          # Alpine puro
sh: ./scratch-run: not found       # o carregador glibc não existe no musl

$ apk add gcompat libstdc++ libgcc && ./scratch-run --version
Error relocating ./scratch-run: fcntl64: symbol not found
```

O shim do `gcompat` não cobre binário de Node empacotado com `pkg`.

Três alternativas foram medidas:

| Caminho | Resultado |
|---|---|
| binário `pkg` + `gcompat` | **não roda** (`fcntl64`) |
| `node src/index.js` + `node_modules` | roda, mas **79 MB**, e manda avisos `No storage module present` para **stderr** |
| **`npx webpack` → `dist/index.js`** | **um arquivo de 645 KB**, roda, e **stderr vazio** |

O bundle venceu por três motivos, e o terceiro é o que importa: o webpack
troca o logger do `scratch-vm` por um noop, então nada vaza para stderr — e
o juiz lê stderr.

Node já estava na imagem (`npm install -g typescript`), então a economia
prometida pelo binário não existia de qualquer forma.

O estágio de construção é separado para os 79 MB de `node_modules` não
entrarem na imagem final, e o commit é **fixado** (tag se move, commit não).

## O defeito que o Scratch destapou, e que não é do Scratch

`AutoJudgeService` executava o programa com `2>&1` — **stderr entrava na
saída comparada**.

Em ICPC o que se compara é o stdout; stderr é diagnóstico, e é o que BOCA e
DOMjudge ignoram. Até aqui, **qualquer** programa que imprimisse uma linha de
depuração em stderr recebia **WA**, em todas as linguagens — e a equipe lia
"resposta errada" sobre uma resposta certa.

A guarda é escrita em **C**, de propósito: a linguagem mais antiga da suíte,
para deixar claro que o conserto não é sobre Scratch. A mutação que restaura
`2>&1` derruba esse teste com o veredito `WA` escrito na mensagem.

E o texto não se perde. Consertar isso destapou um segundo buraco: o laço de
casos de teste devolvia, no caminho de **sucesso**, um array novo com
`'stderr' => ''` — o diagnóstico de um run **aceito** era descartado. Não era
visível enquanto stderr ia junto com a saída comparada, porque um programa
que escrevesse nele nunca chegava a dar AC.

## O `.sb3` de teste é código, e não um blob

`Tests\Support\ScratchProject` monta o projeto a partir de blocos declarados
em PHP. Guardar um `.sb3` de terceiro no repositório tornaria o teste
impossível de revisar: ninguém sabe o que um `.sb3` faz sem abrir o editor.

O esquema do SB3 exige `blocks` em **todo** target, palco incluso — descoberto
contra o `scratch-run` real, que recusa com *"should have required property
'blocks'"*.

## Um defeito meu, que a suíte pegou por ordenação

O arquivo de teste novo mudou a ordem de execução, e o teste de throttle do
`/login` passou a falhar. A causa não era ordenação:

`ThrottleRequests::resolveRequestSignature()` devolve `sha1(domínio|ip)` —
**sem a rota**. Duas rotas com `throttle:5,1` no mesmo domínio dividem **um
balde por IP**, então o limite que a #275 pôs no `/register` estava
descontando das tentativas de `/login`.

Em laboratório de prova, onde a sede inteira sai por um NAT, **cinco cadastros
recusados trancavam o login de todos por um minuto**. Consertado com
limitador nomeado, e com teste próprio — sem ele, o balde compartilhado só
aparecia como falha de outro arquivo quando a ordem mudava.

## `is_active` = true, e por quê

A regra que este catálogo já segue: as entradas inativas estão inativas
porque *"selecting them today would silently fail"* — **inativo quer dizer
toolchain ausente**. A do Scratch está na imagem.

E ligada, ela ganha a verificação que importa: `MultiLanguageJudgingTest`
julga um `.sb3` real dentro da imagem do juiz, no job Judging do CI. Aquele
arquivo tem uma guarda que **exige** fixture para toda linguagem ativa, então
ligar sem testar não é possível.

## Fora de escopo

- **Onde o editor vive** — é a #267. Esta issue torna um `.sb3` julgável,
  venha ele de onde vier.
- **Similaridade** para Scratch: linguagem fora do `SimilarityLanguageMap`
  não é oferecida (422), e não quebra.
- **Treino Livre**: `PracticeController` exigia UTF-8 e rejeitava binário por
  construção. Resolvido na #390 (P2), em
  [`390-envio-de-arquivo-no-treino.md`](390-envio-de-arquivo-no-treino.md).
- O item 4 da issue (limite de tamanho por contest) **já foi feito**, na #284.
