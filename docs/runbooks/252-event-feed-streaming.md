# Homologação do streaming do event feed (issue #252)

O resolver só funciona se receber a **primeira linha** do feed enquanto a prova
acontece. Um feed que entrega tudo no fim travaria o resolver, e passava em
toda a suíte: o teste lia o corpo inteiro **depois** que a resposta fecha, e
nessa leitura as duas hipóteses eram indistinguíveis.

**Não são mais** — a seção 7 mostra como a diferença virou uma contagem
observável dentro do phpunit, sem proxy e sem deploy. O que continua exigindo
máquina de verdade ficou menor e está na seção 3.

Este documento é o que fecha essa lacuna: o que já está garantido por teste, o
que foi medido, e o que só uma máquina de verdade responde.

## 1. O que já tem guarda automatizada

`tests/Feature/Clics/EventFeedStreamingContractTest.php` fixa o que segue, e
cada guarda foi verificada por mutação (as do lado PHP estão na seção 7):

| guarda | o que a quebraria |
|---|---|
| a resposta traz `X-Accel-Buffering: no` | remover o cabeçalho do controller |
| o nginx serve PHP por `fastcgi_pass` | trocar o caminho — o cabeçalho só vale para upstream |
| `application/x-ndjson` **não** está em `gzip_types` | "vamos comprimir tudo": gzip bufferiza para comprimir |
| o bloco PHP não tem `fastcgi_buffering on` | ligar o buffer explicitamente sobrepõe o cabeçalho |

As três últimas são condições de infraestrutura que **anulariam o cabeçalho em
silêncio**. Nenhuma delas estava coberta antes.

## 2. O que foi medido, e onde

O lado PHP — o `ob_flush()`/`flush()` por linha — foi medido em 16/09/2026,
fora do Docker, com `php artisan serve` e sqlite:

```
40.000 eventos em contest_events, feed com 40.050 linhas

  linha 1        t = 0,021 s
  linha 8.000    t = 0,730 s
  linha 40.000   t = 0,778 s
  TOTAL                0,785 s
```

**A primeira linha chega a 2,7% do tempo total.** Se o corpo fosse
bufferizado até o fim, ela chegaria junto com a última. O laço de emissão
faz streaming.

Cabeçalhos observados na mesma requisição, numa resposta HTTP real:

```
HTTP/1.1 200 OK
Content-Type: application/x-ndjson
X-Accel-Buffering: no
```

**Limite desta medição, explicitamente:** ela usa o servidor embutido do PHP.
A produção é php-fpm atrás de nginx, e é justamente o nginx que o
`X-Accel-Buffering` existe para instruir. Esta medição prova o lado da
aplicação. **Não prova o caminho completo.**

## 3. O que só um deploy real responde

Rodar **antes de qualquer prova**, contra o ambiente que vai rodar a prova,
com o proxy que vai estar no meio:

```sh
scripts/clics/verifica-streaming-deploy.sh https://SEU-DEPLOY <ID-DO-CONTEST>
```

Ele mede e **decide**: sai com 0 se o feed faz streaming, 1 se provou que não
(com a lista do que investigar, na ordem em que costuma estar), e 2 se a
medição não pôde ser feita. O critério não é "a primeira linha chegou rápido"
— isso não quer dizer nada sozinho — e sim a **fração do tempo total** em que
ela chegou: um feed bufferizado entrega a primeira e a última no mesmo
instante (100%), e um que faz streaming entrega a primeira quase em zero.

A seção 8 mede a mesma coisa contra a configuração de nginx versionada aqui,
sem deploy; o que este script acrescenta é a camada que **não** está no
repositório — um CDN ou balanceador na frente.

O comando abaixo é o que o script faz por dentro, para quem quiser conferir
ou adaptar:

```sh
curl -N -s "https://SEU-DEPLOY/api/clics/contests/<ID>/event-feed" \
  | perl -MTime::HiRes=time -ne 'BEGIN{$t0=time} $n++;
      printf("linha %-6d t=%.3fs\n", $n, time-$t0) if ($n==1 || $n%1000==0);
      END{printf("TOTAL %d linhas em %.3fs\n", $n, time-$t0)}'
```

