# Issue #251 (#196, fase 2) — o limite de tempo servido por máquina

## O que a fase 1 deixou

A fase 1 (#196/#230, com a cobertura do #273) **mede e avisa**: a tela de
calibração diz se as máquinas são comparáveis. O limite continuava digitado à
mão, igual para todas — e com judgehosts de velocidades diferentes, o caso
quando instituições parceiras emprestam o que têm (#53), a mesma solução
recebia TLE numa e AC noutra. A fase 1 punha isso na tela; a equipe já tinha
levado o veredito.

## A forma, decidida pelo mantenedor

**Teto e piso em torno do digitado**, e não substituição:

```
efetivo = clamp(digitado × fator, digitado × piso, digitado × teto)
```

O valor do problema continua sendo a referência. O #117/#130 fica preservado
em espírito: a divergência passa a ser **compensada, mas limitada** — nenhuma
máquina fica livre para inventar o próprio limite.

Piso e teto em `1.0` desligam o ajuste e devolvem a fase 1, e há teste para
isso: é a saída para quem não quer que o veredito mude.

## De onde sai o fator, e por que não de solução de referência

A issue previa que o pacote passasse a carregar `sols/` e que a máquina
medisse a referência — e registrava isso como o que **adiava** a fase 2.

Não é preciso. Desde o #273 toda prova já produz a medição, nos **envios
aceitos de verdade**: a mesma solução, medida em máquinas diferentes. É a
mesma fonte que a fase 1 usa para avisar.

Isso custa **zero** mudança de formato de pacote e **zero** execução nova — e
o `IcpcPackageImporter` já guarda as soluções de referência em
`submissions/accepted/` (com teste), então elas continuam disponíveis se um
dia a abordagem mudar.

```
razão_do_host_no_grupo = mediana(host) / mediana(medianas dos hosts)
fator                  = mediana das razões do host, em todos os grupos
```

Mediana duas vezes, pelo motivo já escrito na fase 1: uma máquina que engasgou
uma vez arrasta a média e não arrasta a mediana.

Um grupo com **uma máquina só** não entra: a razão dela contra si mesma é 1.0,
e contar isso empurraria todo fator para o neutro.

## Onde o ajuste acontece, e onde eu errei

**Uma vez, na entrega do trabalho** — `JudgeWorkQueue::workPayload()`. É o
único lugar onde ele *pode* acontecer para um judgehost remoto, que por
desenho não consulta banco.

Tentei aplicá-lo **também** em `AutoJudgeService::executeProgram()`, achando
que cobria o julgamento local. Estava errado por três motivos, e os três
foram medidos:

| # | Por quê |
|---|---|
| 1 | o julgamento local **nunca tem `judgehost_id`** — o daemon chama `claimNextLocally(null)` e a coluna fica nula, então o fator seria sempre 1.0 e a linha, código morto |
| 2 | no judgehost remoto o limite **já chegou ajustado** no payload, e ajustar de novo aplicaria o fator **duas vezes** |
| 3 | ler `$run->contest` ali é consulta preguiçosa, e o judgehost **não tem credencial de banco** (#116) |

O terceiro foi pego por `JudgeWithoutDatabaseTest`, com a mensagem exata que
ele existe para dar: *"Judging reached the database. A judgehost has no
credentials for it -- see issue #116."*

O segundo foi pego por `RemoteJudgingParityTest`, que existe para pegar
diferença entre julgar aqui e julgar lá.

Fica guardado o que importa: quem **não tem banco** recebe o valor intacto,
porque ele já veio ajustado.

## Duas guardas minhas que não guardavam

**O teto.** O teste usava duas máquinas, e com duas a base é a mediana de duas
medianas — que fica entre elas — então a razão **nunca passa de 2 por
construção**. A mutação que remove o teto passava limpa porque o teto nunca
era alcançado. Consertado com **três** máquinas, e o teste agora afirma
primeiro que o fator passou de 2.

**O grupo solo.** O teste tinha só o grupo de uma máquina, e a razão dela
contra si mesma é 1.0 com ou sem a recusa — a mesma resposta nos dois casos.
Consertado combinando um grupo comparável com um solo: se o solo contasse, a
mediana das razões saltaria de 0,5 para 0,75.

## Quem não tem medição

Fator **1.0**, o valor digitado. É a resposta segura, e é a mesma para os três
casos que a produzem: máquina recém-registrada, prova que ainda não teve envio
aceito, e julgamento local. A tela de calibração já avisa quando uma máquina
está sem medição (#273), então não fica invisível.
