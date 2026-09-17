# 198 — Intervalos de tempo removidos da prova

## O caso

Cai a energia numa sede às 14h. Volta às 14h40. As equipes daquela sede perderam quarenta minutos, e as outras não.

Até aqui não havia o que fazer: o relógio é `start_time` + `duration`, e as opções eram deixar a sede perder o tempo ou mexer no `start_time` de todo mundo — que estraga os `contest_time` já gravados em cada run.

Não é hipótese. O [Manual do Diretor de Sede](https://maratona.sbc.org.br/manual.pdf) da Maratona tem protocolo escrito para queda de energia — *"adiciona-se 60% do tempo de parada, limitado a uma hora"*. Hoje a sede faz a conta no papel e a classificação não bate com o placar.

## Um mecanismo, duas formas

A issue descreve duas: o **intervalo removido global** (o que a ICPC especifica) e a **extensão por sede** (o que o MOJ faz, e o caso real da Maratona). São a mesma coisa com um campo a mais:

```
contest_time_adjustments.site_id   NULL = a prova inteira
                                   preenchido = só aquela sede
```

Fazer duas máquinas para a mesma pergunta era a alternativa, e seriam duas para manter em sincronia.

## O desenho, em uma frase

**`runs.contest_time` continua sendo o tempo cru de parede, e o ajuste acontece na leitura.**

Isso é o que torna a remoção reversível, que a spec exige em voz alta:

> The removal of a time interval must be reversible.

Reescrever o `contest_time` gravado seria destrutivo: desfazer exigiria lembrar o valor antigo de cada run, e um segundo intervalo removido *antes* do primeiro mudaria a conversão de todos eles. Com o ajuste na leitura, adicionar ou remover um intervalo é só recompor — e `Score::recomputeFor()` já é uma função pura dos runs que contam (#171), então a recomposição é a mesma que qualquer rejulgamento faz.

## A ordem vem do cru, o valor vem do ajustado

> If submission S_i arrived before submission S_j during a removed interval, S_i must still be considered by the CCS to have arrived strictly before S_j.

Dois envios feitos **dentro** do intervalo removido colapsam para o mesmo instante ajustado. Se a ordem viesse dali, eles empatariam — e a exigência diz o contrário. Vindo do tempo cru (e do id como desempate, que `Score::reduceCell` já usava), não empatam.

E um envio dentro do intervalo colapsa para o **começo** dele, não para o fim: o tempo que não conta é só o que já passou. Sem esse `min`, um envio feito no meio da queda receberia o desconto inteiro e apareceria *antes* de a queda ter começado.

## O fim da prova se move

A prova acaba quando a sede tiver vivido `duration` de tempo **que conta**. Um intervalo removido empurra o fim daquela sede para frente — e é por isso que "extensão por sede" e "intervalo removido" são o mesmo mecanismo, e não duas coisas.

As duas portas de submissão (API e web) perguntam `isRunningFor($site)`. **As duas têm que concordar:** uma sede com tempo devolvido que pudesse submeter pela API e não pela web teria o tempo de volta só para quem soubesse usar a API.

Sem isso, o intervalo removido corrigiria o placar e deixaria a equipe sem poder usar o tempo que lhe foi devolvido — que é metade do ponto.

## O congelamento

O corte do congelamento (#211) é comparado em tempo **ajustado**: com um intervalo removido da prova inteira, o congelamento começa quando as equipes tiverem vivido `duration - freeze` de tempo que conta. Comparar cru o faria começar cedo demais por exatamente o tanto removido.

### Limitação assumida na época, levantada pela #276

Quando isto foi escrito, `Contest::isFrozen()` e `Contest::end_time` consideravam apenas os intervalos **globais**, e uma extensão de sede só movia o prazo de submissão e o tempo efetivo dos runs daquela sede — **não** criava janela de congelamento própria.

O motivo registrado era que o placar é um só: a tela projetada na sala está congelada ou não está, e um congelamento por sede significaria o mesmo quadro escondendo coisas diferentes para pessoas diferentes.

A **#276** decidiu o contrário, e resolveu a objeção em vez de a contornar: quem tem espectador em mãos pergunta `ContestClock::isFrozenFor($contest, $siteId)`, e quem não tem — o telão, o visitante anônimo, a Contest API, a finalização — pergunta `isFrozenForAnyone()`, que é conservador: congelado enquanto **qualquer** sede ainda esconder. `Contest::isFrozen()` passou a ser exatamente essa pergunta conservadora, e é por isso que os chamadores dele não precisaram ser tocados.

O ajuste de tempo continua sendo o caminho recomendado para **devolver** tempo perdido; a duração própria da sede serve para o caso diferente de uma sede que roda um formato mais curto de propósito. Ver `docs/specs/276-override-de-tempo-por-sede.md`.

> Uma nota de procedência: ao reescrever `ContestClock::endTimeFor()` para a #276, descobriu-se que ele somava a extensão **global duas vezes** — `Contest::end_time` já a tinha somado. Isso é a **#287**, consertada antes desta.

Quem precisa do fim efetivo de uma sede pergunta a `ContestClock::endTimeFor()`.

## Auditoria

`reason` é obrigatório: um pedaço de prova que não conta muda a classificação de gente que não pediu nada, e "por quê" é a primeira pergunta de quem contesta o resultado.

Os instantes são de **parede** e não tempo de prova: quem registra sabe o horário do relógio. Converter na gravação congelaria a conversão usando o relógio de *então* — e se um segundo intervalo for removido antes deste, ela muda.

Intervalos desfeitos **continuam listados**, marcados como tal. "Reversível" não combina com sumir do registro.

## Uma terceira ocorrência do mesmo defeito de fixture

`LanguageFactory` sorteava `is_active` com `faker->boolean(70)` — numa coluna que **decide se dá para submeter**. Trinta por cento das linguagens de teste nasciam inativas, e qualquer teste que enviasse um run com ela falhava por sorteio.

Foi assim que este arquivo entrou nesta issue: um teste de extensão de tempo falhava um em cada três, e a causa não estava no relógio. É o mesmo padrão do #213 (`start_time`) e do #135 (`is_public`), agora pela terceira vez. Corrigido para determinístico, com `->inactive()` para quem quer o outro caso.
