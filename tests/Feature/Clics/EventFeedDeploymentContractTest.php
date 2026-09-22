<?php

namespace Tests\Feature\Clics;

use Tests\TestCase;

/**
 * Issue #252 -- o que o DEPLOY faz com o feed, e que o codigo do feed nao
 * tem como saber.
 *
 * `EventFeedStreamingContractTest` prova o lado da aplicacao: a resposta e
 * uma `StreamedResponse`, traz `X-Accel-Buffering: no`, e a primeira linha
 * sai antes de a ultima ser produzida. Isso e necessario e nao e
 * suficiente -- entre aquele `echo` e o resolver existem tres arquivos
 * versionados neste repositorio que podem anular tudo em silencio:
 *
 *   docker/nginx/nginx.conf     o proxy
 *   docker/php/php.ini          o buffer do proprio PHP
 *   docker/php/www.conf         o teto de vida de uma requisicao no php-fpm
 *
 * A #252 dizia que essa parte so um deploy real responderia. Metade dela
 * nao: os tres arquivos estao no repositorio, e o que eles fazem com o feed
 * foi MEDIDO -- nginx:alpine e php:8.3-fpm-alpine subidos com estas mesmas
 * confs, `curl -N` do lado de fora, uma mutacao por vez. A medicao inteira
 * esta na secao 8 do runbook, e `scripts/clics/verifica-streaming-nginx.sh`
 * a reproduz.
 *
 * O resultado que justifica cada guarda deste arquivo:
 *
 * | variante                              | primeira linha | streaming? |
 * |---------------------------------------|----------------|------------|
 * | como esta hoje                        | 0,002 s        | SIM        |
 * | sem `X-Accel-Buffering: no`           | 5,058 s        | nao        |
 * | sem `ob_flush()`                      | 5,087 s        | nao        |
 * | sem `flush()`                         | 5,039 s        | nao        |
 * | `zlib.output_compression = On`        | 4,999 s        | nao        |
 *
 * (total do feed: ~5 s. "primeira linha a 5 s" e o feed inteiro chegando de
 * uma vez no fim, que e exatamente o que trava o resolver.)
 *
 * As tres primeiras linhas dessa tabela ja tinham guarda. As duas coisas que
 * NAO tinham, e que este arquivo passa a ter, sao o `zlib` e -- a mais grave
 * -- o teto do php-fpm, que corta um feed que a aplicacao declara infinito.
 */
class EventFeedDeploymentContractTest extends TestCase
{
    private function arquivoDeDeploy(string $relativo): string
    {
        $caminho = dirname(__DIR__, 3).'/'.$relativo;

        // Controle positivo: sem isto, um arquivo que mudou de lugar faria
        // as assercoes de ausencia passarem sobre string vazia -- guarda que
        // nao guarda.
        $this->assertFileExists($caminho, "arquivo de deploy mudou de lugar: {$relativo}");

        $conteudo = (string) file_get_contents($caminho);

        $this->assertGreaterThan(200, strlen($conteudo), "{$relativo} esta pequeno demais para ser o arquivo real");

        return $conteudo;
    }

    /**
     * O `X-Accel-Buffering: no` instrui o nginx. Nao instrui o PHP.
     *
     * Com `zlib.output_compression = On`, o PHP comprime a saida ele mesmo
     * -- e comprimir exige acumular. O `ob_flush()` continua sendo chamado,
     * o cabecalho continua na resposta, o nginx continua sem bufferizar, e
     * mesmo assim o feed inteiro chega no fim, porque nunca saiu do PHP.
     *
     * Medido: com `On` e um cliente que manda `Accept-Encoding: gzip`, as 20
     * linhas chegaram juntas em 4,999 s. Com o `Off` de hoje, a primeira
     * chegou em 0,017 s. A pegadinha e que sem `Accept-Encoding: gzip` o
     * `On` nao faz diferenca nenhuma -- entao isto passa despercebido ate um
     * cliente de verdade, que manda, aparecer.
     */
    public function test_the_shipped_php_ini_does_not_compress_the_feed_itself(): void
    {
        $ini = $this->arquivoDeDeploy('docker/php/php.ini');

        $this->assertMatchesRegularExpression(
            '/^\s*zlib\.output_compression\s*=\s*Off\s*$/mi',
            $ini,
            'zlib.output_compression saiu de Off: o PHP passa a comprimir a saida, e comprimir exige acumular -- '.
            'o feed chega inteiro no fim para qualquer cliente que aceite gzip, com o X-Accel-Buffering intacto'
        );
    }

