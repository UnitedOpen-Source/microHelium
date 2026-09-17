<?php

namespace Tests\Feature\Icpc;

use App\Services\Icpc\IcpcPackageException;
use App\Services\Icpc\IcpcPackageReader;
use Tests\TestCase;

/**
 * Issue #251 -- de onde sai o limite de tempo de um pacote LEGACY.
 *
 * `IcpcPackageReader` lia `limits.time_limit`, com `?? 1` quando ausente. Mas
 * esse leitor so aceita `legacy` e `legacy-icpc` -- ele RECUSA a 2025-09, e
 * recusa por um motivo escrito com cuidado no proprio docblock: ler um pacote
 * de uma versao com as regras de outra "nao da erro, da um problema sem
 * enunciado e sem validador, em silencio".
 *
 * E `limits.time_limit` e chave da 2025-09. No formato legacy ela NAO EXISTE:
 * a especificacao legacy tem `time_multiplier` (padrao 5) e
 * `time_safety_margin` (padrao 2), e o limite e derivado das solucoes de
 * referencia. Ou seja, o mesmo erro que o docblock se preocupa em evitar
 * estava acontecendo na direcao contraria, e todo pacote legacy de verdade
 * caia no `?? 1`: um segundo, em silencio, para qualquer problema.
 *
 * Um segundo reprova por tempo quase toda solucao correta. E o defeito nao
 * aparece na importacao -- aparece na prova.
 *
 * Onde o limite mora de verdade num pacote legacy, em ordem de precedencia:
 *
 *  1. `domjudge-problem.ini`, chave `timelimit`. E o que a documentacao do
 *     DOMjudge descreve: "timelimit - time limit in seconds per test case".
 *  2. o arquivo `.timelimit` na raiz -- convencao do problemtools, nao
 *     oficial, mas produzida por boa parte das ferramentas.
 *  3. `limits.time_limit`, tolerado: nao e do legacy, mas exportadores
 *     emitem mesmo assim, e recusar um pacote que DIZ o limite seria trocar
 *     um silencio por uma teimosia.
 *
 * Sem nenhum dos tres, o limite teria que ser DERIVADO -- e derivar e a fase
 * 2 do #196, que nao existe (ver docs/specs/251-limite-de-tempo-derivado.md).
 * Entao a importacao e recusada em voz alta, como ja acontece com problema
 * interativo e com versao de formato nao suportada.
 */
class LegacyPackageTimeLimitTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = storage_path('app/temp/legacy-tl-'.uniqid());
        @mkdir($this->workspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    /**
     * Um pacote legacy minimo e valido. `limits:` vem SEM `time_limit`, que e
     * o caso normal do formato -- a chave nao existe nele.
     *
     * @param  array<string, string>  $extras  caminho relativo => conteudo
     */
    private function package(array $extras = [], ?string $yaml = null): string
    {
        $root = $this->workspace.'/pkg-'.uniqid();

        foreach (['data/sample', 'data/secret', 'problem_statement', 'submissions/accepted'] as $dir) {
            @mkdir("{$root}/{$dir}", 0o755, true);
        }

        file_put_contents("{$root}/problem.yaml", $yaml ?? <<<'YAML'
problem_format_version: legacy-icpc
name: Soma de Dois Numeros
license: cc by-sa
limits:
    memory: 512
validation: default
YAML);

        file_put_contents("{$root}/data/secret/01.in", "10 20\n");
        file_put_contents("{$root}/data/secret/01.ans", "30\n");
        file_put_contents("{$root}/problem_statement/problem.en.pdf", '%PDF-1.4');
        file_put_contents("{$root}/submissions/accepted/sol.cpp", "int main(){}\n");

        foreach ($extras as $caminho => $conteudo) {
            @mkdir(dirname("{$root}/{$caminho}"), 0o755, true);
            file_put_contents("{$root}/{$caminho}", $conteudo);
        }

        return $root;
    }

    private function read(string $root): array
    {
        return app(IcpcPackageReader::class)->read($root);
    }

    public function test_a_legacy_package_that_states_no_time_limit_is_refused_instead_of_silently_getting_one_second(): void
    {
        $this->expectException(IcpcPackageException::class);
        $this->expectExceptionMessageMatches('/limite de tempo/i');

        $this->read($this->package());
    }

    public function test_the_time_limit_comes_from_domjudge_problem_ini(): void
    {
        $pacote = $this->package(['domjudge-problem.ini' => "name = Soma\ntimelimit = 5\n"]);

        $this->assertSame(5, $this->read($pacote)['time_limit']);
    }

    public function test_the_time_limit_comes_from_the_timelimit_file(): void
    {
        $pacote = $this->package(['.timelimit' => "4\n"]);

        $this->assertSame(4, $this->read($pacote)['time_limit']);
    }

    /**
     * O DOMjudge le o `.timelimit` "if it exists and is not overwritten in
     * domjudge-problem.ini" -- entao o ini ganha.
     */
    public function test_the_ini_wins_over_the_timelimit_file(): void
    {
        $pacote = $this->package([
            '.timelimit' => "4\n",
            'domjudge-problem.ini' => "timelimit = 7\n",
        ]);

        $this->assertSame(7, $this->read($pacote)['time_limit']);
    }

    /**
     * Tolerado, embora nao seja chave do legacy: exportadores emitem, e um
     * pacote que DIZ o limite nao deve ser recusado.
     */
    public function test_limits_time_limit_is_still_honoured_when_a_package_carries_it(): void
    {
        $pacote = $this->package(yaml: <<<'YAML'
problem_format_version: legacy-icpc
name: Soma
limits:
    time_limit: 3
    memory: 512
YAML);

        $this->assertSame(3, $this->read($pacote)['time_limit']);
    }

    /**
     * Fracionario arredonda para CIMA. A coluna e inteira, e arredondar para
     * baixo aperta o limite: reprovaria por tempo uma solucao que o pacote
     * considera correta.
     */
    public function test_a_fractional_limit_rounds_up_and_never_down(): void
    {
        $pacote = $this->package(['.timelimit' => "2.4\n"]);

        $this->assertSame(3, $this->read($pacote)['time_limit']);
    }
}
