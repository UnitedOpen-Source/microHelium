# 202 — Finalizar a prova e derivar a premiação

## O que faltava

Depois que a prova acaba e o placar é revelado (#189, #211), não havia estado nenhum. Não existia o momento em que a organização **afirma** que não sobrou nada pendente que pudesse mudar a classificação — e medalha e colocação não eram calculadas em lugar nenhum.

## Finalizar é uma checagem de integridade, não um botão de publicar

Os requisitos de CCS nomeiam o que impede, e é a parte interessante:

> Finalizing must not be possible if: The contest is still running…; There are un-judged submissions; There are submissions judged as Judging Error; There are unanswered clarification requests.

Cada uma dessas é **uma coisa que ainda poderia mudar a classificação**.

Os impedimentos vêm como lista, um a um, e não como um booleano. A tela precisa dizer *o que* está impedindo: sem isso o botão de finalizar é um botão que não funciona e ninguém sabe por quê — e alguém vai clicar dez vezes antes de ir procurar no log.

| código | de onde vem |
| --- | --- |
| `contest_running` | CCS |
| `unjudged_submissions` | CCS |
| `judging_errors` | CCS — o `CS` que a própria infraestrutura produz ao desistir (#45) |
| `unanswered_clarifications` | CCS |
| `still_frozen` | **nosso** — ver abaixo |
| `already_finalized` | nosso, óbvio |

### Por que `still_frozen` é nosso

A premiação deriva da classificação final. Publicar *"medalha de ouro: equipe X"* enquanto o placar está congelado revela, **pela lista de medalhas**, que X está entre os primeiros — que é exatamente o que o congelamento esconde. O #189 deu o `unfrozen_at`; exigi-lo aqui é o que fecha o vazamento pela porta dos fundos.

## O corte da mediana

> Teams that solved fewer problems than the median team are not ranked at all.

É mais duro do que parece: não é "aparecem no fim", é **não são classificadas**. Quem fica abaixo recebe menção honrosa.

E é **"fewer than"**, não "at most": quem empata com a mediana continua classificado. Essa diferença decide o destino da metade do meio da tabela, que numa regional são dezenas de equipes. Fixada por mutação — trocar `<` por `<=` derruba teste.

Equipes que não resolveram nada **contam** para a mediana: são competidoras, e tirá-las da conta subiria a mediana e desclassificaria gente que a regra não manda desclassificar. Com o #212 essas equipes passaram a aparecer no placar, então a conta aqui só está certa depois dele.

### A pergunta que a issue deixa em aberto

> Vale ser configurável por contest, com o padrão da ICPC — mas essa é decisão de quem organiza, não minha.

**Sim, configurável, com o padrão da ICPC** (`rank_median_cut`, ligado). Uma prova de treino ou seletiva interna quer classificar todo mundo, e aplicar a mediana lá deixaria metade da turma sem colocação por um motivo que não existe naquele contexto. O padrão é o da ICPC porque é para isso que este sistema serve primeiro.

## As medalhas

Configuráveis por contest (`medal_gold`, `medal_silver`, `medal_bronze`), atribuídas em ordem de colocação, cada faixa começando onde a anterior parou.

**Vêm zeradas por padrão**, e não 4/4/4 como na final mundial. Declarar medalhista é uma afirmação para fora; um sistema que sai premiando ouro numa prova de treino porque ninguém mexeu na configuração é pior do que um que exige dizer quantas.

`winner` não é medalha: existe sempre que alguém se classificou.

Equipes empatadas compartilham `rank-<n>` — duas linhas podem ser `rank-1`, e serem duas primeiras colocadas é o que aconteceu.

`first-to-solve-<problema>` vem de `scores.is_first_solver`, que já existia e que o #171 corrigiu para não sobreviver a um rejulgamento que tira o AC. Sem aquele conserto, a premiação aqui citaria uma equipe que não resolveu mais.

## Uma linha que parecia proteger e não protegia

O laço das medalhas tinha `if ($count <= 0) continue;`. Uma mutação mostrou que removê-la não derrubava teste nenhum — **porque era redundante**: com zero, `array_slice` já devolve vazio e o teste seguinte pula. Foi removida, e não coberta com mais um teste. Uma linha que parece proteger e não protege é pior do que a sua ausência: a próxima pessoa confia nela.

## Quem pode

Staff. `awards` é legível **antes** de finalizar, de propósito — a organização confere quem receberia o que antes de assinar embaixo — e por isso mesmo precisa ser de staff: a lista de medalhas é a classificação final dita de outro jeito.

## Alimenta

O #195 (Contest API), onde `/state` e `/awards` são exatamente isto.
