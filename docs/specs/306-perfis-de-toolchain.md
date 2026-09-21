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
| `maratona` | 5 | 10 | 6 | **579,2 MiB** | +558 MiB |
| `scripting` | 12 | 17 | 13 | 710,6 MiB | +689 MiB |
| `funcional` | 20 | 25 | 20 | 2630,7 MiB | +2609 MiB |
| `completo` | 48 | 48 | 38 | **4938,4 MiB** | +4917 MiB |

Três leituras, e as três mudam a conversa da issue:

1. **O desperdício é maior do que a issue estimou.** A prova típica de cinco
   linguagens custa **11,7% do que a imagem instala hoje**. Não é "a maior
   parte da imagem": é quase toda ela.
2. **O número de ~1,0 GB da issue é anterior à #305.** O catálogo ativo hoje
   manda instalar **4,9 GiB só de `apk`**, e o `Dockerfile.judge` instala o
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
