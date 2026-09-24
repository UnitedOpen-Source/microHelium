<?php

namespace Tests\Unit\Judge;

use App\Models\Language;
use App\Support\Judge\DockerfileToolchain;
use App\Support\Judge\ToolchainRequirement;
use PHPUnit\Framework\TestCase;

/**
 * Issue #339 -- a desativacao de `erl` e `ex` deixa de ser so um `false` no
 * catalogo e passa a ter quem a guarde na suite rapida.
 *
 * ## O que este arquivo impede
 *
 * A BEAM nao sobe de forma confiavel na imagem do juiz em x86_64:
 * `sys_signal_stack.c:101:sys_sigaltstack(): Internal error: Failed to set
 * alternate signal stack`. A causa nao e nossa -- a ERTS dimensiona a pilha
 * alternativa de sinal com o `SIGSTKSZ` ESTATICO da musl e o kernel recusa
 * onde o `AT_MINSIGSTKSZ` da CPU e maior (AVX-512, AMX). Quem decide e a CPU
 * do host, e por isso a falha e intermitente: o mesmo commit passa numa
 * execucao e derruba quatro testes na seguinte. Ja segurou o CI da correcao
 * de seguranca da #311, que nao tem relacao nenhuma com Erlang.
 *
 * As duas foram desligadas pelo PR #346. O que faltava era uma guarda: hoje
 * um `true` de volta nao tropeca em nada que fale da #339.
 *
 * ## Por que na suite rapida, e nao ao lado do teste de 20 partidas
 *
 * `LanguageVerdictFidelityTest::test_a_beam_sobe_vinte_vezes_seguidas_dentro_do_sandbox`
 * ja guarda a coerencia, mas vive em `tests/E2E` -- suite que o CI exclui do
 * job de todo PR (`--exclude-testsuite E2E`) e so roda dentro da imagem do
 * juiz. Uma reativacao acidental so seria vista no job mais caro, depois de
 * construir a imagem. Aqui custa milissegundos e roda em todo PR.
 *
 * ## Quando este arquivo sai
 *
 * Quando o bloqueio cair, `test_erl_e_ex_continuam_desligadas...` DEVE ser
 * apagado junto com a reativacao -- ele existe para ser removido de
 * proposito, e nao contornado. Os outros dois casos continuam valendo depois
 * disso: a coerencia entre as duas e a igualdade dos pinos nas tres imagens
 * nao dependem da #339.
 */
class CatalogoBeamDesativadaTest extends TestCase
{
    /**
     * As tres imagens que compilam e executam codigo submetido, como em
     * {@see ToolchainManifestParityTest}.
     *
     * @return list<string>
     */
    private static function dockerfiles(): array
    {
        return ['Dockerfile', 'Dockerfile.dev', 'Dockerfile.judge'];
    }

    private static function catalogo(string $extension): array
    {
        foreach (Language::getDefaultLanguages() as $language) {
            if (($language['extension'] ?? null) === $extension) {
                return $language;
            }
        }

        self::fail("a extensao `{$extension}` sumiu do catalogo; se foi removida de proposito, este teste sai junto");
    }

    private static function ativa(string $extension): bool
    {
        return (bool) (self::catalogo($extension)['is_active'] ?? false);
    }

    /**
     * O guard que existe para ser apagado no dia certo, e nao antes.
     *
     * A condicao de reativacao mudou desde o PR #346 e por isso esta escrita
     * aqui, e nao so num comentario: a correcao do upstream
     * (`sysconf(_SC_MINSIGSTKSZ)`, erlang/otp#11376) JA saiu em release --
     * OTP-29.1, 16/09/2026 --, entao "esperar uma release com a correcao"
     * virou uma condicao ja satisfeita que reativaria a linguagem para dentro
     * do mesmo defeito. O que falta e o EMPACOTAMENTO.
     */
    public function test_erl_e_ex_continuam_desligadas_enquanto_o_alpine_nao_publicar_a_correcao(): void
    {
        $motivo = <<<'TXT'

            A BEAM nao sobe de forma confiavel dentro do sandbox em x86_64
            (`sys_sigaltstack(): Failed to set alternate signal stack`), e a
            falha e INTERMITENTE: depende do AT_MINSIGSTKSZ da CPU do host, e
            nao do codigo deste repositorio. Linguagem intermitente e pior que
            linguagem ausente -- a equipe submete e recebe veredito que nao e
            dela.

            Antes de trocar este `false` por `true`, confira NA FONTE (medido
            em 20/09/2026; se a data acima estiver velha, meca de novo):

              1. O Alpine publica um pacote `erlang` que carregue a correcao?
                 `apk search -x 'erlang*'`, e olhe TODOS os repositorios,
                 nao so `community`. Reconferido em 21/09/2026:
                 community (v3.24 e edge) tem erlang27 (27.3.4.17-r0) e
                 erlang28 (28.5.0.6-r0); edge/testing tem erlang29
                 (29.0.6-r0). O `erlang29` EXISTE e mesmo assim NAO SERVE --
                 29.0.6 e anterior a 29.1. O nome do pacote nao responde
                 nada; quem responde e o pino.
              2. A release empacotada tem mesmo a correcao? Confira pelo
                 CONTEUDO, e nao pelo numero:
                 `erts/emulator/sys/unix/sys_signal_stack.c` tem de conter
                 `sysconf(_SC_MINSIGSTKSZ)`. Tem em OTP-29.1; nao tem em
                 OTP-28.5.0.6, OTP-27.3.4.17, nem em `maint-28`/`maint-27` --
                 o backport prometido pela equipe do OTP ainda nao saiu.
              3. Suba o pino nos TRES Dockerfiles, nao em um (#302, #352,
                 #356 -- a correcao que entra num lugar e nao no outro e o
                 defeito recorrente deste repositorio).
              4. So entao apague ESTE caso de teste e deixe o
                 `test_a_beam_sobe_vinte_vezes_seguidas_dentro_do_sandbox`
                 (tests/E2E, roda dentro da imagem) dizer se a maquina aguenta.

            Ver #339 e o quadro de bloqueios da #309.
            TXT;

        $this->assertFalse(
            self::ativa('erl'),
            '`erl` foi reativada no catalogo e o bloqueio da #339 continua de pe.'.$motivo
        );

        $this->assertFalse(
            self::ativa('ex'),
            '`ex` foi reativada no catalogo e o bloqueio da #339 continua de pe.'.$motivo
        );
    }

