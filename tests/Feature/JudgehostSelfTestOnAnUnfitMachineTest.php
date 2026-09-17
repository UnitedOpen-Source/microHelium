<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Issue #253 -- o comando tem que DIAGNOSTICAR a maquina emprestada, nao
 * morrer nela.
 *
 * `judgehost:selftest` so tinha rodado dentro da imagem Docker do juiz, que
 * e a unica maquina cuja configuracao o projeto controla -- ou seja, a
 * maquina que ele nao precisa checar. Rodando num laptop de verdade, fora do
 * Docker, o primeiro caso lancava SandboxUnavailableException e o comando
 * terminava com um stack trace do Laravel.
 *
 * Com `--json` isso e pior que feio. O `handle()` ja diz, no caso do sandbox
 * desligado, por que a recusa tem que sair como JSON: "um script que
 * recebesse texto solto aqui nao conseguiria distinguir 'esta maquina nao
 * confina' de 'o comando quebrou', e as duas coisas pedem reacoes
 * diferentes". Era exatamente o que acontecia -- os dois caminhos saem com
 * exit 1, e so um deles saia parseavel.
 *
 * E o caso que faltava e o COMUM na maquina emprestada: ninguem desligou
 * nada, a maquina so nao tem bubblewrap instalado.
 */
class JudgehostSelfTestOnAnUnfitMachineTest extends TestCase
{
    private function runJson(): string
    {
        Artisan::call('judgehost:selftest', ['--json' => true]);

        return Artisan::output();
    }

    public function test_a_machine_without_the_sandbox_binary_still_answers_in_json(): void
    {
        config(['autojudge.use_bwrap' => true]);
        config(['autojudge.bwrap_path' => '/caminho/que/nao/existe/bwrap']);

        $saida = $this->runJson();

        $documento = json_decode($saida, true);

        $this->assertIsArray(
            $documento,
            'a saida de --json nao e JSON numa maquina sem bwrap: um consumidor nao distingue "nao confina" de "quebrou"'
        );
        $this->assertFalse($documento['passed'], 'uma maquina sem sandbox nao pode passar');
        $this->assertNotEmpty($documento['cases'] ?? [], 'o relatorio saiu sem caso nenhum');
        $this->assertStringContainsString(
            'bwrap',
            strtolower(json_encode($documento['cases'])),
            'o relatorio nao diz que o problema e o bwrap'
        );

        // E que diga o que FAZER. Sem isto o teste passaria tambem com a
        // mensagem crua da excecao, que descreve o estado e nao a acao --
        // "Refusing unconfined host execution" nao diz a ninguem que falta
        // instalar o bubblewrap. Esta assercao e o que torna a checagem
        // adiantada carregadora de peso: so ela produz a frase acionavel.
        $this->assertStringContainsString(
            'instale',
            strtolower(json_encode($documento['cases'])),
            'o relatorio diz o que esta errado mas nao o que fazer'
        );
    }

    /**
     * O outro lado, para a guarda nao passar por recusar tudo: com o caminho
     * apontando para um executavel de verdade, este caso especifico nao e o
     * que reprova.
     */
    public function test_the_guard_does_not_fire_when_the_binary_is_there(): void
    {
        config(['autojudge.use_bwrap' => true]);
        config(['autojudge.bwrap_path' => '/bin/echo']);

        $documento = json_decode($this->runJson(), true);

        $this->assertIsArray($documento, 'a saida deixou de ser JSON com um binario presente');

        $chaves = array_column($documento['cases'] ?? [], 'key');
        $this->assertNotContains(
            'sandbox_binary',
            $chaves,
            'a guarda do binario disparou mesmo com o binario presente'
        );
    }

    /**
     * O caminho que ja funcionava, aqui para nao regredir junto.
     */
    public function test_a_disabled_sandbox_still_answers_in_json(): void
    {
        config(['autojudge.use_bwrap' => false]);

        $documento = json_decode($this->runJson(), true);

        $this->assertIsArray($documento);
        $this->assertFalse($documento['passed']);
        $this->assertSame('sandbox_enabled', $documento['cases'][0]['key'] ?? null);
    }
}
