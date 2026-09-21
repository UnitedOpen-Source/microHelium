<?php

namespace Tests\Unit\Judge;

use App\Models\Language;
use App\Support\Judge\DockerfileToolchain;
use PHPUnit\Framework\TestCase;

/**
 * Issue #339 -- a desativacao da BEAM deixa de valer "por combinado".
 *
 * ## O que aconteceu, e o que pode voltar a acontecer
 *
 * `erl` e `ex` foram desligadas do catalogo (PR #346) porque a maquina
 * virtual da BEAM nao SOBE de forma confiavel na imagem do juiz:
 *
 *     sys/unix/sys_signal_stack.c:101:sys_sigaltstack():
 *         Internal error: Failed to set alternate signal stack
 *
 * A causa nao e nossa e esta medida: a ERTS dimensiona a pilha alternativa
 * de sinal com o `SIGSTKSZ` ESTATICO da musl e o kernel recusa quando o
 * `AT_MINSIGSTKSZ` da CPU e maior (AVX-512, AMX). Quem decide e o host, o
 * que explica a intermitencia -- um runner passa e o seguinte derruba quatro
 * testes, inclusive PRs que nao tem relacao nenhuma com a BEAM.
 *
 * O que impedia alguem de virar os dois `is_active` de volta para `true`?
 * Nada. O motivo morava num comentario, e comentario nao reprova. Este teste
 * e quem reprova: ligar a BEAM sem que a imagem instale uma OTP que carregue
 * a correcao passa a custar uma falha aqui, em vez de custar uma prova.
 *
 * ## Por que o criterio e "OTP >= 29.1", e nao "a versao mais nova"
 *
 * Esta e a parte que um teste precisa fixar, porque o fato e contraintuitivo
 * e ja enganou o proprio texto que o registrava.
 *
 * A correcao e o `erlang/otp#11376` (`sysconf(_SC_MINSIGSTKSZ)` no lugar da
 * constante). Ela foi mesclada em `maint` em 10/08/2026 e saiu na release
 * **OTP-29.1, de 16/09/2026**. Conferido pelo CONTEUDO do arquivo em cada
 * tag, e nao por comparacao de arvore (medicao de 20/09/2026):
 *
 *     OTP-29.1       erts/emulator/sys/unix/sys_signal_stack.c
 *                    => `#if defined(_SC_MINSIGSTKSZ)` ... TEM
 *     OTP-29.0.6     => so `ss.ss_size = SIGSTKSZ;`      ... NAO TEM
 *     maint-28       => so `ss.ss_size = SIGSTKSZ;`      ... NAO TEM
 *     maint-27       => so `ss.ss_size = SIGSTKSZ;`      ... NAO TEM
 *
 * Ou seja: **subir de major nao basta**. No dia em que este teste foi
 * escrito o Alpine `edge/testing` passou a publicar um `erlang29` -- e ele e
 * o **29.0.6**, que NAO carrega a correcao. Quem lesse "espere o erlang29 do
 * Alpine" (que e o que a #309 registrava como caminho barato) trocaria o
 * pino, veria o teste da versao ficar verde e reativaria as linguagens
 * direto para o mesmo defeito. Por isso o criterio e uma comparacao de
 * versao contra a primeira release corrigida, e nao um nome de pacote.
 *
 * ## Por que esta na suite normal, e estatico
 *
 * A pergunta "a BEAM sobe vinte vezes seguidas?" ja tem dono: o
 * `test_a_beam_sobe_vinte_vezes_seguidas_dentro_do_sandbox`
 * (tests/E2E/LanguageVerdictFidelityTest.php), que so pode responder dentro
 * da imagem e so no host em que roda. Este aqui responde outra, que nenhuma
 * maquina precisa ter Erlang para responder: "o catalogo esta oferecendo uma
 * linguagem cuja imagem nao pode executar?". Ler tres arquivos custa
 * milissegundos e vale em qualquer runner.
 */
class ReativarABeamExigeOtpCorrigidaTest extends TestCase
{
    /**
     * A primeira release do OTP que carrega o `erlang/otp#11376`.
     *
     * @see https://github.com/erlang/otp/pull/11376
     */
    private const PRIMEIRA_OTP_CORRIGIDA = '29.1';

