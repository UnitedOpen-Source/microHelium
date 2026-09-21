<?php

namespace Tests\Feature;

use App\Support\Judge\DockerfileToolchain;
use App\Support\Judge\ToolchainProfile;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Issue #306, segundo passo -- o que o comando entrega e feito para ser
 * ALIMENTADO, e nao so lido.
 *
 * `--apk` existe porque foi assim que os numeros da
 * docs/specs/306-perfis-de-toolchain.md foram levantados: a saida foi
 * direto para `apk add --simulate` dentro da imagem base. Se ela deixar de
 * ser exatamente a lista resolvida -- se ganhar um cabecalho, perder o pino
 * ou passar a imprimir o catalogo inteiro --, a medicao e o build que vierem
 * depois passam a medir outra coisa em silencio.
 */
class JudgeToolchainProfileCommandTest extends TestCase
{
    private function emit(string $arguments): string
    {
        Artisan::call('judge:toolchain-profile '.$arguments);

        return Artisan::output();
    }

    public function test_a_saida_apk_e_exatamente_a_lista_resolvida_do_perfil(): void
    {
        $profile = ToolchainProfile::named('maratona');
        self::assertNotNull($profile);

        $image = DockerfileToolchain::fromFile(base_path('Dockerfile.judge'), base_path());

        self::assertSame(
            implode(' ', $profile->apkPackages($image)),
            trim($this->emit('maratona --apk')),
        );
    }

    /**
     * O que a #306 quer dizer, dito pelo artefato que se passa adiante.
     */
    public function test_a_saida_apk_da_maratona_traz_o_compilador_fixado_e_nao_traz_o_toolchain_caro(): void
    {
        $line = ' '.trim($this->emit('maratona --apk')).' ';

        self::assertStringContainsString(' gcc=', $line);
        self::assertStringContainsString(' openjdk21-jdk=', $line);
        self::assertStringNotContainsString(' ghc=', $line);
        self::assertStringNotContainsString(' crystal=', $line);
        self::assertStringNotContainsString(' rust=', $line);
    }

    /**
     * Um perfil que nao existe nao pode sair como sucesso com lista vazia:
     * um `apk add` sem argumento nenhum constroi uma imagem que nao julga
     * nada, e o erro so apareceria no dia da prova.
     */
    public function test_perfil_desconhecido_falha_e_diz_quais_existem(): void
    {
        $status = Artisan::call('judge:toolchain-profile nao-existe --apk');

        self::assertSame(1, $status);
        self::assertStringContainsString('maratona', Artisan::output());
    }

    public function test_dockerfile_inexistente_falha_em_vez_de_gerar_lista_sem_pino(): void
    {
        $status = Artisan::call('judge:toolchain-profile maratona --apk --dockerfile=Dockerfile.nao-existe');

        self::assertSame(1, $status);
    }

    public function test_sem_argumento_lista_todos_os_perfis(): void
    {
        $output = $this->emit('');

        foreach (array_keys(ToolchainProfile::all()) as $name) {
            self::assertStringContainsString($name, $output);
        }
    }
}
