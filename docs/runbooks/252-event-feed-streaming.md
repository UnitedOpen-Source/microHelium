# Homologação do streaming do event feed (issue #252)

O resolver só funciona se receber a **primeira linha** do feed enquanto a prova
acontece. Um feed que entrega tudo no fim passa em toda a suíte e trava o
resolver, porque o teste lê o corpo inteiro **depois** que a resposta fecha —
e nessa leitura as duas hipóteses são indistinguíveis.

Este documento é o que fecha essa lacuna: o que já está garantido por teste, o
que foi medido, e o que só uma máquina de verdade responde.

## 1. O que já tem guarda automatizada

`tests/Feature/Clics/EventFeedStreamingContractTest.php` fixa quatro coisas, e
cada uma foi verificada por mutação:

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