    /**
     * A guarda mais importante deste arquivo, e a que estava faltando.
     *
     * `config/clics.php` diz, sobre `max_seconds`: "`null` e o valor da
     * spec: o feed nao termina. E o default de producao." O `www.conf` que o
     * Dockerfile instala diz `request_terminate_timeout = 300s`. As duas
     * coisas nao podem ser verdade ao mesmo tempo, e quem ganha e o php-fpm.
     *
     * Medido, com o mesmo www.conf e o teto baixado de 300 s para 15 s so
     * para a medicao caber em 15 s: um feed de 120 linhas foi cortado na
     * linha 64, aos 15,9 s. O php-fpm registrou
     *
     *     WARNING: [pool www] child 8 ... execution timed out (16.12 sec), terminating
     *     WARNING: [pool www] child 8 exited on signal 15 (SIGTERM)
     *
     * e o `curl` saiu com 18 (CURLE_PARTIAL_FILE). Nenhuma linha chegou
     * partida -- o NDJSON nao corrompe --, mas a conexao cai. Em producao,
     * com 300 s, isso e o resolver sendo desconectado a cada 5 minutos
     * durante a prova inteira; pela #328, o ContestSource do ICPC espera ate
     * 20 s antes de reconectar.
     *
     * Esta guarda nao escolhe o numero: escolher e decisao de quem opera.
     * Ela exige que o numero que o deploy pratica seja o numero que o
     * runbook registra, para que mudar um sem o outro fique vermelho em vez
     * de silencioso.
     */
    public function test_the_php_fpm_request_cap_is_the_one_the_runbook_records(): void
    {
        $pool = $this->arquivoDeDeploy('docker/php/www.conf');

        $this->assertSame(
            1,
            preg_match('/^\s*request_terminate_timeout\s*=\s*(\d+)\s*s\s*$/mi', $pool, $m),
            'request_terminate_timeout sumiu ou mudou de formato no www.conf: este e o teto real de uma conexao do feed'
        );

        $teto = (int) $m[1];

        $runbook = $this->arquivoDeDeploy('docs/runbooks/252-event-feed-streaming.md');

        $this->assertStringContainsString(
            "request_terminate_timeout = {$teto}s",
            $runbook,
            "o www.conf corta uma conexao do feed em {$teto}s e o runbook da #252 registra outro numero: ".
            'o teto de vida do feed mudou sem ninguem atualizar o que o operador vai ler'
        );
    }

    /**
     * O contrapositivo da guarda acima.
     *
     * Hoje `max_seconds` e `null`, entao esta assercao e vacuamente
     * verdadeira -- de proposito. Ela existe para o dia em que alguem puser
     * um teto na aplicacao: se esse teto for MAIOR que o do php-fpm, ele e
     * ficcao, porque o php-fpm corta antes, e a diferenca entre "a aplicacao
     * fechou a conexao no fim de um ciclo" e "o worker levou SIGTERM no meio
     * de um" e a diferenca entre um EOF limpo e um `curl` saindo com 18.
     */
    public function test_an_application_side_cap_must_stay_under_the_php_fpm_cap(): void
    {
        $maxSeconds = config('clics.event_feed.max_seconds');

        if ($maxSeconds === null) {
            $this->assertTrue(true, 'sem teto na aplicacao: quem corta e o php-fpm, e o teste acima cobre isso');

            return;
        }

        $pool = $this->arquivoDeDeploy('docker/php/www.conf');
        preg_match('/^\s*request_terminate_timeout\s*=\s*(\d+)\s*s\s*$/mi', $pool, $m);
        $tetoFpm = (int) ($m[1] ?? 0);

        $this->assertGreaterThan(
            (float) $maxSeconds,
            (float) $tetoFpm,
            'clics.event_feed.max_seconds ficou acima do request_terminate_timeout do php-fpm: '.
            'o teto da aplicacao nunca sera alcancado, e o feed termina em SIGTERM em vez de terminar sozinho'
        );
    }

