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
     * Enquanto a BEAM continuar instalada, as tres imagens instalam A MESMA.
     *
     * As linguagens estao desligadas e o toolchain continua nas tres imagens
     * (+86 MiB em cada, medido no comentario do `Dockerfile.judge`). Isso e
     * deliberado: reativar precisa ser trocar um `false`, e nao reconstruir a
     * receita. O efeito colateral e que esses pinos ficam FORA do
     * `ToolchainManifestParityTest`, que so percorre as linguagens ligadas --
     * ou seja, hoje nada impediria os tres Dockerfiles de divergirem em
     * silencio e a divergencia so aparecer no dia da reativacao.
     *
     * Se um dia a decisao for remover a BEAM das imagens, este caso sai junto
     * -- e ai ele falha ate ser removido, que e o comportamento desejado: a
     * remocao tem de ser uma decisao, nao um esquecimento.
     */
    public function test_as_tres_imagens_fixam_a_mesma_beam_enquanto_ela_continuar_instalada(): void
    {
        $raiz = dirname(__DIR__, 3);

        foreach ([ToolchainRequirement::apk('erlang27'), ToolchainRequirement::apk('elixir')] as $exigencia) {
            $pinos = [];

            foreach (self::dockerfiles() as $dockerfile) {
                $imagem = DockerfileToolchain::fromFile($raiz.'/'.$dockerfile, $raiz);

                $this->assertTrue(
                    $imagem->satisfies($exigencia),
                    "{$dockerfile} deixou de instalar ".$exigencia->describe().", e as outras imagens ainda instalam.\n"
                    .'Tirar a BEAM de uma imagem so e a #302 de novo; ou tire das tres (e apague este caso), ou '
                    .'mantenha nas tres.'
                );

                $pinos[$dockerfile] = $imagem->pinFor($exigencia);
            }

            $this->assertNotNull(
                $pinos['Dockerfile'],
                $exigencia->describe().' esta sem pino de versao (#303)'
            );

            $this->assertLessThanOrEqual(
                1,
                count(array_unique($pinos, SORT_REGULAR)),
                $exigencia->describe().' esta fixado em versoes diferentes entre as imagens: '
                .json_encode($pinos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
                .'Enquanto `erl`/`ex` estao desligadas isto nao passa pelo ToolchainManifestParityTest, que so '
                .'percorre as linguagens ligadas -- a divergencia so apareceria no dia da reativacao.'
            );
        }
    }
}