Use um contest com volume — alguns milhares de eventos —, senão o feed termina
rápido demais para a diferença aparecer.

**Passa** se a primeira linha chegar numa fração pequena do tempo total, como
na medição acima.

**Falha** se todas as linhas aparecerem juntas, no fim. Nesse caso o problema
está entre a aplicação e o cliente, não no código: procure buffer de proxy,
gzip aplicado ao `application/x-ndjson`, ou um CDN no caminho.

Confira também, na mesma resposta:

```sh
curl -sI "https://SEU-DEPLOY/api/clics/contests/<ID>/event-feed" \
  | grep -iE 'content-type|x-accel-buffering|content-encoding|content-length'
```

- `Content-Encoding: gzip` presente → o feed está sendo comprimido; corrija.
- `Content-Length` presente → a resposta foi bufferizada para medir o tamanho.

## 4. Homologação com um resolver de verdade

O teste acima prova que os bytes saem no ritmo certo. Não prova que o resolver
entende o que sai. Rode um resolver da ICPC contra o feed, do início ao fim de
uma prova de ensaio, e registre na issue #252:

- ele consegue retomar por `since_token` depois de ser interrompido;
- o congelamento aparece na hora certa e os julgamentos represados saem **no
  descongelamento**, na ordem certa;
- a cerimônia roda até o fim sem intervenção.

**Enquanto os passos 3 e 4 não forem executados contra um deploy real, o
resolver não deve ser anunciado como pronto.** Os passos 1 e 2 reduzem o risco;
não o eliminam.

## 5. O que a auditoria de conformidade respondeu sem deploy (19/09/2026)

Os passos 3 e 4 continuam válidos, mas **encolheram**: três das perguntas que
estavam esperando um deploy foram respondidas numa máquina de desenvolvimento,
com `curl` e com os JSON Schemas publicados pelo ICPC.

