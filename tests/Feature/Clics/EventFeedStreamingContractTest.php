<?php

namespace Tests\Feature\Clics;

use App\Http\Controllers\Clics\ContestApiController;
use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Services\Clics\ContestEventRecorder;
use Helium\User;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Issue #252 -- o que faz o feed ser um STREAM, e nao um arquivo entregue no
 * fim, nao tinha guarda nenhuma.
 *
 * `EventFeedTest` cobre o conteudo: retomada por since_token, congelamento,
 * formato NDJSON. Nada cobria as duas coisas das quais depende o resolver
 * receber a PRIMEIRA linha antes do fim da prova:
 *
 *  1. o cabecalho `X-Accel-Buffering: no`, sem o qual o nginx entre o
 *     consumidor e nos guarda a resposta inteira ate o fim;
 *  2. o `ob_flush()`/`flush()` por linha.
 *
 * O (2) JA TEM teste -- e a parte dele que da para provar aqui esta provada.
 * O cabecalho deste arquivo dizia o contrario, e o que ele dizia era
 * verdade enquanto a unica forma de medir fosse ler o corpo inteiro depois
 * que a resposta fecha: nessa leitura um feed que so entrega no final e
 * mesmo indistinguivel de um que faz streaming. So que essa nao e a unica
 * forma de medir. Consumindo a `StreamedResponse` por um buffer de saida
 * SEM `chunk_size` -- o que, por construcao, so entrega quando alguem chama
 * `ob_flush()` -- a diferenca entre as duas hipoteses vira uma contagem: com
 * o `ob_flush()` no lugar o corpo chega em N entregas, e sem ele chega em
 * UMA, depois que o laco ja terminou. Ver
 * `test_the_first_line_reaches_the_client_before_the_last_one_is_produced`,
 * que e o unico teste desta suite que OBSERVA essa diferenca -- os outros
 * oito ficavam verdes com o `ob_flush()` e o `flush()` apagados do
 * controller, e essa medicao esta na secao 7 do runbook.
 *
 * O que continua exigindo deploy real, e esta registrado na #252 em vez de
 * fingido aqui:
 *
 *  - o `X-Accel-Buffering: no` atravessando o nginx de PRODUCAO (e qualquer
 *    CDN ou balanceador na frente dele), que nenhum teste de unidade ve;
 *  - o `flush()` de SAPI, que so tem o que empurrar sob php-fpm: no phpunit
 *    nao existe camada de saida do servidor para ele esvaziar, entao o que
 *    esta suite consegue garantir sobre ele e que ele nao SUMIU
 *    (`test_both_emitters_also_push_the_sapi_buffer`), e nao que ele
 *    funciona;
 *  - a cerimonia inteira contra um resolver de verdade.
 *
 * Foi assim que este arquivo nasceu: medindo, e nao supondo. A medicao esta
 * em docs/runbooks/252-event-feed-streaming.md.
 *
 * ## O que a issue #328 acrescentou aqui
 *
 * A limitacao descrita acima era maior do que parecia, e o paragrafo do (2)
 * a enquadrou pequena demais: nao era so "nao sabemos se as linhas saem no
 * ritmo". Era que o feed TERMINAVA -- emitia a fotografia, emitia o backlog,
 * e fechava em 0,34 s com a prova rodando. A spec diz o contrario ("The feed
 * does not terminate under normal circumstances"), e o cliente de referencia
 * trata EOF como queda e reconecta com ate 20 s de back-off.
 *
 * Os testes de stream abaixo medem o que um `assertHeader` nunca mediria:
 * eles criam um evento DEPOIS que a primeira linha ja foi entregue ao
 * cliente, e exigem que a MESMA resposta aberta o entregue. Um feed que
 * fecha cedo nao tem como passar.
 */
class EventFeedStreamingContractTest extends TestCase
{
    private function nginxConf(string $arquivo): string
    {
        $caminho = dirname(__DIR__, 3).'/docker/nginx/'.$arquivo;

        // Controle positivo: se o arquivo sumir de lugar, as assercoes de
        // ausencia abaixo passariam sobre uma string vazia -- guarda que nao
        // guarda.
        $this->assertFileExists($caminho, "a configuracao do nginx mudou de lugar: {$arquivo}");

        $conteudo = (string) file_get_contents($caminho);

        $this->assertGreaterThan(200, strlen($conteudo), "{$arquivo} esta pequeno demais para ser a configuracao real");

        return $conteudo;
    }

    public function test_the_feed_tells_the_proxy_not_to_buffer_it(): void
    {
        $contest = Contest::factory()->create(['is_public' => true, 'is_active' => true]);

        $response = $this->get("/api/clics/contests/{$contest->id}/event-feed")->assertStatus(200);

        $this->assertSame(
            'no',
            $response->headers->get('X-Accel-Buffering'),
            'sem este cabecalho o nginx guarda a resposta inteira e "streaming" vira "tudo de uma vez no final"'
        );
    }

    /**
     * O cabecalho so significa alguma coisa se a resposta for mesmo um
     * stream.
     *
     * Um `response()->json(...)` com `X-Accel-Buffering: no` passaria no
     * teste acima e seria uma mentira completa: o corpo ja estaria montado
     * em memoria, e nao haveria nada para o proxy deixar de bufferizar. E
     * uma troca plausivel -- "o feed e pequeno, junta tudo e devolve" -- que
     * hoje nenhuma assercao impede.
     *
     * `getContent()` de uma `StreamedResponse` devolve `false` de proposito:
     * nao existe corpo para ler antes de enviar. E a diferenca, dita pelo
     * proprio Symfony, entre um feed que se produz enquanto sai e um arquivo
     * pronto.
     */
    public function test_the_feed_is_a_streamed_response_and_not_a_body_built_in_memory(): void
    {
        $contest = Contest::factory()->create(['is_public' => true, 'is_active' => true]);

        $response = $this->get("/api/clics/contests/{$contest->id}/event-feed")->assertStatus(200);

        $this->assertInstanceOf(
            StreamedResponse::class,
            $response->baseResponse,
            'o event feed deixou de ser uma StreamedResponse: o corpo passou a ser montado em memoria e entregue no fim'
        );

        $this->assertFalse(
            $response->baseResponse->getContent(),
            'a resposta tem corpo pronto antes de ser enviada, o que e o oposto de streaming'
        );
    }

    /**
     * O cabecalho acima so tem efeito porque o nginx deste projeto serve PHP
     * por `fastcgi_pass`: `X-Accel-Buffering` vale para respostas de upstream
     * (proxy, fastcgi, uwsgi, scgi). Se alguem trocar o caminho, o cabecalho
     * vira decoracao sem nada reclamar.
     */
    public function test_nginx_serves_php_through_an_upstream_that_honours_the_header(): void
    {
        $conf = $this->nginxConf('default.conf');

        $this->assertMatchesRegularExpression(
            '/fastcgi_pass\s+\S+/',
            $conf,
            'o PHP deixou de ser servido por fastcgi_pass: X-Accel-Buffering so vale para upstream'
        );
    }

    /**
     * gzip bufferiza para comprimir. Hoje o tipo do feed NAO esta em
     * `gzip_types`, e e por isso que o cabecalho basta -- mas "vamos
     * comprimir tudo" e uma edicao de uma linha, plausivel, e que anularia o
     * streaming sem quebrar teste nenhum.
     */
    public function test_the_feeds_media_type_is_not_gzipped_by_nginx(): void
    {
        $conf = $this->nginxConf('nginx.conf');

        $this->assertStringContainsString('gzip_types', $conf, 'a lista de gzip_types sumiu da configuracao');

        $this->assertStringNotContainsString(
            'application/x-ndjson',
            $conf,
            'o tipo do event feed entrou em gzip_types: gzip bufferiza para comprimir e o feed deixa de ser stream'
        );
    }

    /**
     * E o outro jeito de anular o cabecalho sem parecer que se esta fazendo
     * isso: ligar o buffer explicitamente no bloco que serve PHP.
     */
    public function test_nginx_does_not_force_buffering_on_the_php_location(): void
    {
        $conf = $this->nginxConf('default.conf');

        $this->assertDoesNotMatchRegularExpression(
            '/fastcgi_buffering\s+on\s*;/',
            $conf,
            'fastcgi_buffering ligado explicitamente sobrepoe o X-Accel-Buffering do feed'
        );
    }

    // -- o stream de verdade (#328) -----------------------------------------

    /**
     * O teste que a #328 pedia: a conexao CONTINUA ABERTA e entrega o que
     * aconteceu depois.
     *
     * Como ele mede, porque isso e o ponto: a resposta e consumida por um
     * buffer de saida com `chunk_size = 1`, entao cada `echo` do controller
     * chama a callback separadamente -- e a callback e um cliente de
     * verdade, que reage ao que recebeu. Quando ela ve a PRIMEIRA linha, ela
     * cria um envio novo (um evento que nao existia quando a resposta
     * comecou). Se o feed fechasse depois do backlog, como acontecia ate a
     * #328, esse envio nao teria como aparecer: a resposta ja teria
     * terminado.
     *
     * A prova termina pela porta normal da spec: quando a linha do envio
     * aparece, a callback finaliza a prova, e o laco emite `end_of_updates`
     * e sai. Isso tambem fixa a saida -- um laco sem saida seria um worker
     * preso para sempre.
     */
    public function test_the_feed_stays_open_and_delivers_an_event_created_after_the_first_line(): void
    {
        config([
            'clics.event_feed.poll_ms' => 5,
            'clics.event_feed.keep_alive_seconds' => 3600,
            // Teto alto de proposito: se o teste passar, tem de ser porque o
            // `end_of_updates` fechou o feed, e nao porque o tempo acabou.
            'clics.event_feed.max_seconds' => 10,
        ]);

        $contest = $this->provaComEquipe();

        $criado = null;
        $comeco = microtime(true);

        $pedacos = $this->consumir($contest, function (string $pedaco, int $indice) use ($contest, &$criado) {
            if ($criado === null) {
                // Primeiro pedaco JA entregue ao cliente: so agora a equipe
                // envia. O evento nasce com a resposta aberta.
                $criado = $this->submeter($contest);

                return;
            }

            if ($indice > 0 && str_contains($pedaco, '"submissions"')) {
                // Chegou. Fecha a prova para o laco sair pela porta da spec.
                Contest::where('id', $contest->id)->update(['finalized_at' => now()]);
            }
        });

        $duracao = microtime(true) - $comeco;
        $corpo = implode('', $pedacos);

        $this->assertNotNull($criado, 'a callback nunca recebeu um pedaco: o feed nao entregou nada');

        $marca = '"type":"submissions","id":"'.$criado->id.'"';

        $this->assertStringContainsString(
            $marca,
            $corpo,
            'o envio criado DEPOIS da primeira linha nao apareceu: a resposta fechou antes, que e exatamente o defeito da #328'
        );

        // E ele chegou depois -- nao no mesmo pedaco da fotografia.
        $indiceDoEnvio = null;

        foreach ($pedacos as $i => $pedaco) {
            if (str_contains($pedaco, '"type":"submissions","id":"'.$criado->id.'"')) {
                $indiceDoEnvio = $i;

                break;
            }
        }

        $this->assertNotNull($indiceDoEnvio);
        $this->assertGreaterThan(0, $indiceDoEnvio, 'o envio saiu no primeiro pedaco: nao foi entrega incremental');

        // Saiu pelo end_of_updates e nao pelo teto de tempo.
        $this->assertLessThan(10, $duracao, 'o feed so terminou porque o teto de tempo acabou');

        $linhas = array_values(array_filter(explode("\n", trim($corpo)), fn (string $l) => $l !== ''));
        $ultima = json_decode((string) end($linhas), true);

        $this->assertSame('state', $ultima['type'], 'a ultima linha tem de ser o end_of_updates');
        $this->assertNotNull($ultima['data']['end_of_updates']);
    }

    /**
     * A prova que faltava (#252): a primeira linha SAI antes de a ultima ser
     * produzida.
     *
     * Este e o teste que distingue as duas hipoteses que a issue chamava de
     * indistinguiveis. Ele nao le o corpo depois que a resposta fecha; ele
     * mede QUANDO cada pedaco saiu, e o instrumento e o proprio contrato do
     * buffer de saida do PHP:
     *
     *   `ob_start($cb)` SEM `chunk_size` nunca entrega sozinho. O conteudo
     *   fica acumulado ate alguem chamar `ob_flush()` -- ou ate o buffer
     *   fechar, no fim de tudo. Entao:
     *
     *     com `ob_flush()` por linha  ->  N entregas, a primeira ANTES de o
     *                                     laco terminar;
     *     sem `ob_flush()`            ->  UMA entrega, DEPOIS de o laco
     *                                     terminar.
     *
     * E essa diferenca e observavel de dentro: a callback so cria o envio
     * quando recebe o primeiro pedaco. Se a entrega so acontecer no fim, o
     * envio nasce depois de o laco ja ter saido e NAO TEM COMO aparecer no
     * corpo -- a ordem "emitiu, o cliente recebeu, o mundo mudou, o feed
     * entregou a mudanca" e o que esta sendo afirmado aqui.
     *
     * Por que ele nao repete o teste da #328 acima: aquele consome com
     * `chunk_size = 1`, e um buffer com `chunk_size = 1` entrega a cada
     * `echo` POR CONTA PROPRIA, com ou sem `ob_flush()`. Medido: com as duas
     * chamadas apagadas do controller, os oito testes deste arquivo ficavam
     * verdes. Este nao fica.
     *
     * O que ele NAO prova: que o `flush()` de SAPI empurra a linha para fora
     * do php-fpm, e que o nginx de producao nao a segura. Ver o cabecalho
     * deste arquivo e a #252.
     */
    public function test_the_first_line_reaches_the_client_before_the_last_one_is_produced(): void
    {
        config([
            'clics.event_feed.poll_ms' => 5,
            'clics.event_feed.keep_alive_seconds' => 3600,
            // Teto baixo so para o caso vermelho nao pendurar a suite: no
            // caso verde quem fecha o feed e o `end_of_updates`.
            'clics.event_feed.max_seconds' => 5,
        ]);

        $contest = $this->provaComEquipe();

        $criado = null;

        $pedacos = $this->consumirSemPicotar($contest, function (string $pedaco, int $indice) use ($contest, &$criado) {
            if ($criado === null) {
                // Primeira entrega recebida. So AGORA o mundo muda.
                $criado = $this->submeter($contest);

                return;
            }

            if ($indice > 0 && str_contains($pedaco, '"submissions"')) {
                Contest::where('id', $contest->id)->update(['finalized_at' => now()]);
            }
        });

        $this->assertNotNull($criado, 'a callback nunca recebeu nada: o feed nao entregou pedaco nenhum');

        $this->assertGreaterThan(
            1,
            count($pedacos),
            'o corpo inteiro chegou numa entrega so, num buffer que so entrega quando alguem chama ob_flush(): '
                .'o feed esta acumulando tudo e soltando no fim, que e exatamente o que trava o resolver'
        );

        $marca = '"type":"submissions","id":"'.$criado->id.'"';
        $corpo = implode('', $pedacos);

        $this->assertStringContainsString(
            $marca,
            $corpo,
            'o envio criado DEPOIS da primeira entrega nao apareceu: a primeira linha nao tinha saido antes de a ultima ser produzida'
        );

        $indiceDoEnvio = null;

        foreach ($pedacos as $i => $pedaco) {
            if (str_contains($pedaco, $marca)) {
                $indiceDoEnvio = $i;

                break;
            }
        }

        $this->assertNotNull($indiceDoEnvio);
        $this->assertGreaterThan(
            0,
            $indiceDoEnvio,
            'o envio saiu na mesma entrega da fotografia: nao houve emissao incremental'
        );
    }

    /**
     * O `flush()` de SAPI: guarda de REGRESSAO, e nao prova de funcionamento.
     *
     * Dito com todas as letras porque a confusao entre as duas coisas e o
     * modo de falha desta casa. `ob_flush()` move a linha do buffer do PHP
     * para a camada de saida do servidor -- isso o teste acima prova. Quem
     * empurra dessa camada para o socket e o `flush()`, e sob o SAPI de
     * linha de comando que roda a suite essa camada nao existe: nao ha
     * experimento local capaz de ficar vermelho quando so o `flush()` some.
     *
     * Entao o que este teste faz e o pouco que e honesto fazer: impedir que
     * ele desapareca em silencio de um dos dois emissores. Quem prova que
     * ele funciona e o `curl -N` contra o deploy, na #252 e no runbook.
     */
    public function test_both_emitters_also_push_the_sapi_buffer(): void
    {
        foreach (['emit', 'emitKeepAlive'] as $metodo) {
            $fonte = $this->fonteDoMetodo($metodo);

            $this->assertStringContainsString(
                'ob_flush()',
                $fonte,
                "{$metodo}() parou de esvaziar o buffer do PHP"
            );

            $this->assertMatchesRegularExpression(
                '/(?<!ob_)@?flush\(\)/',
                $fonte,
                "{$metodo}() parou de chamar flush(): sob php-fpm a linha fica na camada de saida do servidor, "
                    .'e o consumidor so a recebe quando a resposta fechar'
            );
        }
    }

    /**
     * O keep-alive da spec:
     *
     *   "The feed does not terminate under normal circumstances, so to
     *    ensure keep alive a newline must be sent if there has been no event
     *    within 120 seconds."
     *
     * Um NEWLINE puro, e nao um evento vazio: o consumidor de NDJSON pula
     * linha em branco, e um objeto inventado seria uma mudanca que nao
     * aconteceu. Aqui o intervalo e de 50 ms para caber num teste; o que se
     * mede e o mecanismo, nao o numero.
     */
    public function test_the_open_connection_sends_a_keep_alive_newline_when_nothing_happens(): void
    {
        config([
            'clics.event_feed.poll_ms' => 5,
            'clics.event_feed.keep_alive_seconds' => 0.05,
            'clics.event_feed.max_seconds' => 0.4,
        ]);

        $contest = $this->provaComEquipe();

        $pedacos = $this->consumir($contest);

        $this->assertContains(
            "\n",
            $pedacos,
            'nenhum newline de keep-alive em 0,4 s com o intervalo em 0,05 s: um proxy com timeout derruba esta conexao sem ninguem perceber'
        );
    }

    /**
     * O teto que a spec da ao keep-alive e 120 s. Um default acima disso
     * seria conformidade no papel e queda de conexao na prova.
     *
     * E o `max_seconds` que vai para producao e `null`: sem teto, que e o
     * "does not terminate" da spec. A suite roda com 0 (phpunit.xml) para
     * nao ficar pendurada -- e este teste existe para que esse 0 nao vire,
     * em silencio, o valor que o repositorio entrega.
     */
    public function test_the_shipped_defaults_keep_the_connection_open_and_alive(): void
    {
        $guardado = [
            $_ENV['CLICS_EVENT_FEED_MAX_SECONDS'] ?? null,
            $_SERVER['CLICS_EVENT_FEED_MAX_SECONDS'] ?? null,
            getenv('CLICS_EVENT_FEED_MAX_SECONDS'),
        ];

        unset($_ENV['CLICS_EVENT_FEED_MAX_SECONDS'], $_SERVER['CLICS_EVENT_FEED_MAX_SECONDS']);
        putenv('CLICS_EVENT_FEED_MAX_SECONDS');

        try {
            $padrao = require base_path('config/clics.php');
        } finally {
            if ($guardado[0] !== null) {
                $_ENV['CLICS_EVENT_FEED_MAX_SECONDS'] = $guardado[0];
            }

            if ($guardado[1] !== null) {
                $_SERVER['CLICS_EVENT_FEED_MAX_SECONDS'] = $guardado[1];
            }

            if ($guardado[2] !== false) {
                putenv('CLICS_EVENT_FEED_MAX_SECONDS='.$guardado[2]);
            }
        }

        $this->assertNull(
            $padrao['event_feed']['max_seconds'],
            'o default entregue passou a ter teto de duracao: o feed volta a terminar, que e a #328'
        );

        $this->assertLessThan(
            120,
            $padrao['event_feed']['keep_alive_seconds'],
            'a spec exige um newline a cada 120 s no maximo'
        );

        $this->assertGreaterThan(0, $padrao['event_feed']['poll_ms']);
    }

    /**
     * Controle negativo do controle: a suite roda com teto 0, e e isso que
     * faz os testes de CONTEUDO terminarem. Se alguem tirar o env do
     * phpunit.xml, eles ficam pendurados para sempre -- e o sintoma seria
     * "a suite travou", sem dizer por que.
     */
    public function test_the_suite_runs_with_the_loop_disabled_by_default(): void
    {
        $this->assertSame(
            0.0,
            (float) config('clics.event_feed.max_seconds'),
            'CLICS_EVENT_FEED_MAX_SECONDS=0 sumiu do phpunit.xml: os testes que leem o feed inteiro vao pendurar'
        );
    }

    // -- cenario ------------------------------------------------------------

    private function provaComEquipe(): Contest
    {
        $contest = Contest::factory()->create(['is_public' => true, 'is_active' => true]);
        $site = Site::factory()->create(['contest_id' => $contest->id]);

        Problem::factory()->create(['contest_id' => $contest->id, 'short_name' => 'A']);
        Language::factory()->create(['contest_id' => $contest->id]);
        Answer::factory()->create(['contest_id' => $contest->id, 'short_name' => 'YES', 'is_accepted' => true]);

        $this->createTestUser([
            'fullname' => 'Equipe Alfa',
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ]);

        return $contest->fresh();
    }

    private function submeter(Contest $contest): Run
    {
        $run = Run::factory()->create([
            'contest_id' => $contest->id,
            'site_id' => Site::where('contest_id', $contest->id)->value('id'),
            'user_id' => User::where('contest_id', $contest->id)->value('user_id'),
            'problem_id' => Problem::where('contest_id', $contest->id)->value('id'),
            'language_id' => Language::where('contest_id', $contest->id)->value('id'),
        ])->fresh();

        $run->update(['contest_time' => 600]);

        app(ContestEventRecorder::class)->submissionCreated($run->fresh());

        return $run->fresh();
    }

    /**
     * O corpo de um metodo do controller, como ele esta no arquivo.
     */
    private function fonteDoMetodo(string $metodo): string
    {
        $reflexao = new ReflectionMethod(ContestApiController::class, $metodo);

        $arquivo = $reflexao->getFileName();
        $this->assertIsString($arquivo);

        $linhas = file($arquivo);
        $this->assertIsArray($linhas);

        $inicio = $reflexao->getStartLine();
        $fim = $reflexao->getEndLine();
        $this->assertIsInt($inicio);
        $this->assertIsInt($fim);

        return implode('', array_slice($linhas, $inicio - 1, $fim - $inicio + 1));
    }

    /**
     * Consome a resposta aberta como um cliente, mas por um buffer que NAO
     * entrega sozinho.
     *
     * A diferenca para `consumir()` e o `chunk_size` ausente, e ela e o
     * instrumento inteiro: um buffer com `chunk_size = 1` esvazia a cada
     * `echo` por conta propria, enquanto um buffer sem `chunk_size` so
     * esvazia quando o codigo sob teste chama `ob_flush()`. Aqui, cada
     * entrega recebida e uma chamada de `ob_flush()` que aconteceu de
     * verdade.
     *
     * @param  null|callable(string, int): void  $aoReceber
     * @return list<string>
     */
    private function consumirSemPicotar(Contest $contest, ?callable $aoReceber = null): array
    {
        $resposta = $this->get("/api/clics/contests/{$contest->id}/event-feed")->assertStatus(200);

        $pedacos = [];

        ob_start(function (string $pedaco) use (&$pedacos, $aoReceber) {
            if ($pedaco === '') {
                return '';
            }

            $pedacos[] = $pedaco;

            if ($aoReceber !== null) {
                $aoReceber($pedaco, count($pedacos) - 1);
            }

            return '';
        });

        try {
            $resposta->baseResponse->sendContent();
        } finally {
            ob_end_clean();
        }

        return $pedacos;
    }

    /**
     * Consome a resposta aberta pedaco a pedaco, como um cliente.
     *
     * `chunk_size = 1` no `ob_start` e o que faz cada `echo` do controller
     * chegar aqui separadamente; sem isso o PHP juntaria tudo e a medicao
     * voltaria a ser "o corpo inteiro depois que a resposta fechou", que e a
     * limitacao que o cabecalho deste arquivo descrevia.
     *
     * @param  null|callable(string, int): void  $aoReceber
     * @return list<string>
     */
    private function consumir(Contest $contest, ?callable $aoReceber = null): array
    {
        $resposta = $this->get("/api/clics/contests/{$contest->id}/event-feed")->assertStatus(200);

        $pedacos = [];

        ob_start(function (string $pedaco) use (&$pedacos, $aoReceber) {
            if ($pedaco === '') {
                return '';
            }

            $pedacos[] = $pedaco;

            if ($aoReceber !== null) {
                $aoReceber($pedaco, count($pedacos) - 1);
            }

            return '';
        }, 1);

        try {
            $resposta->baseResponse->sendContent();
        } finally {
            ob_end_clean();
        }

        return $pedacos;
    }
}
