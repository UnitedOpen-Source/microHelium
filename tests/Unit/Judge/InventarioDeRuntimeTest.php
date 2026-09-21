<?php

namespace Tests\Unit\Judge;

use App\Models\Language;
use App\Support\Judge\DockerfileToolchain;
use App\Support\Judge\ToolchainManifest;
use App\Support\Judge\ToolchainRequirement;
use PHPUnit\Framework\TestCase;

/**
 * Issue #300 -- o inventario de runtime deixa de ser corpo de issue e vira
 * arquivo guardado.
 *
 * ## Por que um teste le um documento
 *
 * O texto de docs/specs/300-inventario-de-runtime.md e DERIVADO do
 * repositorio: quantas linguagens o catalogo oferece, com que pino cada
 * toolchain entra, o que vem de onde. Enquanto ele morou no corpo de uma
 * issue, a unica coisa que o mantinha verdadeiro era alguem lembrar de
 * reconferir -- e entre 19 e 21/09/2026 os tres Dockerfiles e o catalogo
 * mudaram sete vezes. Um inventario desatualizado e pior que nenhum, porque
 * ele parece medido.
 *
 * Entao a regra deste arquivo e a mesma do
 * JudgeToolchainProfileCommandTest (#365) sobre a spec da #306: o documento
 * nao e prosa livre, e uma VISTA do que o codigo ja sabe, e divergir custa
 * uma falha de teste.
 *
 * ## O que este teste NAO promete
 *
 * Ele confere o que e derivavel estaticamente: contagens do catalogo e pinos
 * do Dockerfile. Ele NAO confere as versoes medidas dentro da imagem (quem
 * faz isso e tests/E2E/ToolchainVersionsMatchCatalogTest.php, que roda
 * dentro dela), nem os md5 dos invocadores, nem os numeros de `apk add
 * --simulate`. Esses estao no documento com a data e a imagem em que foram
 * medidos, que e a forma honesta de registrar o que um teste rapido nao
 * alcanca -- e dize-lo aqui evita que a existencia deste arquivo seja lida
 * como "tudo no documento esta guardado".
 */
class InventarioDeRuntimeTest extends TestCase
{
    private const DOCUMENTO = 'docs/specs/300-inventario-de-runtime.md';