| pergunta | medida onde | resposta |
|---|---|---|
| o feed fica aberto enquanto a prova acontece? | `php artisan serve` + `curl --max-time 20` | **não** — 200 e EOF em 0,342 s (#328) |
| o resolver consegue retomar por `since_token`? | código do `NDJSONFeedParser`/`RESTContestSource` do ICPC Tools | **não** — o nosso `op` o derruba no parser pré-2020-03, que nunca lê `token` (#330) |
| um `since_token` inválido devolve 400? | `curl` | **não** — 200 com corpo vazio, ou 200 com a fotografia inteira (#330) |

E a linha `TOTAL 0,785 s` da medição da seção 2 é, relida, a evidência da
primeira: **um feed que não termina não tem total.** A medição estava certa
sobre o mecanismo que examinou; a pergunta que faltava estava fora do
enquadramento.

O que **continua** precisando de deploy: o `X-Accel-Buffering: no` atravessando
o nginx de produção, e a cerimônia inteira contra um resolver de verdade. Mas
não adianta marcar esses dois antes de a #328 e a #330 fecharem — hoje o
resolver reconectaria a cada 20 s rebaixando o feed inteiro.

A conferência contínua contra a spec mora em
`tests/Feature/Clics/ConformidadeClicsTest.php`, medida contra a cópia literal
dos schemas do ICPC em `tests/Fixtures/clics/json-schema/`.

## 6. O que mudou quando a #328 e a #330 fecharam (19/09/2026)

As três respostas "não" da seção 5 viraram "sim", e é isto que destrava o
passo 4:

| pergunta | o que passou a valer | guarda |
|---|---|---|
| o feed fica aberto enquanto a prova acontece? | **sim** — `EventFeedStream` mantém a conexão, consulta o log a cada `clics.event_feed.poll_ms`, manda o newline de keep-alive e só sai no `end_of_updates`, na desconexão do cliente ou no teto (`null` em produção) | `EventFeedStreamingContractTest`, que cria um evento **depois** da primeira linha já entregue e exige que a mesma resposta aberta o entregue |
| o resolver consegue retomar por `since_token`? | **sim** — a linha não traz mais `op`, então o `NDJSONFeedParser` entra no `parseNewFormat` e passa a ler `token` | `EventFeedTest::test_no_line_carries_the_op_property_that_2023_06_removed` |
| um `since_token` inválido devolve 400? | **sim** — não numérico, além do fim do log, e também `since_id` (que não suportamos) | três testes em `EventFeedTest` |

O que **continua** valendo do passo 4, e é o que mantém a #252 aberta: nenhum
resolver de verdade rodou uma cerimônia inteira contra este feed, e o
`X-Accel-Buffering: no` ainda não foi medido atravessando o nginx de produção.
A diferença é que agora essa medição é possível — antes, o cliente de
referência reconectava a cada 20 s e rebaixava o feed inteiro, então não havia
o que homologar.

Ao rodar, registre na #252 a versão do resolver e o `since_token` de onde ele
retomou.

## 7. O `ob_flush()` deixa de ser indistinguível (20/09/2026)

A issue dizia que o `flush()` por linha **não tinha como** ser testado aqui,
porque o teste lê o corpo depois que a resposta fecha. A premissa é verdadeira
e a conclusão não: ler o corpo no fim não é a única forma de medir.

O instrumento é o próprio contrato do buffer de saída do PHP. `ob_start($cb)`
**sem `chunk_size`** nunca entrega sozinho — o conteúdo fica acumulado até
alguém chamar `ob_flush()`, ou até o buffer fechar, no fim de tudo. Consumindo
a `StreamedResponse` por um buffer desses, a diferença entre as duas hipóteses
vira uma contagem:

| hipótese | entregas que a callback recebe | quando a primeira chega |
|---|---|---|
| `ob_flush()` por linha (o código de hoje) | N, uma por `echo` | antes de o laço terminar |
| sem `ob_flush()` | **1** | depois de o laço terminar |

E a diferença é observável de dentro: a callback só cria um envio novo quando
recebe o **primeiro** pedaço. Se a entrega só acontecesse no fim, esse envio
nasceria depois de o laço já ter saído e não teria como aparecer no corpo. A
afirmação que o teste faz é a ordem inteira — *emitiu, o cliente recebeu, o
mundo mudou, a mesma resposta aberta entregou a mudança*.

Isso é `test_the_first_line_reaches_the_client_before_the_last_one_is_produced`.

Por que ele não repete o teste da #328: aquele consome com `chunk_size = 1`, e
um buffer com `chunk_size = 1` entrega a cada `echo` **por conta própria**.
Medido: com `ob_flush()` e `flush()` apagados do controller, os oito testes
que existiam neste arquivo ficavam **todos verdes**. Era verde contra mecanismo
que não podia funcionar, de novo.

### Mutações, e o que cada uma faz ficar vermelho

| mutação no controller | resultado |
|---|---|
| apagar `ob_flush()` **e** `flush()` | `test_the_first_line_reaches_the_client_before_the_last_one_is_produced` — *Failed asserting that 1 is greater than 1* |
| apagar só o `ob_flush()` | o mesmo teste, a mesma falha |
| apagar só o `flush()` | `test_both_emitters_also_push_the_sapi_buffer` |
| remover `X-Accel-Buffering: no` | `test_the_feed_tells_the_proxy_not_to_buffer_it` |
| trocar a `StreamedResponse` por um corpo montado em memória | `test_the_feed_is_a_streamed_response_and_not_a_body_built_in_memory` |

### O que este teste NÃO prova, dito de propósito

- **O `flush()` de SAPI.** `ob_flush()` move a linha do buffer do PHP para a
  camada de saída do servidor; quem empurra dali para o socket é o `flush()`.
  Sob o SAPI de linha de comando que roda a suíte, essa camada não existe —
  não há experimento local capaz de ficar vermelho quando só o `flush()` some.
  O que a suíte garante sobre ele é que ele **não sumiu**
  (`test_both_emitters_also_push_the_sapi_buffer`), e isso é guarda de
  regressão, não prova de funcionamento. Quem prova é a seção 3.
- **O nginx de produção.** Continua inteiro na seção 3.


## 8. O nginx de produção deixa de ser suposição (21/09/2026)

A seção 3 pedia um deploy real para responder ao `X-Accel-Buffering`. Metade
dessa pergunta não precisava de deploy: **a configuração do nginx de produção
está versionada aqui** (`docker/nginx/nginx.conf` e `docker/nginx/default.conf`,
montadas pelo `docker-compose.yml`), e o mesmo vale para o PHP
(`docker/php/php.ini` e `docker/php/www.conf`, instalados pelo `Dockerfile`).
Subir `nginx:alpine` e `php:8.3-fpm-alpine` com **esses mesmos arquivos** e
medir com `curl -N` de fora responde pelo caminho inteiro — o que ficou de
fora é o host, não a configuração.

`scripts/clics/verifica-streaming-nginx.sh` é essa medição, executável.

### O que foi medido

20 linhas NDJSON, 250 ms entre elas (~5 s de feed), `curl -N` a partir do host.
Uma mutação por vez, tudo o mais igual:

| variante | 1ª linha | última | faz streaming? |
|---|---|---|---|
| **como está hoje** | **0,002 s** | 4,797 s | **sim** |
| sem `X-Accel-Buffering: no` | 5,058 s | 5,058 s | não |
| sem `ob_flush()` | 5,087 s | 5,087 s | não |
| sem `flush()` | 5,039 s | 5,039 s | não |
| `zlib.output_compression = On` (cliente com `Accept-Encoding: gzip`) | 4,999 s | 4,999 s | não |

"1ª linha a ~5 s" é o feed inteiro chegando de uma vez no fim — exatamente o
que trava o resolver.

Três coisas que este repositório dava por não-prováveis localmente ficaram
provadas:

- **o `X-Accel-Buffering: no` atravessando nginx**: removê-lo bufferiza tudo.
  O cabeçalho é carregado, e a configuração versionada o honra;
- **o `flush()` de SAPI**: a seção 7 dizia, com razão, que sob o SAPI de linha
  de comando não há camada de servidor para ele esvaziar, e que nenhum
  experimento local ficaria vermelho quando só o `flush()` sumisse. Sob
  **php-fpm** há: removendo só o `flush()`, o feed chega todo no fim;
- **`output_buffering = 4096`** do `docker/php/php.ini` é o que torna o
  `ob_flush()` necessário (com ele, `ob_get_level()` é 1 em produção).

### O que a medição achou, e não estava procurando

`config/clics.php` diz sobre `max_seconds`: *"`null` é o valor da spec: o feed
não termina. É o default de produção."*

`docker/php/www.conf` diz `request_terminate_timeout = 300s`.

**As duas não podem ser verdade, e quem ganha é o php-fpm.** Medido com o
mesmo `www.conf` e o teto baixado de 300 s para 15 s só para a medição caber
em 15 s — um feed de 120 linhas foi cortado na linha 64, aos 15,9 s:

```
WARNING: [pool www] child 8 ... execution timed out (16.12 sec), terminating
WARNING: [pool www] child 8 exited on signal 15 (SIGTERM) ...
```

e o `curl` saiu com **18** (`CURLE_PARTIAL_FILE`). Nenhuma linha chegou
partida — o NDJSON não corrompe —, mas a conexão cai.

Em produção, com `request_terminate_timeout = 300s`, isso é **o resolver sendo
desconectado a cada 5 minutos durante a prova inteira**. Pela #328, o
`ContestSource` do ICPC espera até 20 s antes de reconectar; numa prova de 5
horas são ~60 quedas.

**Este runbook não escolhe o número** — escolher é decisão de quem opera, e as
opções (subir o teto, dar um pool próprio ao feed, ou pôr na aplicação um teto
abaixo do do php-fpm para que a conexão feche limpa em vez de levar SIGTERM)
têm custos diferentes. O que passou a existir é a guarda: se o número do
`www.conf` mudar sem este runbook mudar junto, a suíte fica vermelha
(`EventFeedDeploymentContractTest`).

### O que **continua** precisando de deploy real

- o host de produção propriamente dito: um CDN, um balanceador ou um
  `proxy_buffering` numa camada **na frente** deste nginx não está neste
  repositório e não tem como ser medido aqui. `scripts/clics/verifica-streaming-deploy.sh`
  é o que o operador roda contra a URL real para responder isso com evidência;
- a cerimônia inteira contra um resolver de verdade (seção 4).
