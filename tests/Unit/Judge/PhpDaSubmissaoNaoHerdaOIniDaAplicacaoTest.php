<?php

namespace Tests\Unit\Judge;

use App\Models\Language;
use App\Services\AutoJudgeService;
use App\Services\CgroupMemoryLimiter;
use Tests\TestCase;

/**
 * Issue #356 -- o `php.ini` da APLICACAO nao pode configurar o
 * interpretador da SUBMISSAO.
 *
 * ## O que aconteceu
 *
 * `Dockerfile` (a imagem que o docker-compose roda nos servicos `queue` e
 * `scheduler`, onde o `JudgeRunJob` compila e executa codigo submetido)
 * instala `docker/php/opcache.ini` em `/usr/local/etc/php/conf.d/`. Um
 * arquivo ali vale para TODA SAPI, e ele traz `opcache.enable_cli=1` com
 * `opcache.memory_consumption=256`.
 *
 * O sandbox monta `/usr` read-only (`autojudge.sandbox_paths`), entao esse
 * conf.d entra no bwrap junto com o binario: o `php` que roda o programa da
 * equipe mapeia 256 MB de memoria compartilhada antes da primeira linha do
 * programa.
 *
 * Onde nao ha cgroup v2 delegado -- e o caminho da fila e exatamente esse,
 * porque `docker/php/entrypoint.sh` nao delega --, o juiz aplica `ulimit -v`
 * de `limite + folga`. Num problema de 256 MB sao 320 MB, dos quais o
 * opcache ja levou 256. Sobravam ~44 MB para a equipe, e o resultado medido
 * na imagem da aplicacao era `RE` com "mmap() failed: [12] Out of memory"
 * num programa CORRETO, dentro do orcamento do enunciado.
 *
 * ## O que este teste prova, e o que nao prova
 *
 * PROVA: que a reserva fixa que o interpretador faz por causa do `.ini` da
 * aplicacao, DEPOIS de aplicados os `-d` do comando do catalogo, cabe na
 * barreira de espaco de enderecamento do juiz junto com o orcamento inteiro
 * do problema. Tirar o `-d opcache.enable_cli=0` do catalogo deixa este
 * teste vermelho.
 *
 * NAO PROVA que o interpretador se comporte assim: isso e medicao, e quem a
 * faz e `LanguageVerdictFidelityTest` rodando DENTRO de uma imagem. O buraco
 * que este teste fecha e outro -- a suite so roda na imagem do juiz
 * (`Dockerfile.judge`), que nao instala opcache nenhum, entao nenhuma
 * execucao de CI ve este defeito (#355). Ler os arquivos leva milissegundos
 * e roda em toda maquina; construir a imagem da aplicacao, nao.
 *
 * Os numeros nao estao escritos aqui: a reserva sai do `.ini` de verdade, a
 * barreira sai de `AutoJudgeService::addressSpaceLimitKbFor()` de verdade e
 * o comando sai do catalogo de verdade.
 */
class PhpDaSubmissaoNaoHerdaOIniDaAplicacaoTest extends TestCase
{
    /**
     * O limite de memoria de um problema, em MB.
     *
     * 256 e o `default` da coluna em
     * `database/migrations/2025_11_25_000005_create_problems_table.php`, ou
     * seja o orcamento que um problema tem quando ninguem escolhe outro.
     */
    private const LIMITE_DO_PROBLEMA_MB = 256;

    /**
     * A metade sem a qual o resto nao prova nada: se o conf.d da imagem NAO
     * entrasse no sandbox, o `.ini` da aplicacao nunca alcancaria a
     * submissao e nao haveria defeito a impedir.
     */
    public function test_o_confd_da_imagem_entra_no_sandbox_junto_com_o_binario(): void
    {
        $confd = '/usr/local/etc/php/conf.d';

        $alcancado = false;
        foreach ((array) config('autojudge.sandbox_paths') as $path) {
            if (is_string($path) && $path !== '' && str_starts_with($confd, rtrim($path, '/').'/')) {
                $alcancado = true;
                break;
            }
        }

        $this->assertTrue(
            $alcancado,
            "nenhum caminho de autojudge.sandbox_paths cobre {$confd}; se isso mudou, a premissa "
            .'deste arquivo mudou junto e o comentario de cima precisa ser reescrito'
        );
    }

    /**
     * A reserva que sobra depois dos `-d` do catalogo tem de caber na
     * barreira JUNTO com o orcamento inteiro do problema.
     */
    public function test_a_reserva_fixa_do_interpretador_nao_come_o_orcamento_do_problema(): void
    {
        $reservaMb = $this->reservaDeEspacoDeEnderecamentoMb(
            $this->iniDaImagemDaAplicacao(),
            $this->sobrescritasDoCatalogo($this->runCommandDoPhp())
        );

        $barreiraKb = $this->barreiraSemCgroupKb();

        $this->assertNotNull(
            $barreiraKb,
            'o PHP deixou de receber barreira de espaco de enderecamento (memory_grace_mb["php"] virou null); '
            .'nesse caso este teste nao mede mais nada e precisa ser reescrito ou removido'
        );

        $precisoKb = ($reservaMb + self::LIMITE_DO_PROBLEMA_MB) * 1024;

        $this->assertLessThanOrEqual(
            $barreiraKb,
            $precisoKb,
            sprintf(
                'o interpretador da submissao reserva %d MB por causa do .ini da APLICACAO, e com os %d MB do '
                ."problema isso pede %d KB de espaco de enderecamento -- a barreira do juiz sem cgroup e %d KB.\n"
                .'Um programa PHP correto, dentro do orcamento do enunciado, recebe RE com "mmap() failed: '
                ."[12] Out of memory\" (issue #356).\nComando do catalogo: %s",
                $reservaMb,
                self::LIMITE_DO_PROBLEMA_MB,
                $precisoKb,
                $barreiraKb,
                $this->runCommandDoPhp()
            )
        );
    }

