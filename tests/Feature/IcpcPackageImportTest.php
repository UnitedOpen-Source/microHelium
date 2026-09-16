<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Problem;
use App\Services\Icpc\IcpcPackageException;
use App\Services\Icpc\IcpcPackageImporter;
use App\Services\Icpc\IcpcPackageReader;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * Issue #200 -- importar o formato de pacote da ICPC/Kattis.
 *
 * E o que o resto do mundo produz e consome: o ICPC Problem Archive publica
 * nele, o Polygon (mais de 50 mil problemas preparados) exporta para ele, e
 * os requisitos de CCS o exigem como piso -- "The CCS MUST support the ICPC
 * subset". Ate aqui o importador lia SO o formato do BOCA, e um problema
 * preparado no Polygon precisava ser convertido a mao.
 *
 * O caso mais importante nestes testes e o do validador de saida: os dois
 * contratos nao se parecem, e ligar um no outro sem traduzir reprovaria
 * TODA submissao correta.
 */
class IcpcPackageImportTest extends TestCase
{
    private Contest $contest;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->running(10)->create();
        $this->workspace = storage_path('app/temp/icpc-test-'.uniqid());
        @mkdir($this->workspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);

        // O diretorio de problemas tambem, e isto NAO e zelo.
        //
        // RefreshDatabase zera o banco entre testes e nao zera o disco, e o
        // basename sai do nome do problema -- que e o mesmo em quase todos
        // estes testes. Sem esta limpeza, o `compare/default` escrito por um
        // teste com validador fica no lugar para o teste seguinte, que
        // afirma que ele NAO existe. O primeiro achado desta suite foi esse:
        // um teste passando por causa de arquivo deixado por outro.
        $this->removeTree(storage_path("app/problems/{$this->contest->id}"));

        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $path) {
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Monta um pacote no formato da ICPC em disco.
     *
     * @param  array<string, mixed>  $options
     */
    private function buildPackage(array $options = []): string
    {
        $root = $this->workspace.'/pacote-'.uniqid();

        foreach (['data/sample', 'data/secret', 'problem_statement', 'submissions/accepted'] as $dir) {
            @mkdir("{$root}/{$dir}", 0o755, true);
        }

        $yaml = $options['yaml'] ?? <<<'YAML'
problem_format_version: legacy-icpc
name: Soma de Dois Numeros
uuid: 8e1f0d2a-0000-4000-8000-000000000001
license: cc by-sa
limits:
    time_limit: 3
    memory: 512
validation: default
YAML;
        file_put_contents("{$root}/problem.yaml", $yaml);

        file_put_contents("{$root}/data/sample/01.in", "1 2\n");
        file_put_contents("{$root}/data/sample/01.ans", "3\n");

        // Dois casos secretos, um deles dentro de um GRUPO -- que e como o
        // formato organiza data/secret/<grupo>/.
        file_put_contents("{$root}/data/secret/01.in", "10 20\n");
        file_put_contents("{$root}/data/secret/01.ans", "30\n");
        @mkdir("{$root}/data/secret/grandes", 0o755, true);
        file_put_contents("{$root}/data/secret/grandes/01.in", "100 200\n");
        file_put_contents("{$root}/data/secret/grandes/01.ans", "300\n");

        file_put_contents("{$root}/problem_statement/problem.en.pdf", '%PDF-1.4 enunciado');
        file_put_contents("{$root}/submissions/accepted/solucao.cpp", "int main(){}\n");

        if ($options['with_validator'] ?? false) {
            @mkdir("{$root}/output_validators/checker", 0o755, true);
            file_put_contents("{$root}/output_validators/checker/run", $options['validator'] ?? "#!/bin/sh\nexit 42\n");
            chmod("{$root}/output_validators/checker/run", 0o755);
        }

        return $root;
    }

    private function zip(string $root): UploadedFile
    {
        $path = $this->workspace.'/pacote-'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), ltrim(str_replace($root, '', $file->getPathname()), '/'));
            }
        }

        $zip->close();

        return new UploadedFile($path, 'pacote.zip', 'application/zip', null, true);
    }

    private function import(array $options = []): Problem
    {
        return app(IcpcPackageImporter::class)->import($this->contest, $this->buildPackage($options));
    }

    // -- metadados ----------------------------------------------------------

    public function test_it_reads_the_metadata_from_problem_yaml(): void
    {
        $problem = $this->import();

        $this->assertSame('Soma de Dois Numeros', $problem->name);
        $this->assertSame(3, $problem->time_limit, 'limits.time_limit vem em segundos');
        $this->assertSame(512, $problem->memory_limit, 'limits.memory vem em MiB');
    }

    /**
     * `name` pode ser string ou mapa de idioma -- o formato permite os dois,
     * e um pacote do Polygon costuma usar o mapa.
     */
    public function test_a_localised_name_map_is_understood(): void
    {
        $problem = $this->import(['yaml' => <<<'YAML'
problem_format_version: legacy-icpc
name:
    en: Sum of Two Numbers
    pt_BR: Soma de Dois Numeros
limits:
    time_limit: 1
YAML]);

        $this->assertSame('Soma de Dois Numeros', $problem->name, 'portugues tem preferencia quando existe');
    }

    // -- casos de teste -----------------------------------------------------

    public function test_sample_and_secret_cases_are_imported_and_distinguished(): void
    {
        $problem = $this->import();
        $cases = $problem->testCases()->orderBy('number')->get();

        $this->assertCount(3, $cases, 'um de amostra e dois secretos');
        $this->assertTrue((bool) $cases[0]->is_sample);
        $this->assertFalse((bool) $cases[1]->is_sample);
        $this->assertFalse((bool) $cases[2]->is_sample);
    }

    /**
     * O importador do BOCA chutava "os dois primeiros sao amostra". O formato
     * da ICPC diz qual e qual, e um caso secreto marcado como amostra e
     * entrada escondida entregue a equipe.
     */
    public function test_the_sample_flag_comes_from_the_directory_and_not_from_a_guess(): void
    {
        $problem = $this->import();

        $this->assertSame(1, $problem->testCases()->where('is_sample', true)->count());
    }

    /**
     * `data/secret/<grupo>/` e como o formato organiza grupos. Pontuacao por
     * grupo esta fora de escopo, mas os ARQUIVOS precisam ser lidos: um
     * pacote agrupado importado sem descer nas subpastas viraria um problema
     * com menos casos -- e um problema com zero casos aceita tudo.
     */
    public function test_cases_inside_a_group_directory_are_not_lost(): void
    {
        $problem = $this->import();

        $this->assertSame(2, $problem->testCases()->where('is_sample', false)->count());
    }

    public function test_the_case_files_land_where_the_judge_looks_for_them(): void
    {
        $problem = $this->import();
        $case = $problem->testCases()->where('is_sample', false)->orderBy('number')->first();

        $this->assertFileExists(storage_path('app/'.$case->input_file));
        $this->assertFileExists(storage_path('app/'.$case->output_file));
        $this->assertSame(hash_file('sha256', storage_path('app/'.$case->input_file)), $case->input_hash);
    }

    public function test_a_package_with_no_secret_cases_is_refused(): void
    {
        $root = $this->buildPackage();
        $this->removeTree($root.'/data/secret');

        $this->expectException(IcpcPackageException::class);
        app(IcpcPackageImporter::class)->import($this->contest, $root);
    }

    // -- o validador de saida, que e o caso que importa ---------------------

    /**
     * Os dois contratos nao se parecem, e ligar um no outro sem traduzir
     * reprovaria TODA submissao correta: aqui qualquer saida diferente de
     * zero e "errado", e o codigo de ACEITO da ICPC e 42.
     */
    public function test_the_validator_shim_translates_accepted(): void
    {
        $problem = $this->import(['with_validator' => true, 'validator' => "#!/bin/sh\nexit 42\n"]);
        $shim = $problem->getPackagePath().'/compare/default';

        $this->assertFileExists($shim);

        exec($this->shimCall($problem, $shim), $output, $exit);

        $this->assertSame(0, $exit, '42 da ICPC tem que virar 0 aqui');
    }

    public function test_the_validator_shim_translates_wrong_answer(): void
    {
        $problem = $this->import(['with_validator' => true, 'validator' => "#!/bin/sh\nexit 43\n"]);

        exec($this->shimCall($problem, $problem->getPackagePath().'/compare/default'), $output, $exit);

        $this->assertSame(1, $exit, '43 da ICPC tem que virar diferente de zero');
    }

    /**
     * Um validador que quebrou nao e um veredito sobre a equipe. Reprova-la
     * por um defeito nosso e a mesma injustica que o #45 evita ao nao
     * pontuar o CS que ele proprio cria.
     */
    public function test_a_broken_validator_is_not_reported_as_a_wrong_answer(): void
    {
        $problem = $this->import(['with_validator' => true, 'validator' => "#!/bin/sh\nexit 7\n"]);

        exec($this->shimCall($problem, $problem->getPackagePath().'/compare/default').' 2>/dev/null', $output, $exit);

        $this->assertSame(2, $exit, 'codigo inesperado do validador nao pode virar "errado"');
    }

    /**
     * O bit de execucao tem que sobreviver a copia: um `run` sem ele faz o
     * shim falhar em todo caso de teste, e o problema inteiro vira erro de
     * julgamento.
     */
    public function test_the_validator_keeps_its_execute_bit(): void
    {
        $problem = $this->import(['with_validator' => true]);

        $this->assertTrue(is_executable($problem->getPackagePath().'/compare/validator/run'));
    }

    public function test_a_package_without_a_validator_writes_no_shim(): void
    {
        $problem = $this->import();

        $this->assertFileDoesNotExist($problem->getPackagePath().'/compare/default');
    }

    private function shimCall(Problem $problem, string $shim): string
    {
        $dir = $problem->getPackagePath();
        file_put_contents("{$dir}/obtido.txt", "3\n");

        return sprintf(
            'bash %s %s %s %s',
            escapeshellarg($shim),
            escapeshellarg("{$dir}/input/001"),
            escapeshellarg("{$dir}/output/001"),
            escapeshellarg("{$dir}/obtido.txt")
        );
    }

    // -- enunciado e solucoes -----------------------------------------------

    public function test_the_statement_is_imported(): void
    {
        $problem = $this->import();

        $this->assertSame('problem.en.pdf', $problem->description_file);
        $this->assertFileExists($problem->getPackagePath().'/description/problem.en.pdf');
    }

    /**
     * Guardadas mesmo sem uso imediato: sao o pre-requisito do #196, que
     * quer medir o limite de tempo em vez de aceitar um numero digitado.
     */
    public function test_accepted_reference_solutions_are_kept(): void
    {
        $problem = $this->import();

        $this->assertFileExists($problem->getPackagePath().'/submissions/accepted/solucao.cpp');
    }

    // -- versao e escopo ----------------------------------------------------

    /**
     * A 2025-09 RENOMEIA diretorios (problem_statement/ -> statement/,
     * output_validators/ -> output_validator/). Ler um pacote 2025-09 com as
     * regras do legacy nao da erro: da um problema sem enunciado e sem
     * validador, EM SILENCIO. Por isso a versao e recusada em voz alta.
     */
    public function test_an_unsupported_format_version_is_refused_out_loud(): void
    {
        $this->expectException(IcpcPackageException::class);
        $this->expectExceptionMessageMatches('/2025-09/');

        $this->import(['yaml' => "problem_format_version: '2025-09'\nname: X\n"]);
    }

    public function test_an_interactive_problem_is_refused_instead_of_half_imported(): void
    {
        $this->expectException(IcpcPackageException::class);

        $this->import(['yaml' => "problem_format_version: legacy-icpc\nname: X\ntype: interactive\n"]);
    }

    public function test_a_package_without_problem_yaml_is_not_an_icpc_package(): void
    {
        $root = $this->workspace.'/vazio';
        @mkdir($root, 0o755, true);

        $this->expectException(IcpcPackageException::class);
        app(IcpcPackageReader::class)->read($root);
    }

    // -- a porta ------------------------------------------------------------

    /**
     * Um endpoint, dois formatos. A deteccao e pelo CONTEUDO: quem exportou
     * do Polygon nao sabe -- nem deveria precisar saber -- qual dos dois
     * formatos esta plataforma chama de nativo.
     */
    public function test_the_endpoint_imports_an_icpc_package(): void
    {
        Sanctum::actingAs($this->createTestUser(['user_type' => 'admin', 'contest_id' => $this->contest->id]));

        $body = $this->postJson('/api/problems', [
            'contest_id' => $this->contest->id,
            'package' => $this->zip($this->buildPackage()),
        ])->assertStatus(201)->json();

        $this->assertSame('Soma de Dois Numeros', $body['name']);
        $this->assertCount(3, $body['test_cases']);
    }

    public function test_the_endpoint_refuses_a_bad_package_with_a_readable_reason(): void
    {
        Sanctum::actingAs($this->createTestUser(['user_type' => 'admin', 'contest_id' => $this->contest->id]));

        $this->postJson('/api/problems', [
            'contest_id' => $this->contest->id,
            'package' => $this->zip($this->buildPackage(['yaml' => "problem_format_version: '2025-09'\nname: X\n"])),
        ])->assertStatus(422);
    }

    /**
     * Um pacote do formato do BOCA continua sendo importado pelo caminho de
     * sempre -- esta issue ACRESCENTA um formato, nao troca o interno.
     */
    public function test_a_boca_package_still_goes_through_the_old_path(): void
    {
        $root = $this->workspace.'/boca';
        @mkdir("{$root}/description", 0o755, true);
        @mkdir("{$root}/input", 0o755, true);
        @mkdir("{$root}/output", 0o755, true);
        file_put_contents("{$root}/description/problem.info", "basename=somaboca\nfullname=Soma BOCA\n");
        file_put_contents("{$root}/input/01", "1 2\n");
        file_put_contents("{$root}/output/01", "3\n");

        Sanctum::actingAs($this->createTestUser(['user_type' => 'admin', 'contest_id' => $this->contest->id]));

        $body = $this->postJson('/api/problems', [
            'contest_id' => $this->contest->id,
            'package' => $this->zip($root),
        ])->assertStatus(201)->json();

        $this->assertSame('Soma BOCA', $body['name']);
    }
}