    /**
     * As mesmas tres imagens que compilam e executam codigo submetido -- a
     * lista do ToolchainManifestParityTest, pelo mesmo motivo (a #302: uma
     * correcao que entra numa imagem e nao nas outras duas).
     *
     * @return list<string>
     */
    private static function dockerfiles(): array
    {
        return ['Dockerfile', 'Dockerfile.dev', 'Dockerfile.judge'];
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * O pino do pacote `erlang*` de uma imagem: nome => pino.
     *
     * O major mora no NOME do pacote no Alpine (`erlang27`, `erlang28`,
     * `erlang29`), e a release exata mora no pino. Quem decide se a correcao
     * esta la e o pino, nunca o nome -- ver o `erlang29` = 29.0.6 do bloco
     * de cima.
     *
     * @return array<string, string|null>
     */
    private static function pinosDeErlang(string $dockerfile): array
    {
        $pacotes = DockerfileToolchain::fromFile(
            self::repoRoot().'/'.$dockerfile,
            self::repoRoot()
        )->apkPackages();

        return array_filter(
            $pacotes,
            fn (string $nome): bool => preg_match('/^erlang\d*$/', $nome) === 1,
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * O pino do Alpine e `29.1.2-r0`; o `-rN` e a revisao do empacotamento
     * e nao entra na comparacao de release do OTP.
     */
    private static function carregaACorrecao(?string $pino): bool
    {
        if ($pino === null || $pino === '') {
            return false;
        }

        $versao = (string) preg_replace('/-r\d+$/', '', $pino);

        return version_compare($versao, self::PRIMEIRA_OTP_CORRIGIDA, '>=');
    }

    /**
     * O controle que NAO depende do estado do catalogo.
     *
     * Enquanto `erl` e `ex` estiverem desligadas o teste do guard abaixo
     * fica vacuo por construcao -- premissa falsa, implicacao verdadeira.
     * Esta tabela e o que impede que ele fique verde por estar vazio E por
     * estar errado ao mesmo tempo: ela exercita o criterio direto, com as
     * versoes que existem de verdade nos repositorios do Alpine hoje.
     *
     * As tres primeiras linhas sao os tres pacotes que o Alpine publica em
     * 20/09/2026 (`erlang27` 27.3.4.17 e `erlang28` 28.5.0.6 em
     * community/v3.24, `erlang29` 29.0.6 em edge/testing) -- e as TRES sao
     * insuficientes. E essa a armadilha que esta linha guarda.
     */
    public function test_o_criterio_recusa_toda_otp_que_o_alpine_publica_hoje(): void
    {
        $casos = [
            // [pino, carrega a correcao?]
            ['27.3.4.17-r0', false],
            ['28.5.0.6-r0', false],
            ['29.0.6-r0', false],
            ['29.1-r0', true],
            ['29.1.1-r0', true],
            ['30.0-r0', true],
            // Sem pino nao da para afirmar nada, e "nao da para afirmar" tem
            // de ser tratado como insuficiente: a duvida nao pode virar
            // autorizacao para ligar a linguagem.
            [null, false],
        ];

        $errados = [];

        foreach ($casos as [$pino, $esperado]) {
            if (self::carregaACorrecao($pino) !== $esperado) {
                $errados[] = ($pino ?? '(sem pino)').' deveria ser '.($esperado ? 'suficiente' : 'insuficiente');
            }
        }

        $this->assertSame(
            [],
            $errados,
            "o criterio de 'OTP com a correcao' classificou errado:\n  ".implode("\n  ", $errados)
        );
    }

    /**
     * O guard: oferecer a BEAM obriga as TRES imagens a uma OTP corrigida.
     *
     * Enquanto `erl` e `ex` estiverem desligadas isto nao exige nada de
     * ninguem -- inclusive nao exige que a BEAM continue instalada, que e o
     * que permite tirar os ~87 MiB de peso morto das tres imagens sem
     * quebrar este teste.
     */
    public function test_ligar_a_beam_exige_as_tres_imagens_numa_otp_com_a_correcao(): void
    {
        $catalogo = collect(Language::getDefaultLanguages());
        $ativa = fn (string $ext): bool => (bool) ($catalogo->firstWhere('extension', $ext)['is_active'] ?? false);

        $beamOferecida = $ativa('erl') || $ativa('ex');

        $ofensas = [];

        foreach ($beamOferecida ? self::dockerfiles() : [] as $dockerfile) {
            $pinos = self::pinosDeErlang($dockerfile);

            if ($pinos === []) {
                $ofensas[] = "{$dockerfile}: o catalogo oferece a BEAM e esta imagem nao instala pacote `erlang*` nenhum";

                continue;
            }

            foreach ($pinos as $nome => $pino) {
                if (! self::carregaACorrecao($pino)) {
                    $ofensas[] = "{$dockerfile}: {$nome}=".($pino ?? '(sem pino)')
                        .' e anterior a OTP-'.self::PRIMEIRA_OTP_CORRIGIDA;
                }
            }
        }

        $this->assertSame(
            [],
            $ofensas,
            "o catalogo voltou a oferecer Erlang/Elixir sobre uma OTP que NAO carrega a correcao:\n  "
            .implode("\n  ", $ofensas)."\n\n"
            ."A BEAM foi desligada na #339 porque a maquina virtual nao sobe de forma\n"
            ."confiavel em x86_64: `sys_sigaltstack(): Failed to set alternate signal stack`.\n"
            ."A ERTS usa o SIGSTKSZ estatico da musl e o kernel recusa quando o AT_MINSIGSTKSZ\n"
            ."da CPU e maior. A correcao e erlang/otp#11376, e a primeira release que a carrega\n"
            .'e a OTP-'.self::PRIMEIRA_OTP_CORRIGIDA." (16/09/2026) -- o `erlang29` do Alpine e 29.0.6 e NAO serve.\n\n"
            ."Para reativar: suba o pino de `erlang*` nos TRES Dockerfiles para uma release\n"
            ."corrigida, rode o `test_a_beam_sobe_vinte_vezes_seguidas_dentro_do_sandbox` no\n"
            .'runner de x86_64, e so entao vire `is_active` em `erl` e `ex`.'
        );
    }
}
