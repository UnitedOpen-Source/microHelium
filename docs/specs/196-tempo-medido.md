# 196 — Tempo medido, fase 1: medir e avisar

## O buraco que o #130 deixou, de propósito

O #117/#130 construiu roteamento por capacidade entre judgehosts e tomou uma decisão explícita: hardware heterogêneo é resolvido **por política** (máquinas iguais), e divergência é **avisada, não compensada**.

A decisão é honesta. O buraco é que **não havia o aviso.**

Com máquinas de velocidades diferentes — o caso quando instituições parceiras emprestam o que têm (#53) — o mesmo limite de tempo é generoso numa e apertado noutra. A equipe recebe TLE ou AC dependendo de qual máquina pegou o run, e nada no sistema dizia isso.

## O passo zero que não estava previsto

A issue propõe medir soluções de referência, e observa que o formato BOCA que importamos **não carrega `sols/`**. Isso tornaria a fase 1 dependente de mudar o formato de pacote.

Mas havia um obstáculo anterior, e mais simples: **nenhum tempo era gravado.** O TLE vem do código de saída do `ulimit -t`, então o sistema sabia *se* um envio estourou o limite e nunca *quanto* demorou. O `time -f` já existia no julgamento, medindo só o pico de memória (`%M`) para o #86.

Então a fase 1 aqui é: estender aquela medição para trazer o tempo junto, e comparar máquinas usando os **envios aceitos que a prova já produz**. É a mesma solução, medida em máquinas diferentes — exatamente o que uma solução de referência seria, só que já existe.

Isso adia a mudança de formato de pacote para a fase 2, onde ela é de fato necessária (servir o limite calibrado, como o MOJ faz).

## O que muda

```
runs.measured_wall_ms   quanto a equipe esperou
runs.measured_cpu_ms    %U + %S, o que compara máquinas
```

**Dois números e não um.** O de parede é o que a equipe sente; o de CPU é o que compara máquinas sem ser enganado por uma que estava ocupada com outro julgamento ao mesmo tempo.

**O maior caso de teste, não a soma nem a média**: é o caso mais pesado que decide se o limite serve, e é ele que o MOJ usa para calibrar.

**A medição acompanha o veredito** por `recordVerdict()`, que é o ponto por onde passam tanto o julgamento local quanto o que chega por HTTP de um judgehost remoto (#53). Ler só o estado local deixaria todo veredito remoto sem medição — e máquina remota é exatamente o caso que esta issue existe para comparar.

## A comparação

`GET /api/frontend/judgehosts/calibration` — na mesma tela do #53 fase 4, porque é a mesma pergunta: aquela responde *"quais máquinas existem e estão vivas"*, esta responde *"elas são comparáveis?"*.

Decisões que a fazem honesta:

- **Só envios aceitos.** Um envio que estourou o limite mede o **limite**, não a máquina — incluí-lo faria toda máquina parecer igualmente lenta, no valor do time limit. Um que quebrou no meio mede até onde chegou.
- **Mediana, não média.** Uma máquina que engasgou uma vez arrasta a média e não arrasta a mediana, e o que se quer saber é como ela se comporta em geral.
- **Uma máquina sozinha não produz linha.** Devolver divergência `1.0` ali encheria a tela de ruído tranquilizador: *"nenhuma divergência"* quando o que houve foi *nenhuma medição de comparação*.
- **Por (problema, linguagem).** O mesmo par de máquinas pode divergir muito em C++ e pouco em Python, e misturar tudo numa média esconde exatamente isso.
- **O pior primeiro.** Quem abre a tela quer saber se há um problema, não ler uma lista em ordem de id.

**Nenhum veredito muda.** O limiar (`1.5×`) é o do aviso.

## Um método que ficou sem donos

`peakRssKb()` passou a não ter chamadores — `measurement()` faz a leitura, e ela **consome o arquivo**, então chamar os dois seria a segunda leitura devolvendo null. Removido em vez de deixado: o #180 já limpou nove erros de PHPStan dos quais oito eram código morto, e um método cujo docblock diz ser a forma de ler a medição é pior do que a sua ausência.

## O que a verificação encontrou

Sete mutações; duas sobreviveram, e as duas eram buracos:

1. **A ordenação "pior primeiro" não era testada.** No teste que existia, o pior caso também era o primeiro criado, então a ordem saía certa por acidente.
2. **A string de formato do `time -f` não era testada de forma alguma.** Trocá-la de volta para só `%M` não derrubava nada, porque a medição real só acontece dentro do sandbox. E esse é o pior tipo de regressão possível aqui: a medição volta **nula em silêncio**, `null` é tratado como "não foi medido", nada falha, e a tela de divergência fica vazia para sempre — dizendo implicitamente que as máquinas são comparáveis.

O segundo virou um teste que não precisa de sandbox: ele **deriva a entrada do parser a partir do formato que o comando pede**, então os dois não têm como divergir sem alguém perceber.

## Fase 2, não entregue

Limite por host com o máximo servido (a máquina mais lenta define o limite, e ninguém é punido por sorteio de fila) **muda veredito**, e depende de os pacotes carregarem soluções de referência. A fase 3 — recalibração fechada por checksum, para não recalibrar quando só o enunciado mudou — depende da 2.
