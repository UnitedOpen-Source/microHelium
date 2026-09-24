<?php

namespace Tests\E2E;

use App\Models\Language;
use App\Services\Judgehost\MachineCapabilities;
use App\Support\Judge\PerfilDaImagem;
use App\Support\Judge\ToolchainManifest;
use App\Support\Judge\ToolchainProfile;
use Tests\TestCase;

/**
 * Issue #306, passo 3 -- a promessa da imagem por perfil, conferida DENTRO
 * dela.
 *
 * A issue pedia que o CI provasse, para cada perfil, as duas metades de uma
 * frase: *esta imagem julga estas linguagens, e nao afirma julgar nenhuma
 * outra*. Quem prova que cada linguagem prometida julga uma solucao certa como
 * AC e o `MultiLanguageJudgingTest`, que passou a percorrer as linguagens que
 * a imagem promete. Este arquivo prova o resto:
 *
 *   - a imagem SABE qual perfil ela e (sem isso a sonda nao tem o que
 *     restringir);
 *   - a sonda encontra tudo que o perfil promete;
 *   - a sonda nao declara nada fora dele;
 *   - a imagem NAO recebeu ordem de instalar o que o perfil corta.
 *
 * ## Por que `/etc/apk/world`, e nao "o pacote nao existe"
 *
 * Um pacote cortado pode entrar como DEPENDENCIA de outro: o `maratona` corta
 * `zlib`, e o `python3` o puxa. O que o recorte controla -- e o que a spec da
 * #306 sempre disse que era o provavel -- e a ORDEM de instalacao, que e o que
 * decide tamanho e tempo de build. O `/etc/apk/world` e exatamente essa
 * lista: o que alguem pediu, e nao o que a arvore trouxe.
 *
 * So faz sentido dentro de uma imagem do juiz, que e onde o CI o roda, com
 * `--fail-on-skipped`. Fora dela, pula.
 */
class PerfilDaImagemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! is_file('/etc/apk/world') || getenv('JUDGE_PROFILE') === false) {
            $this->markTestSkipped('fora de uma imagem do juiz (sem /etc/apk/world ou sem JUDGE_PROFILE)');
        }
    }

    /** @return list<array<string, mixed>> */
    private static function ativas(): array
    {
        return array_values(array_filter(
            Language::getDefaultLanguages(),
            fn (array $l): bool => ($l['is_active'] ?? false) === true
        ));
    }

    public function test_a_imagem_sabe_de_qual_perfil_ela_e(): void
    {
        $this->assertArrayHasKey(
            (string) getenv('JUDGE_PROFILE'),
            ToolchainProfile::all(),
            'a imagem carrega um JUDGE_PROFILE que o codigo nao conhece'
        );
    }

    public function test_a_sonda_encontra_tudo_que_o_perfil_promete(): void
    {
        $detectadas = (new MachineCapabilities)->detect(self::ativas());

        $faltando = array_values(array_diff(
            array_intersect(PerfilDaImagem::prometidas(), array_column(self::ativas(), 'extension')),
            $detectadas
        ));

        $this->assertSame(
            [],
            $faltando,
            'o perfil '.PerfilDaImagem::nome().' promete estas linguagens e a sonda nao achou o toolchain delas na imagem: '
            .implode(', ', $faltando)
        );
    }

    public function test_a_sonda_nao_declara_nada_fora_do_perfil(): void
    {
        $detectadas = (new MachineCapabilities)->detect(self::ativas());

        $this->assertSame(
            [],
            array_values(array_diff($detectadas, PerfilDaImagem::prometidas())),
            'a imagem declararia julgar linguagens que o perfil '.PerfilDaImagem::nome().' nao promete'
        );
    }

    public function test_a_imagem_nao_recebeu_ordem_de_instalar_o_que_o_perfil_corta(): void
    {
        $world = [];
        foreach (file('/etc/apk/world', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
            $world[(string) preg_replace('/[=<>~].*$/', '', trim($linha))] = true;
        }

        $executaveisPor = [];
        foreach (ToolchainManifest::provenance() as $executavel => $exigencias) {
            foreach ($exigencias as $exigencia) {
                $executaveisPor[$exigencia->key()][] = $executavel;
            }
        }

        $presentes = [];

        foreach (PerfilDaImagem::perfil()->cuts() as $corte) {
            [$kind, $alvo] = explode(':', $corte, 2);

            if ($kind === 'apk' && isset($world[$alvo])) {
                $presentes[] = "{$corte} (esta em /etc/apk/world)";
            }

            if ($kind === 'download') {
                foreach ($executaveisPor[$corte] ?? [] as $executavel) {
                    if (trim((string) shell_exec('command -v '.escapeshellarg($executavel).' 2>/dev/null')) !== '') {
                        $presentes[] = "{$corte} (`{$executavel}` esta no PATH)";
                    }
                }
            }

            if ($kind === 'npm' && (is_dir("/usr/local/lib/node_modules/{$alvo}") || is_dir("/usr/lib/node_modules/{$alvo}"))) {
                $presentes[] = "{$corte} (pacote global do npm instalado)";
            }
        }

        $this->assertSame(
            [],
            $presentes,
            'o perfil '.PerfilDaImagem::nome().' corta isto, e a imagem instalou mesmo assim:'."\n  ".implode("\n  ", $presentes)
        );
    }
}
