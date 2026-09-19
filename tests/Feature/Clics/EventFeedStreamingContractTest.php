<?php

namespace Tests\Feature\Clics;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Services\Clics\ContestEventRecorder;
use Helium\User;
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
 * O (2) continua sem teste automatizado, e isso e uma limitacao real: o
 * teste le o corpo inteiro depois que a resposta fecha, e nessa leitura um
 * feed que so entrega no final e indistinguivel de um que faz streaming. A
 * suite ficaria verde nas duas hipoteses, inclusive naquela em que o
 * resolver trava esperando a primeira linha.
 *
 * O que da para fixar daqui e o (1), mais as condicoes de infraestrutura que
 * o anulariam em silencio. Foi assim que este arquivo nasceu: medindo, e nao
 * supondo. A medicao esta em docs/runbooks/252-event-feed-streaming.md.
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