    /**
     * O nginx desiste de esperar o php-fpm depois de `fastcgi_read_timeout`
     * sem receber byte nenhum. O feed passa longos trechos sem nenhum
     * evento, e o que o mantem vivo e o newline de keep-alive.
     *
     * Se o intervalo do keep-alive passar do `fastcgi_read_timeout`, o nginx
     * derruba a conexao no meio de uma prova parada -- e o sintoma e "o feed
     * cai quando nao acontece nada", que e o pior dos sintomas para
     * diagnosticar. Hoje sao 30 s de keep-alive contra 300 s de espera.
     */
    public function test_nginx_waits_for_the_feed_longer_than_the_keep_alive_interval(): void
    {
        $conf = $this->arquivoDeDeploy('docker/nginx/default.conf');

        $this->assertSame(
            1,
            preg_match('/^\s*fastcgi_read_timeout\s+(\d+)\s*;\s*$/mi', $conf, $m),
            'fastcgi_read_timeout sumiu do bloco PHP: e ele que diz quanto o nginx espera entre dois bytes do feed'
        );

        $esperaNginx = (int) $m[1];
        $keepAlive = (float) config('clics.event_feed.keep_alive_seconds');

        $this->assertGreaterThan(
            0.0,
            $keepAlive,
            'clics.event_feed.keep_alive_seconds nao esta configurado: sem keep-alive o nginx derruba o feed parado'
        );

        $this->assertGreaterThan(
            $keepAlive,
            (float) $esperaNginx,
            "o nginx espera {$esperaNginx}s entre bytes e o feed so manda keep-alive a cada {$keepAlive}s: ".
            'numa prova sem eventos o nginx derruba a conexao antes de o keep-alive chegar'
        );
    }

    /**
     * Guardas estaticas sobre um arquivo que o deploy nao usa sao teatro.
     *
     * `EventFeedStreamingContractTest` afirma coisas sobre
     * `docker/nginx/*.conf` -- que o PHP e servido por `fastcgi_pass`, que o
     * `application/x-ndjson` nao esta em `gzip_types`, que nao ha
     * `fastcgi_buffering on`. Tudo isso so vale porque e ESTE par de
     * arquivos que o container do nginx monta. Se o compose passar a apontar
     * para outro lugar, aquelas guardas continuam verdes e deixam de
     * significar qualquer coisa.
     */
    public function test_the_compose_file_mounts_the_very_nginx_conf_the_suite_asserts_about(): void
    {
        $compose = $this->arquivoDeDeploy('docker-compose.yml');

        foreach (['docker/nginx/nginx.conf:/etc/nginx/nginx.conf', 'docker/nginx/default.conf:/etc/nginx/conf.d/default.conf'] as $montagem) {
            $this->assertStringContainsString(
                $montagem,
                $compose,
                "o docker-compose.yml deixou de montar {$montagem}: as guardas de nginx da suite passam a descrever ".
                'um arquivo que o container nao le'
            );
        }
    }

    /**
     * O mesmo argumento, para os dois arquivos de PHP que as guardas acima
     * examinam: quem os instala na imagem e o Dockerfile.
     */
    public function test_the_dockerfile_ships_the_very_php_config_the_suite_asserts_about(): void
    {
        $dockerfile = $this->arquivoDeDeploy('Dockerfile');

        foreach (['docker/php/php.ini', 'docker/php/www.conf'] as $origem) {
            $this->assertMatchesRegularExpression(
                '/^\s*COPY\s+'.preg_quote($origem, '/').'\s+\S+/mi',
                $dockerfile,
                "o Dockerfile deixou de instalar {$origem}: as guardas que leem esse arquivo passam a descrever ".
                'uma configuracao que a imagem de producao nao tem'
            );
        }
    }
}