    /**
     * `ulimit -v` que o juiz aplica ao PHP quando nao ha cgroup delegado --
     * o caminho da fila. Vem do metodo de verdade, com um
     * `CgroupMemoryLimiter` apontado para um diretorio que nao existe.
     */
    private function barreiraSemCgroupKb(): ?int
    {
        $judge = new AutoJudgeService(
            new CgroupMemoryLimiter(sys_get_temp_dir().'/mh-356-sem-cgroup-'.getmypid())
        );

        $metodo = new \ReflectionMethod($judge, 'addressSpaceLimitKbFor');
        $metodo->setAccessible(true);

        /** @var int|null $kb */
        $kb = $metodo->invoke($judge, (object) ['extension' => 'php'], self::LIMITE_DO_PROBLEMA_MB);

        return $kb;
    }

    private function runCommandDoPhp(): string
    {
        foreach (Language::getDefaultLanguages() as $language) {
            if (($language['extension'] ?? null) === 'php') {
                return (string) ($language['run_command'] ?? '');
            }
        }

        $this->fail('o catalogo nao tem mais a entrada `php`');
    }

    /**
     * As diretivas que o `Dockerfile` da aplicacao instala em
     * `/usr/local/etc/php/conf.d/`, lidas dos arquivos de verdade.
     *
     * A lista de arquivos sai do proprio Dockerfile e nao esta escrita aqui:
     * um `.ini` novo copiado para o conf.d entra neste teste sozinho.
     *
     * @return array<string, string>
     */
    private function iniDaImagemDaAplicacao(): array
    {
        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

        preg_match_all(
            '#^COPY\s+(\S+\.ini)\s+/usr/local/etc/php/conf\.d/#m',
            $dockerfile,
            $matches
        );

        $this->assertNotEmpty(
            $matches[1],
            'o Dockerfile da aplicacao nao copia mais nenhum .ini para /usr/local/etc/php/conf.d; '
            .'se isso e verdade o defeito da #356 sumiu pela raiz e este teste precisa ser reescrito'
        );

        $diretivas = [];

        foreach ($matches[1] as $arquivo) {
            $caminho = base_path($arquivo);

            $this->assertFileExists($caminho, "o Dockerfile copia {$arquivo}, que nao existe no repositorio");

            $lidas = parse_ini_file($caminho, false, INI_SCANNER_RAW);

            if (is_array($lidas)) {
                $diretivas = array_merge($diretivas, array_map('strval', $lidas));
            }
        }

        return $diretivas;
    }

    /**
     * Os `-d chave=valor` do comando do catalogo, que valem por cima do
     * conf.d da imagem.
     *
     * @return array<string, string>
     */
    private function sobrescritasDoCatalogo(string $command): array
    {
        preg_match_all('/-d\s+([A-Za-z0-9_.]+)=(\S+)/', $command, $matches, PREG_SET_ORDER);

        $overrides = [];
        foreach ($matches as $match) {
            $overrides[$match[1]] = $match[2];
        }

        return $overrides;
    }

    /**
     * Quanto espaco de enderecamento o interpretador reserva na PARTIDA,
     * antes de executar uma linha do programa submetido.
     *
     * Hoje isso e o opcache: quando ligado para a CLI ele mapeia a memoria
     * compartilhada de `opcache.memory_consumption` e, quando o JIT esta
     * ativo, tambem o `opcache.jit_buffer_size`. O buffer do JIT entra na
     * conta por cima -- na imagem da aplicacao o pcov sobrescreve
     * `zend_execute_ex()` e o PHP desliga o JIT sozinho ("JIT is
     * incompatible with third party extensions"), mas um build sem pcov
     * reservaria os dois, e a barreira tem de valer para os dois.
     *
     * @param  array<string, string>  $ini
     * @param  array<string, string>  $overrides
     */
    private function reservaDeEspacoDeEnderecamentoMb(array $ini, array $overrides): int
    {
        $valor = static function (string $chave) use ($ini, $overrides): ?string {
            return $overrides[$chave] ?? $ini[$chave] ?? null;
        };

        $ligado = static function (?string $bruto): bool {
            if ($bruto === null) {
                return false;
            }

            return in_array(strtolower(trim($bruto)), ['1', 'on', 'true', 'yes'], true);
        };

        if (! $ligado($valor('opcache.enable')) || ! $ligado($valor('opcache.enable_cli'))) {
            return 0;
        }

        $mb = (int) ($valor('opcache.memory_consumption') ?? 0);

        if ($ligado($valor('opcache.jit')) || (int) ($valor('opcache.jit') ?? 0) > 0) {
            $mb += $this->shorthandMb($valor('opcache.jit_buffer_size'));
        }

        return $mb;
    }

    /**
     * `256M`, `1G`, `262144` -- a notacao abreviada do php.ini, em MB.
     */
    private function shorthandMb(?string $bruto): int
    {
        if ($bruto === null || trim($bruto) === '') {
            return 0;
        }

        $bruto = trim($bruto);
        $numero = (int) $bruto;
        $sufixo = strtolower(substr($bruto, -1));

        return match ($sufixo) {
            'g' => $numero * 1024,
            'm' => $numero,
            'k' => intdiv($numero, 1024),
            default => intdiv($numero, 1024 * 1024),
        };
    }
}
