# #126 — Vazão de julgamento: medição antes de justificar o distribuído

`docs/specs/53-distributed-judging.md` pedia isto na fase 1, antes de qualquer
implementação:

> ADR com necessidade medida (fila/tempo de espera/throughput), **comparação com
> múltiplos workers Redis existentes**, trust model e custo operacional.
> Decidir se prosseguir.

A medição não foi feita antes. Isto é a conta sendo paga, e o resultado muda o
que se pode afirmar sobre o julgamento distribuído.

## O achado que veio primeiro

**Múltiplos workers locais não funcionavam.** `autojudge:start` chamava
`getNextPendingRun()`, que seleciona o run pendente mais antigo e devolve; só
depois `judge()` mudava o status. Dois workers perguntando nessa janela
recebiam o mesmo run e julgavam os dois.

Reproduzido de forma determinística — duas chamadas seguidas, sem julgar entre
elas, devolvem o mesmo id:

```
WORKER A got run: 1
WORKER B got run: 1
SAME RUN: YES
```

Ou seja, a alternativa barata que o spec queria comparar **não era uma
configuração válida para comparar**. Quem escalasse assim estaria desperdiçando
capacidade, não somando. Corrigido com o mesmo claim atômico que os judgehosts
já usavam (`JudgeWorkQueue::claimNextLocally()`).

## Método

- Imagem real do judge, privilegiada (bwrap + cgroup v2 ativos), 10 CPUs.
- K processos `autojudge:start` de verdade — não K "workers" dentro de um
  processo PHP, que julgariam um de cada vez e não mediriam nada.
- 24 submissões, 3 casos de teste cada, solução **CPU-bound de propósito**
  (~4,6 s por submissão). Com solução trivial a medição vira tempo de boot do
  Laravel, não julgamento.
- Polling da fila por PDO cru. A primeira versão bootava o Laravel a cada 200 ms
  e competia por CPU e pelo lock de escrita — estava medindo o próprio harness.

## Resultado

### MySQL 8.4 (o banco de produção)

| workers | wall (s) | runs/s | speedup |
|--------:|---------:|-------:|--------:|
| 1  | 116,6 | 0,205 | 1,00x |
| 2  | 69,6  | 0,344 | 1,67x |
| 4  | 43,6  | 0,550 | 2,67x |
| 8  | 32,0  | 0,750 | 3,64x |
| 12 | 31,8  | 0,755 | 3,67x |
| 16 | 33,0  | 0,726 | 3,53x |
| 24 | 26,2  | 0,916 | 4,45x |

Escala até perto da contagem de CPUs e então satura, que é exatamente o
comportamento esperado de trabalho CPU-bound. O ganho é sublinear (3,6x com 8
workers em 10 CPUs) por causa do custo de bwrap/cgroup por run.

### SQLite

| workers | wall (s) | runs/s | speedup |
|--------:|---------:|-------:|--------:|
| 1 | 110,6 | 0,217 | 1,00x |
| 2 | 114,6 | 0,209 | 0,96x |
| 4 | 116,8 | 0,205 | 0,94x |
| 8 | 114,8 | 0,209 | 0,96x |

**Zero escala.** SQLite serializa escritas, e julgar escreve várias vezes por
run. Isto é operacionalmente relevante por si só: numa instalação sobre SQLite,
subir mais workers não adiciona capacidade nenhuma.

## O que isso decide

1. **A alternativa barata funciona.** Em MySQL, uma máquina escala até o número
   de CPUs dela. Antes de pensar em máquinas de terceiros, é isso que se ajusta.
2. **O distribuído se justifica quando uma máquina acaba**, não antes. O custo
   dele — credencial por máquina, lease, fencing token (#123), heartbeat (#124),
   pacote do problema pela rede (#120) — só se paga depois desse teto.
3. **A regra "1 judgehost por 10-20 times" que eu vinha citando é do DOMjudge**,
   medida no hardware deles. Não foi verificada aqui e não deve ser repetida
   como se fosse desta base.

## O que esta medição não responde

- Vazão absoluta para um contest real. A submissão usada é sintética e
  deliberadamente pesada; submissão real de contest costuma ser bem mais rápida.
  Para dimensionar de verdade é preciso medir com o mix de linguagens e o perfil
  de submissão da competição.
- Comparação direta contra K judgehosts remotos. O gargalo aqui foi CPU local, e
  um judgehost remoto acrescenta rede e transferência de casos de teste, que esta
  medição não cobre.
- Ambiente: Docker Desktop numa VM, não um servidor dedicado. Os números relativos
  (a forma da curva) valem mais que os absolutos.