    /**
     * Erlang e Elixir sao a MESMA maquina virtual.
     *
     * Nao existe estado em que uma esteja ligada e a outra nao: o defeito que
     * derruba uma derruba a outra, e meio-desativar e como a #302 e a #352
     * aconteceram. Este caso sobrevive a reativacao -- e por isso ele nao
     * esta dentro do anterior.
     */
    public function test_erl_e_ex_ligam_e_desligam_juntas(): void
    {
        $this->assertSame(
            self::ativa('erl'),
            self::ativa('ex'),
            'erl e ex compartilham a BEAM: nao existe estado em que uma esteja ativa e a outra nao (#339). '
            .'Se a intencao era reativar, reative as duas; se era desativar, desative as duas.'
        );
    }

    /**
     * As tres imagens concordam sobre a BEAM: ou NENHUMA a instala, ou as
     * tres fixam A MESMA.
     *
     * Ate 24/09/2026 a BEAM ficava instalada e desligada, para que reativar
     * fosse trocar um `false`. Nesse dia o Alpine publicou a OTP-27.3.4.18,
     * tirou do repositorio o `erlang27=27.3.4.17-r0` que as tres imagens
     * fixavam, e o `elixir` passou a puxar o `erlang28` -- todo build sem cache
     * das tres imagens parou em `unable to select packages`, por causa de dois
     * pacotes que nenhuma prova usava. A BEAM saiu das tres imagens.
     *
     * Este caso substitui o que exigia "a mesma BEAM enquanto ela continuar
     * instalada" -- e o docblock daquele caso ja dizia que ele sairia junto
     * com a remocao. O que ele guardava continua guardado, nos dois estados:
     * como `erl`/`ex` estao fora do ToolchainManifestParityTest (que so
     * percorre linguagens ligadas), nada mais impediria uma imagem de voltar a
     * instalar a BEAM sozinha, que e a forma da #302. Reativar exige pino
     * `>= 29.1` (ReativarABeamExigeOtpCorrigidaTest) -- e as tres juntas.
     */
    public function test_as_tres_imagens_concordam_sobre_a_beam(): void
    {
        $raiz = dirname(__DIR__, 3);

        foreach ([ToolchainRequirement::apk('erlang27'), ToolchainRequirement::apk('erlang28'), ToolchainRequirement::apk('erlang29'), ToolchainRequirement::apk('elixir')] as $exigencia) {
            $instala = [];
            $pinos = [];

            foreach (self::dockerfiles() as $dockerfile) {
                $imagem = DockerfileToolchain::fromFile($raiz.'/'.$dockerfile, $raiz);
                $instala[$dockerfile] = $imagem->satisfies($exigencia);
                $pinos[$dockerfile] = $imagem->pinFor($exigencia);
            }

            $this->assertCount(
                1,
                array_unique($instala),
                $exigencia->describe().' esta em umas imagens e nao em outras: '
                .json_encode($instala, JSON_UNESCAPED_SLASHES).'
'
                .'A BEAM entra nas tres ou em nenhuma -- meia imagem e a #302 de novo.'
            );

            if (in_array(true, $instala, true)) {
                $this->assertNotContains(null, $pinos, $exigencia->describe().' esta sem pino de versao (#303)');
                $this->assertCount(
                    1,
                    array_unique($pinos),
                    $exigencia->describe().' esta fixado em versoes diferentes entre as imagens: '
                    .json_encode($pinos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }
        }
    }
}
