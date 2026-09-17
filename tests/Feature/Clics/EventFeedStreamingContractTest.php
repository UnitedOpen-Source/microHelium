<?php

namespace Tests\Feature\Clics;

use App\Models\Contest;
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
}