    private static function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function texto(): string
    {
        return (string) file_get_contents(self::raiz().'/'.self::DOCUMENTO);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function ativas(): array
    {
        return array_values(array_filter(
            Language::getDefaultLanguages(),
            static fn (array $language): bool => (bool) ($language['is_active'] ?? false),
        ));
    }

    public function test_o_documento_existe_no_lugar_combinado(): void
    {
        self::assertFileExists(
            self::raiz().'/'.self::DOCUMENTO,
            'O inventario da #300 e um documento versionado, nao um corpo de issue.',
        );
    }

    /**
     * As tres contagens de cabecalho. Sao o que um leitor cita de cabeca, e
     * por isso sao as que mais caro custam quando envelhecem.
     */
    public function test_as_contagens_do_catalogo_batem_com_o_catalogo(): void
    {
        $texto = self::texto();
        $todas = Language::getDefaultLanguages();
        $ativas = self::ativas();

        $esperado = [
            count($todas) => 'entradas no catalogo',
            count($ativas) => 'ativas',
            count($todas) - count($ativas) => 'inativas',
        ];

        foreach ($esperado as $numero => $rotulo) {
            self::assertMatchesRegularExpression(
                '/\*\*'.$numero.'\*\*/',
                $texto,
                "O documento tem de dizer **{$numero}** {$rotulo}; o catalogo diz {$numero}.",
            );
        }
    }

    /**
     * Toda entrada ativa aparece nominalmente no documento, e toda extensao
     * citada no documento e uma entrada ativa de verdade.
     *
     * A segunda metade e a que pega o caso chato: uma linguagem DESATIVADA
     * que continua descrita como se fosse oferecida.
     */
    public function test_toda_extensao_ativa_e_citada_e_nenhuma_inativa_se_passa_por_ativa(): void
    {
        $texto = self::texto();

        $ativas = array_map(
            static fn (array $language): string => (string) $language['extension'],
            self::ativas(),
        );

        // O documento cita extensoes em crase. Sem a crase nao conta, para
        // que uma palavra solta ("perl") nao seja confundida com a citacao
        // da entrada `perl`.
        preg_match_all('/`([a-z0-9_]+)`/', $texto, $encontradas);
        $citadas = array_unique($encontradas[1]);

        $inativas = array_map(
            static fn (array $language): string => (string) $language['extension'],
            array_filter(
                Language::getDefaultLanguages(),
                static fn (array $language): bool => ! ($language['is_active'] ?? false),
            ),
        );

        foreach ($ativas as $extension) {
            self::assertContains(
                $extension,
                $citadas,
                "A entrada ativa `{$extension}` nao esta citada no inventario.",
            );
        }

        foreach ($inativas as $extension) {
            if (in_array($extension, $ativas, true)) {
                continue;
            }

            if (! in_array($extension, $citadas, true)) {
                continue;
            }

            // Citar uma inativa e legitimo -- `erl` e `ex` tem secao
            // propria. O que nao pode e cita-la sem dizer que esta
            // desligada.
            self::assertMatchesRegularExpression(
                '/`'.preg_quote($extension, '/').'`[^\n]*(?:\n[^\n]*){0,6}(?:is_active => false|desativad|desligad|NAO podemos usar)/i',
                $texto,
                "A entrada `{$extension}` esta INATIVA no catalogo e o inventario a cita sem dizer isso.",
            );
        }
    }

    /**
     * Todo pino de `apk` escrito no documento existe de fato no
     * `Dockerfile.judge`. E o que impede o inventario de anunciar uma versao
     * que a imagem nao instala -- o modo de falha da #303, invertido.
     */
    public function test_nenhum_pino_citado_e_inventado(): void
    {
        $imagem = DockerfileToolchain::fromFile(self::raiz().'/Dockerfile.judge', self::raiz());
        $pacotes = $imagem->apkPackages();

        preg_match_all('/`([A-Za-z0-9_+.-]+)=([0-9][A-Za-z0-9_.+~-]*-r[0-9]+)`/', self::texto(), $matches, PREG_SET_ORDER);

        self::assertNotEmpty($matches, 'O inventario deveria citar os pinos de `apk`; nenhum foi encontrado.');

        foreach ($matches as [$citado, $pacote, $versao]) {
            self::assertArrayHasKey(
                $pacote,
                $pacotes,
                "O inventario cita {$citado}, e o estagio final do Dockerfile.judge nao instala `{$pacote}`.",
            );

            self::assertSame(
                $versao,
                $pacotes[$pacote],
                "O inventario cita {$citado} e o Dockerfile.judge fixa `{$pacote}={$pacotes[$pacote]}`.",
            );
        }
    }

    /**
     * O outro lado da mesma moeda: todo pacote `apk` que uma linguagem ATIVA
     * exige tem de estar escrito no inventario, com o pino.
     *
     * Sem esta metade, o teste acima seria satisfeito por um documento que
     * simplesmente nao cita pino nenhum.
     */
    public function test_todo_apk_de_linguagem_ativa_esta_no_inventario(): void
    {
        $texto = self::texto();
        $imagem = DockerfileToolchain::fromFile(self::raiz().'/Dockerfile.judge', self::raiz());
        $pacotes = $imagem->apkPackages();

        $exigidos = [];

        foreach (self::ativas() as $language) {
            foreach (ToolchainManifest::requirementsFor($language) as $requirement) {
                if ($requirement->kind === ToolchainRequirement::APK) {
                    $exigidos[$requirement->target] = true;
                }
            }
        }

        foreach (ToolchainManifest::shared() as $requirement) {
            if ($requirement->kind === ToolchainRequirement::APK) {
                $exigidos[$requirement->target] = true;
            }
        }

        ksort($exigidos);

        self::assertNotEmpty($exigidos);

        foreach (array_keys($exigidos) as $pacote) {
            $pino = $pacotes[$pacote] ?? null;

            self::assertNotNull(
                $pino,
                "`{$pacote}` e exigido por linguagem ativa e o Dockerfile.judge nao o fixa.",
            );

            self::assertStringContainsString(
                '`'.$pacote.'='.$pino.'`',
                $texto,
                "O inventario nao cita `{$pacote}={$pino}`, que uma linguagem ativa exige.",
            );
        }
    }

    /**
     * A contagem de pacotes `apk` que o documento anuncia -- 36 de linguagem
     * mais 2 de infraestrutura -- e a mesma que o manifesto resolve.
     */
    public function test_a_contagem_de_pacotes_apk_bate_com_o_manifesto(): void
    {
        $deLinguagem = [];

        foreach (self::ativas() as $language) {
            foreach (ToolchainManifest::requirementsFor($language) as $requirement) {
                if ($requirement->kind === ToolchainRequirement::APK) {
                    $deLinguagem[$requirement->target] = true;
                }
            }
        }

        $infra = [];

        foreach (ToolchainManifest::shared() as $requirement) {
            if ($requirement->kind === ToolchainRequirement::APK) {
                $infra[$requirement->target] = true;
            }
        }

        $texto = self::texto();

        self::assertMatchesRegularExpression(
            '/\*\*'.count($deLinguagem).'\*\* pacotes `apk` exigidos por linguagem ativa/',
            $texto,
            'A contagem de pacotes de linguagem ativa mudou; o inventario ainda diz outra.',
        );

        self::assertMatchesRegularExpression(
            '/\*\*'.count($infra).'\*\* de\s+infraestrutura/',
            $texto,
            'A contagem de pacotes de infraestrutura mudou; o inventario ainda diz outra.',
        );

        // E o total FIXADO, que e maior que os dois somados: o estagio final
        // fixa tambem `erlang27` e `elixir`, instalados de proposito e nao
        // oferecidos (#339). Sem esta asserção, um pacote novo fixado por
        // alguem que nao passou por aqui nao apareceria em lugar nenhum.
        $imagem = DockerfileToolchain::fromFile(self::raiz().'/Dockerfile.judge', self::raiz());
        $fixados = array_filter($imagem->apkPackages(), static fn (?string $pin): bool => $pin !== null);

        self::assertMatchesRegularExpression(
            '/\*\*'.count($fixados).'\*\* pacotes fixados no estágio final do `Dockerfile\.judge`/u',
            $texto,
            'O numero de pacotes fixados no Dockerfile.judge mudou; o inventario ainda diz outro.',
        );
    }

    /**
     * Os dois caminhos de julgamento, que sao a origem da #302 e da #352,
     * tem de continuar nomeados junto com o job de CI que constroi cada
     * imagem. Se um job for renomeado ou removido, o inventario passa a
     * prometer uma cobertura que nao existe.
     */
    public function test_os_dois_caminhos_citam_jobs_de_ci_que_existem(): void
    {
        $texto = self::texto();
        $ci = (string) file_get_contents(self::raiz().'/.github/workflows/ci.yml');

        foreach (['judge-image', 'app-image'] as $job) {
            self::assertStringContainsString(
                '`'.$job.'`',
                $texto,
                "O inventario deixou de nomear o job `{$job}`, que e quem constroi uma das duas imagens que julgam.",
            );

            self::assertMatchesRegularExpression(
                '/^\s{2}'.preg_quote($job, '/').':\s*$/m',
                $ci,
                "O inventario promete o job `{$job}`, e ele nao existe em .github/workflows/ci.yml.",
            );
        }
    }

    /**
     * A coluna "de onde vem" da tabela por linguagem e DERIVADA, e este e o
     * teste que impede que ela vire uma segunda lista escrita a mao.
     *
     * E a asserção mais cara deste arquivo e a que justifica ele existir:
     * sem ela, a tabela de 48 linhas seria exatamente o tipo de copia que a
     * #302 mostrou apodrecer -- e apodrecer em silencio, porque uma tabela
     * bonita nao tem cara de errada.
     */
    public function test_a_coluna_de_procedencia_e_exatamente_a_que_o_manifesto_resolve(): void
    {
        $texto = self::texto();
        $imagem = DockerfileToolchain::fromFile(self::raiz().'/Dockerfile.judge', self::raiz());

        foreach (self::ativas() as $language) {
            $extension = (string) $language['extension'];
            $esperado = self::procedenciaDe($language, $imagem);

            self::assertStringContainsString(
                '| `'.$extension.'` | '.$esperado.' |',
                $texto,
                "A linha de `{$extension}` no inventario nao diz o que o manifesto resolve.\n"
                ."Esperado no meio da linha: {$esperado}",
            );
        }
    }

    /**
     * A mesma renderizacao que a tabela do documento usa.
     *
     * @param  array<string, mixed>  $language
     */
    private static function procedenciaDe(array $language, DockerfileToolchain $imagem): string
    {
        $apk = $imagem->apkPackages();
        $npm = $imagem->npmPackages();
        $partes = [];

        foreach (ToolchainManifest::requirementsFor($language) as $requirement) {
            $alvo = $requirement->target;

            $partes[] = match ($requirement->kind) {
                ToolchainRequirement::APK => 'apk `'.$alvo.(($apk[$alvo] ?? null) !== null ? '='.$apk[$alvo] : '').'`',
                ToolchainRequirement::NPM => 'npm `'.$alvo.'@'.($npm[$alvo] ?? '?').'`',
                ToolchainRequirement::DOWNLOAD => 'baixado `'.$alvo.'_VERSION`',
                ToolchainRequirement::STAGE => 'estágio `'.$alvo.'`',
                ToolchainRequirement::INVOKER => 'invocador `'.$alvo.'`',
                ToolchainRequirement::REPO_FILE => 'repo `'.$alvo.'`',
                ToolchainRequirement::BASE_IMAGE => 'base `'.$alvo.'`',
                default => $requirement->kind.' `'.$alvo.'`',
            };
        }

        return implode(', ', array_unique($partes));
    }
}
