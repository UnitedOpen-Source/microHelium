# 194 — Autoteste do sandbox na máquina de julgamento

## Por que não bastava o que já existe

O #49 e o #86 puseram o julgamento dentro de bubblewrap com limite de
memória por cgroup, e há teste automatizado disso
(`JudgeSandboxConfinementTest`, `JudgeSandboxIsolationTest`).

Não é a mesma pergunta. A suíte prova que **o código monta o bwrap certo**.
O que faltava é provar que **aquele kernel, com aquela configuração de
cgroup, naquele host emprestado, aguenta código hostil**.

A premissa do #53 é que a máquina de julgamento fica no rack de outra
instituição, configurada por outra pessoa, com um kernel que ninguém daqui
escolheu. O #186 tratou do que *fica* nessa máquina; isto é sobre o que ela
*deixa acontecer*.

## O comando

```
php artisan judgehost:selftest          # relatório caso a caso
php artisan judgehost:selftest --json   # para colar num checklist
```

Roda **na máquina que julga**. Sai com código diferente de zero se um único
caso falhar, e a regra é a do `mojtools`, categórica de propósito:

> Se **qualquer** caso falhar, não use aquela máquina em prova.

## O primeiro caso é o mais importante

Com `AUTOJUDGE_USE_BWRAP` desligado o comando **falha de cara**, antes de
qualquer outra coisa. A variável existe para a suíte rodar em máquina de
desenvolvedor sem bubblewrap; numa máquina que julga, ela é a falha — uma
instalação que suba assim executa código submetido sem confinamento nenhum
e não avisa ninguém.

## Os casos

| caso | o que se espera | como se mede |
| --- | --- | --- |
| o sandbox sobe | bwrap cria o namespace | um `echo` de dentro |
| fork bomb | contida pelo `ulimit -u` | o limite lido de dentro **e** o kernel recusando forks |
| alocação sem teto | morta pelo `memory.max` (ou recusada pelo `ulimit -v` onde não há cgroup) | a string alocada não chega inteira; `oom_kill`/pico no teto |
| encher disco | contido pelo `ulimit -f` | o tamanho do arquivo escrito, não só o código de saída |
| abrir socket | recusado | `/proc/net/dev` só lista `lo` |
| ler segredos | negado | `/etc/shadow`, `/etc/sudoers`, `~root/.ssh`, o `.env` da aplicação |
| exceder CPU | morto pelo `ulimit -t` | o laço não imprime o final |
| exceder tempo de parede | morto pelo backstop | um `sleep` de 120s termina em segundos |

### Detalhes que não são detalhe

**A fork bomb é limitada.** Não é a `:(){ :|:& };:` clássica: uma bomba sem
teto num host onde o limite *não* pega derruba o host — e derrubar a
máquina é a única coisa que este comando não pode fazer, porque roda antes
da prova. Tentar três vezes o limite prova o mesmo.

**Duas evidências para o limite de processos.** O `ulimit -u` lido de dentro
mostra que o prólogo chegou; a recusa de fork mostra que o número é
obedecido. Um sandbox onde o limite aparece e não é cumprido passaria pelo
primeiro sozinho.

**Rede se mede por `/proc/net/dev`, não tentando conectar.** Uma conexão
falha igual numa máquina que só está sem internet, o que não prova nada.
Dentro de um namespace de rede novo só existe `lo`, e isso é uma afirmação
sobre o isolamento.

**`/etc` está montado** (somente leitura, é a lista `sandbox_paths`), então
o que impede ler `/etc/shadow` é a permissão do arquivo e não a montagem.
Um judgehost que rode como root lê shadow de dentro do sandbox — exatamente
o tipo de coisa que só se descobre olhando a máquina.

**Tempo de parede é um caso separado do de CPU** porque `sleep` não gasta
CPU nenhuma. Se o único limite fosse o de CPU, um envio que dorme prenderia
um judgehost pelo tempo que quisesse — e um judgehost preso é uma fila que
não anda, que é o #199 inteiro.

**Cada caso passa pelo mesmo `wrapWithBwrap()` e pelo mesmo
`CgroupMemoryLimiter::confine()`** que julgam submissão de verdade. Montar
um sandbox próprio para o autoteste seria provar coisa diferente da que
acontece na prova — que é o modo de falha que este comando existe para
fechar.

## No CI

O job `Judging` roda o comando dentro da imagem do juiz, como passo próprio
além da suíte. Lá o resultado esperado é passar, e uma regressão de
confinamento aparece como falha em vez de silêncio.
