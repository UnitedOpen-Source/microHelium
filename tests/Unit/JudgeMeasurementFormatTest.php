<?php

namespace Tests\Unit;

use App\Services\AutoJudgeService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Issue #196 -- a string de formato do `time -f` e o parser tem que
 * concordar.
 *
 * A medicao inteira depende de duas coisas escritas em lugares diferentes:
 * o formato pedido ao `time` e a expressao regular que le o resultado. Se
 * uma mudar sem a outra, a medicao volta nula em silencio -- e "nulo" e
 * exatamente o que o codigo trata como "nao foi medido", entao nada falha.
 * A tela de divergencia simplesmente fica vazia para sempre, dizendo
 * implicitamente que as maquinas sao comparaveis.
 *
 * Uma mutacao provou a lacuna: trocar `%M %e %U %S` de volta para so `%M`
 * nao derrubava teste nenhum, porque a medicao de verdade so acontece dentro
 * do sandbox.
 *
 * Este teste nao precisa de sandbox: ele deriva a entrada do parser A PARTIR
 * do formato que o comando pede, entao os dois nao tem como divergir sem
 * alguem perceber.
 */
class JudgeMeasurementFormatTest extends TestCase
{
    /**
     * O que o `time -f` escreveria, dado o formato que o comando pediu.
     */
    private function renderAs(string $format, array $values): string
    {
        return str_replace(['%M', '%e', '%U', '%S'], $values, $format);
    }

    private function requestedFormat(): string
    {
        $service = app(AutoJudgeService::class);

        $build = new ReflectionMethod($service, 'withPeakRssMeasurement');
        $command = $build->invoke($service, 'true', '/tmp/mh-format-probe');

        // Sem /usr/bin/time a maquina devolve o comando intacto, e ai nao ha
        // formato para conferir -- o teste se pula em vez de afirmar algo
        // sobre uma medicao que nao existe nesta maquina.
        if (! str_contains($command, 'MHRSS')) {
            $this->markTestSkipped('/usr/bin/time nao esta disponivel aqui');
        }

        $this->assertSame(1, preg_match("/'(MHRSS[^']*)'/", $command, $matches));

        return $matches[1];
    }

    public function test_the_parser_reads_exactly_what_the_command_asks_for(): void
    {
        $format = $this->requestedFormat();
        $path = sys_get_temp_dir().'/mh-format-'.getmypid();

        // 2048 KB de pico, 1.5s de parede, 1.2s de CPU de usuario e 0.3s de
        // sistema.
        file_put_contents($path, $this->renderAs($format, ['2048', '1.50', '1.20', '0.30'])."\n");

        $read = new ReflectionMethod(AutoJudgeService::class, 'measurement');
        $measured = $read->invoke(app(AutoJudgeService::class), $path);

        $this->assertSame(2048, $measured['peak_rss_kb']);
        $this->assertSame(1.5, $measured['wall_seconds']);
        $this->assertSame(1.5, $measured['cpu_seconds'], 'CPU e usuario mais sistema');
    }

    /**
     * Uma medicao ausente nao pode parar uma prova. O formato antigo -- so o
     * pico -- continua legivel, e o tempo volta nulo em vez de derrubar o
     * julgamento.
     */
    public function test_an_old_format_line_still_yields_the_memory(): void
    {
        $path = sys_get_temp_dir().'/mh-format-old-'.getmypid();
        file_put_contents($path, "MHRSS 4096\n");

        $read = new ReflectionMethod(AutoJudgeService::class, 'measurement');
        $measured = $read->invoke(app(AutoJudgeService::class), $path);

        $this->assertSame(4096, $measured['peak_rss_kb']);
        $this->assertNull($measured['wall_seconds']);
    }

    public function test_a_missing_file_measures_nothing_instead_of_failing(): void
    {
        $read = new ReflectionMethod(AutoJudgeService::class, 'measurement');
        $measured = $read->invoke(app(AutoJudgeService::class), sys_get_temp_dir().'/mh-nao-existe-'.getmypid());

        $this->assertNull($measured['peak_rss_kb']);
        $this->assertNull($measured['wall_seconds']);
        $this->assertNull($measured['cpu_seconds']);
    }
}
