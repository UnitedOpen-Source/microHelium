# Issue #276 — duração e congelamento próprios por sede

## O que era

`sites.duration` e `sites.freeze_time` existiam desde a migração inicial, com
comentário e tudo: *"Site-specific duration override"*, *"Site-specific freeze
time override"*. `Site::getEffectiveDuration()` e
`Site::getEffectiveFreezeTime()` implementavam o fallback **corretamente**.

E o override estava morto nas **duas** pontas:

- **ninguém lia** — os únicos chamadores dos dois métodos no repositório
  inteiro eram `tests/Unit/SiteModelTest.php:59,60,69,70`;
- **ninguém gravava** — não havia UI, rota de API, importador nem seeder que
  pusesse valor nas colunas.

Este é o modo de falha que o repositório catalogou: **teste verde contra
mecanismo desligado**. `SiteModelTest` passava e provava que o cálculo estava
certo; o cálculo não era chamado por ninguém. Quem lesse a suíte concluiria
que o override funcionava. Quem configurasse uma sede descobriria na prova que
não — e não tinha nem onde configurar.

Mesma família do #50 (`Site.ip_address` coletado e nunca aplicado) e da #284
(`contests.max_file_size` gravado por cinco caminhos e lido por nenhum).

## A decisão

Havia duas saídas, e a issue nomeava quem decide. **Ligar as duas** foi a
escolha, e o SRS é o argumento: RF-F04-001 e RF-F04-002 são **P0**, com
critério de aceite escrito. Remover contradiria a especificação canônica do
projeto.

O risco foi aceito com nome: **o placar responde congelado ou não dependendo
de quem pergunta.**

## Como o risco foi resolvido, e não contornado

A objeção original (registrada em `docs/specs/198-intervalos-removidos.md`) era
que "o placar é um só". Ela é verdadeira para o **telão**, e falsa para a tela
que cada equipe abre no navegador.

Duas perguntas, e não uma:

| Pergunta | Quem faz | Método |
|---|---|---|
| "está congelado **para esta sede**?" | as três telas de placar, que têm espectador em mãos | `ContestClock::isFrozenFor($contest, $siteId)` |
| "**alguma** sede ainda esconde?" | telão, visitante anônimo, Contest API, finalização, operações, rejulgamento | `ContestClock::isFrozenForAnyone($contest)` |

`Contest::isFrozen()` passou a ser a **segunda**. É por isso que os quatorze
chamadores dele não precisaram ser tocados: para uma prova cujas sedes não têm
override — toda prova existente — a resposta é **idêntica** à de antes, porque
todas compartilham a janela do contest.

Conservador é a escolha certa na segunda pergunta porque o congelamento existe
para **esconder**. Entregar o quadro aberto porque uma sede já acabou
publicaria o que as outras ainda escondem, e é justamente a restrição
normativa que a fase 1 do #195 protege.

## Onde cada coisa vive

| Camada | O que faz |
|---|---|
| `Site::getEffectiveDuration()` / `getEffectiveFreezeTime()` | o fallback, que já existia e agora é chamado |
| `ContestClock::durationMinutesFor()` / `freezeMinutesFor()` | delegam ao `Site`, com o contest como fallback quando não há sede |
| `ContestClock::endTimeFor()` | soma a duração **da sede** e a extensão do #198 |
| `ContestClock::freezeStartFor()` | conta do fim **da sede** para trás; `null` quando a sede não congela |
| `ContestClock::isFrozenFor()` | as três condições do #189, por sede |
| `ContestClock::isFrozenForAnyone()` | a pergunta conservadora |
| `Backend\SiteController` | grava as colunas (o escritor que faltava) |
| `partials/site-fields.blade.php` | os dois campos, com o aviso do risco |

As sedes são memoizadas por instância do `ContestClock`, pelo mesmo motivo que
os ajustes: uma tela de placar pergunta isto uma vez por célula, e são centenas
numa regional. A relação `contest` é injetada em cada sede carregada porque o
fallback de `getEffectiveDuration()` lê `$this->contest->duration` — sem isso,
cada sede faria a sua própria consulta.

## Invariantes preservadas

- **#189** — o congelamento sobrevive ao fim da prova, e só `unfrozen_at` o
  encerra. Revelar descongela **todas** as sedes.
- **#189, a outra metade** — zero quer dizer "sem congelamento", agora por
  sede. E o caso oposto passou a ser possível: prova sem congelamento cuja
  sede define trinta minutos congela **naquela** sede.
- **#225** — `is_active` continua fora da condição. Desativar um contest não
  descongela nada.
- **#211** — "quem é da organização" continua tendo uma só definição,
  `Run::viewerSeesWithheldVerdicts()`. Staff é isento em qualquer sede.

## Bordas

| Caso | Resposta |
|---|---|
| sede sem override | segue o contest (RF-F04-002) |
| sede de **outra** prova, ou apagada | cai no contest — não existe "duração de uma sede que não existe" |
| prova sem sede nenhuma | cai no cálculo do próprio contest |
| espectador sem sede | a resposta conservadora |
| campo em branco no formulário | `null` — "segue o contest" |
| zero no congelamento | zero — "sem congelamento nesta sede", diferente de branco |

A distinção entre branco e zero não é estilo: um `(int)` cru na string vazia
daria **zero**, e zero em `duration` é uma prova de duração nula — a sede não
aceitaria envio nenhum.

## Procedência

Ao reescrever `endTimeFor()` para esta issue, descobriu-se que ele somava a
extensão **global duas vezes**: uma queda de energia nacional de 40 minutos
dava 80 minutos extras de submissão a todas as sedes, inclusive às sem ajuste
próprio. Isso é a **#287**, consertada antes desta.
