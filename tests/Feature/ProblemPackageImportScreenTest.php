<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Problem;
use Helium\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * Issue #200/#233 -- a tela de importação de pacote ICPC/Kattis.
 *
 * O leitor e o importador existem desde o #200 e só eram alcançáveis por
 * chamada manual. A issue #233 pede a tela, e pede uma coisa em particular:
 * "não apontar o formulário BOCA de importação do banco para um endpoint que
 * importa problemas de competição com outro contrato". São dois destinos
 * diferentes -- o BOCA traz uma competição inteira para o BANCO, este traz um
 * problema para UMA COMPETIÇÃO -- e trocar um pelo outro entregaria em
 * silêncio a coisa errada.
 *
 * O caso que mais importa aqui é o da conferência sem gravar: um diagnóstico
 * que só existe DEPOIS de importar chega tarde, porque o problema errado já
 * está na prova.
 */
class ProblemPackageImportScreenTest extends TestCase
{
    private Contest $contest;

    private User $admin;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->notStarted()->create(['name' => 'Regional 2026']);
        $this->admin = $this->createAdminUser();
        $this->workspace = storage_path('app/temp/import-screen-'.uniqid());
        @mkdir($this->workspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);

        // RefreshDatabase zera o banco e não zera o disco, e o importador
        // grava em app/problems/<contest>. Sem isto, arquivo deixado por um
        // teste responde pelo seguinte.
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
     * Monta um pacote ICPC em disco e devolve o ZIP como upload.
     *
     * @param  array<string, mixed>  $options
     */
    private function pacote(array $options = []): UploadedFile
    {
        $root = $this->workspace.'/pacote-'.uniqid();

        foreach (['data/sample', 'data/secret', 'problem_statement', 'submissions/accepted'] as $dir) {
            @mkdir("{$root}/{$dir}", 0o755, true);
        }

        file_put_contents("{$root}/problem.yaml", $options['yaml'] ?? <<<'YAML'
problem_format_version: legacy-icpc
name: Soma de Dois Numeros
license: cc by-sa
limits:
    time_limit: 3
    memory: 512
validation: default
YAML);

        file_put_contents("{$root}/data/sample/01.in", "1 2\n");
        file_put_contents("{$root}/data/sample/01.ans", "3\n");
        file_put_contents("{$root}/data/secret/01.in", "10 20\n");
        file_put_contents("{$root}/data/secret/01.ans", "30\n");
        file_put_contents("{$root}/data/secret/02.in", "5 5\n");
        file_put_contents("{$root}/data/secret/02.ans", "10\n");

        if ($options['statement'] ?? true) {
            file_put_contents("{$root}/problem_statement/problem.en.pdf", '%PDF-1.4 enunciado');
        }

        file_put_contents("{$root}/submissions/accepted/solucao.cpp", "int main(){}\n");

        if ($options['with_validator'] ?? false) {
            @mkdir("{$root}/output_validators/checker", 0o755, true);
            file_put_contents("{$root}/output_validators/checker/run", "#!/bin/sh\nexit 42\n");
            chmod("{$root}/output_validators/checker/run", 0o755);
        }

        $path = $this->workspace.'/pacote-'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), ltrim(str_replace($root, '', $file->getPathname()), '/'));
            }
        }

        $zip->close();

        return new UploadedFile($path, 'pacote.zip', 'application/zip', null, true);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $campos
     */
    private function enviar(array $options = [], array $campos = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('backend.import-package'))
            ->post(route('backend.import-package.store'), array_merge([
                'contest_id' => $this->contest->id,
                'package' => $this->pacote($options),
            ], $campos));
    }

    /**
     * O trecho <select> das competições de destino, sozinho.
     */
    private function opcoesDoSeletor(): string
    {
        $html = $this->actingAs($this->admin)->get(route('backend.import-package'))->assertOk()->getContent();

        preg_match('#<select id="import-contest".*?</select>#s', $html, $m);

        // Controle positivo: sem isto, um seletor renomeado deixaria $m vazio
        // e TODA asserção de "não aparece" passaria por falta de texto.
        $this->assertNotEmpty($m, 'o seletor de competição sumiu da tela');

        return $m[0];
    }

    // -- acesso -------------------------------------------------------------

    public function test_only_an_admin_reaches_the_screen(): void
    {
        $this->get(route('backend.import-package'))->assertRedirect();

        $this->actingAs($this->createTestUser(['user_type' => 'team']))
            ->get(route('backend.import-package'))
            ->assertForbidden();

        $this->actingAs($this->admin)->get(route('backend.import-package'))->assertOk();
    }

    /**
     * Uma tela sem porta de entrada é uma tela que não existe. O caminho é a
     * gestão de problemas da competição, que é de onde alguém vem querendo
     * pôr um problema numa prova.
     */
    public function test_the_screen_is_reachable_from_the_contest_problems_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('backend.exercises'))
            ->assertOk()
            ->assertSee(route('backend.import-package'));
    }

    public function test_the_practice_contest_is_not_offered_and_is_refused(): void
    {
        $treino = Contest::factory()->create(['is_practice' => true, 'name' => 'Treino Livre']);

        // A comparação é com o SELETOR, não com a página: o menu lateral tem
        // um link fixo para a prova de treino, e um assertDontSee na página
        // inteira passaria a falhar por causa dele -- ou, pior, esconderia o
        // que se quer medir se o link mudasse de nome.
        $opcoes = $this->opcoesDoSeletor();

        $this->assertStringContainsString('Regional 2026', $opcoes);
        $this->assertStringNotContainsString('Treino Livre', $opcoes);

        // E não só escondida no seletor: quem montar o POST à mão também é
        // recusado. Os problemas da prova de treino são instantâneos
        // versionados do PracticePublisher (#43); um posto à mão diverge da
        // biblioteca e não volta sozinho.
        $this->enviar(campos: ['contest_id' => $treino->id])->assertNotFound();

        $this->assertSame(0, Problem::where('contest_id', $treino->id)->count());
    }

    // -- o formulário não é o do BOCA ---------------------------------------

    /**
     * A issue nomeia este erro: reapontar o formulário do BOCA para cá. Os
     * dois contratos não se parecem -- `boca_zip` para um ZIP de competição
     * inteira, contra `package` + `contest_id` para um problema -- e a rota
     * do BOCA continua sendo a do BOCA.
     */
    public function test_the_boca_form_still_posts_to_the_boca_endpoint(): void
    {
        $html = $this->actingAs($this->admin)->get(route('backend.import-boca'))->assertOk()->getContent();

        $this->assertStringContainsString('/backend/import-boca/upload', $html);
        $this->assertStringNotContainsString(route('backend.import-package.store'), $html);
    }

    // -- diagnóstico ---------------------------------------------------------

    public function test_the_dry_run_reports_the_package_without_writing_anything(): void
    {
        $resposta = $this->enviar(['with_validator' => true], ['dry_run' => '1'])
            ->assertRedirect(route('backend.import-package'));

        $this->assertSame(0, Problem::where('contest_id', $this->contest->id)->count(), 'conferir não pode gravar');
        $this->assertFalse(is_dir(storage_path("app/problems/{$this->contest->id}")), 'nem em disco');

        $resposta->assertSessionHas('diagnostico', function (array $d) {
            $this->assertFalse($d['importado']);
            $this->assertSame('Soma de Dois Numeros', $d['nome']);
            $this->assertSame('legacy-icpc', $d['versao']);
            $this->assertSame(3, $d['time_limit']);
            $this->assertSame(512, $d['memory_limit']);
            $this->assertSame(1, $d['amostras']);
            $this->assertSame(2, $d['secretos']);
            $this->assertTrue($d['validador']);
            $this->assertTrue($d['enunciado']);
            $this->assertSame(1, $d['solucoes']);

            return true;
        });

        // E os números chegam à tela, não só à sessão.
        $this->actingAs($this->admin)
            ->get(route('backend.import-package'))
            ->assertSee('Conferência do pacote')
            ->assertSee('Nada foi gravado', false)
            ->assertSee('validador de saída', false);
    }

    /**
     * Um pacote sem validador próprio é julgado por comparação padrão, e um
     * com validador é julgado pelo programa do pacote. Quem importa precisa
     * saber qual dos dois vai acontecer ANTES de aceitar.
     */
    public function test_the_diagnosis_distinguishes_a_package_without_a_validator(): void
    {
        $this->enviar([], ['dry_run' => '1'])
            ->assertSessionHas('diagnostico', fn (array $d) => $d['validador'] === false);

        $this->actingAs($this->admin)
            ->get(route('backend.import-package'))
            ->assertSee('Sem validador próprio', false);
    }

    public function test_a_package_without_a_statement_is_called_out(): void
    {
        $this->enviar(['statement' => false], ['dry_run' => '1'])
            ->assertSessionHas('diagnostico', fn (array $d) => $d['enunciado'] === false);

        $this->actingAs($this->admin)
            ->get(route('backend.import-package'))
            ->assertSee('não traz enunciado', false);
    }

    // -- recusas legíveis ----------------------------------------------------

    /**
     * A 2025-09 renomeia diretórios. Lê-la com as regras anteriores não daria
     * erro: daria um problema sem enunciado e sem validador, em silêncio. A
     * recusa precisa chegar como frase, não como 500.
     */
    public function test_an_unsupported_format_version_comes_back_as_a_readable_refusal(): void
    {
        $this->enviar(['yaml' => <<<'YAML'
problem_format_version: 2025-09
name: Problema Novo
limits:
    time_limit: 1
YAML])
            ->assertRedirect(route('backend.import-package'))
            ->assertSessionHas('error', fn (string $erro) => str_contains($erro, '2025-09'))
            ->assertSessionMissing('diagnostico');

        $this->assertSame(0, Problem::where('contest_id', $this->contest->id)->count());
    }

    public function test_a_package_that_is_not_a_zip_is_refused_by_validation(): void
    {
        $this->actingAs($this->admin)
            ->from(route('backend.import-package'))
            ->post(route('backend.import-package.store'), [
                'contest_id' => $this->contest->id,
                'package' => UploadedFile::fake()->create('nao-e-pacote.txt', 4, 'text/plain'),
            ])
            ->assertRedirect(route('backend.import-package'))
            ->assertSessionHasErrors('package');
    }

    // -- importação ----------------------------------------------------------

    public function test_a_successful_import_creates_the_problem_and_reports_it(): void
    {
        $resposta = $this->enviar()->assertRedirect(route('backend.import-package'));

        $problema = Problem::where('contest_id', $this->contest->id)->firstOrFail();

        $this->assertSame('Soma de Dois Numeros', $problema->name);
        $this->assertSame(3, $problema->time_limit);
        $this->assertSame(512, $problema->memory_limit);
        $this->assertSame(3, $problema->testCases()->count(), 'uma amostra e dois secretos');

        $resposta->assertSessionHas('diagnostico', function (array $d) use ($problema) {
            $this->assertTrue($d['importado']);
            $this->assertStringContainsString($problema->short_name, $d['problema']);

            return true;
        });

        $this->actingAs($this->admin)
            ->get(route('backend.import-package'))
            ->assertSee('Pacote importado')
            ->assertSee($problema->short_name);
    }

    public function test_the_requested_letter_is_honoured(): void
    {
        $this->enviar(campos: ['short_name' => 'K'])->assertRedirect();

        $this->assertSame('K', Problem::where('contest_id', $this->contest->id)->firstOrFail()->short_name);
    }

    /**
     * O log da competição é o que sobra depois da prova. Uma importação feita
     * com a prova andando muda o que as equipes veem, e precisa ter autor e
     * hora registrados.
     */
    public function test_the_import_is_written_to_the_contest_log(): void
    {
        $this->enviar();

        $this->assertDatabaseHas('contest_logs', [
            'contest_id' => $this->contest->id,
        ]);

        $log = ContestLog::where('contest_id', $this->contest->id)->latest('id')->firstOrFail();

        $this->assertSame('icpc_package_imported', $log->context['event']);
        $this->assertSame($this->admin->user_id, $log->context['user_id']);
        $this->assertSame('legacy-icpc', $log->context['format_version']);
    }

    /**
     * O diretório temporário de extração não pode sobreviver à requisição --
     * nem quando a leitura falha. Um pacote recusado deixando o ZIP aberto em
     * storage/ enche o disco da máquina que serve a prova.
     */
    public function test_the_extracted_files_do_not_survive_the_request(): void
    {
        $antes = glob(storage_path('app/temp/import_*')) ?: [];

        $this->enviar();
        $this->enviar(['yaml' => "problem_format_version: 2025-09\nname: X\n"]);

        $this->assertSame($antes, glob(storage_path('app/temp/import_*')) ?: []);
    }
}
