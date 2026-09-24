<?php

namespace Tests\Feature;

use App\Models\CurriculumFramework;
use App\Models\CurriculumOutcome;
use App\Models\ProblemBank;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Issue #396 -- `curriculum:import` e a carga da BNCC Computação
 * (docs/specs/396-curriculos-oficiais.md).
 */
class CurriculumImportCommandTest extends TestCase
{
    private const BNCC = 'database/curricula/bncc-computacao-2022.csv';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/curriculum-import-'.$this->uniqueSuffix();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function manifest(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'curriculo-teste',
            'name' => 'Currículo de teste',
            'jurisdiction' => 'BR',
            'version' => '1',
            'locale' => 'pt_BR',
            'source_url' => 'https://example.org/curriculo.pdf',
            'source_consulted_at' => '2026-09-24',
        ], $overrides);
    }

    /** Escreve o par CSV + manifesto e devolve o caminho do CSV. */
    private function files(string $csv, ?array $manifest = null, string $name = 'teste'): string
    {
        file_put_contents("{$this->dir}/{$name}.csv", $csv);
        file_put_contents("{$this->dir}/{$name}.json", json_encode($manifest ?? $this->manifest()));

        return "{$this->dir}/{$name}.csv";
    }

    private function assertRefused(string $path, string $expected): void
    {
        $this->artisan('curriculum:import', ['file' => $path])
            ->expectsOutputToContain('Importação recusada; nada foi gravado.')
            ->expectsOutputToContain($expected)
            ->assertFailed();

        $this->assertSame(0, CurriculumFramework::count(), 'nada pode ser gravado quando o arquivo é recusado');
        $this->assertSame(0, CurriculumOutcome::count());
    }

    public function test_imports_the_bncc_computacao_as_published(): void
    {
        $this->artisan('curriculum:import', ['file' => self::BNCC])
            ->expectsOutputToContain('criadas: 141, atualizadas: 0, inalteradas: 0')
            ->assertSuccessful();

        $framework = CurriculumFramework::where('slug', 'bncc-computacao')->firstOrFail();
        $this->assertSame('BR', $framework->jurisdiction);
        $this->assertSame('2022', $framework->version);
        $this->assertSame('pt_BR', $framework->locale);
        $this->assertSame('https://basenacionalcomum.mec.gov.br/images/historico/anexo_parecer_cneceb_n_2_2022_bncc_computacao.pdf', $framework->source_url);
        $this->assertSame('2026-09-24', $framework->source_consulted_at->toDateString());
        $this->assertSame(64, strlen((string) $framework->source_sha256));
        $this->assertSame(141, $framework->outcomes()->count());

        $byStage = $framework->outcomes()->get()->countBy('stage')->all();
        $this->assertSame([
            'Educação Infantil' => 11,
            '1º ano' => 7,
            '2º ano' => 6,
            '3º ano' => 9,
            '4º ano' => 8,
            '5º ano' => 11,
            '1º ao 5º ano' => 9,
            '6º ano' => 10,
            '7º ano' => 11,
            '8º ano' => 11,
            '9º ano' => 10,
            '6º ao 9º ano' => 12,
            'Ensino Médio' => 26,
        ], $byStage);

        $ef06co02 = $framework->outcomes()->where('code', 'EF06CO02')->firstOrFail();
        $this->assertSame('Elaborar algoritmos que envolvam instruções sequenciais, de repetição e de seleção usando uma linguagem de programação.', $ef06co02->text);
        $this->assertSame('Pensamento Computacional', $ef06co02->axis);

        // Impresso assim no anexo oficial; não é corrigido para EF05CO11.
        $this->assertTrue($framework->outcomes()->where('code', 'EF05CO011')->exists());
        $this->assertFalse($framework->outcomes()->where('code', 'EF05CO11')->exists());

        // O Ensino Médio é por competência específica, não por eixo.
        $this->assertSame(0, $framework->outcomes()->where('stage', 'Ensino Médio')->whereNotNull('axis')->count());
        $this->assertEqualsCanonicalizing(
            ['Cultura Digital', 'Mundo Digital', 'Pensamento Computacional'],
            $framework->outcomes()->whereNotNull('axis')->pluck('axis')->unique()->values()->all(),
        );

        // A ordem do documento: Infantil primeiro, Médio por último.
        $this->assertSame('EI03CO01', $framework->outcomes()->first()->code);
        $this->assertSame('EM13CO26', $framework->outcomes()->get()->last()->code);
    }

    public function test_reimporting_the_same_file_changes_nothing(): void
    {
        $this->artisan('curriculum:import', ['file' => self::BNCC])->assertSuccessful();
        $before = CurriculumOutcome::orderBy('id')->get(['id', 'code', 'text', 'updated_at'])->toArray();

        $this->artisan('curriculum:import', ['file' => self::BNCC])
            ->expectsOutputToContain('criadas: 0, atualizadas: 0, inalteradas: 141')
            ->assertSuccessful();

        $this->assertSame(1, CurriculumFramework::count());
        $this->assertSame($before, CurriculumOutcome::orderBy('id')->get(['id', 'code', 'text', 'updated_at'])->toArray());
    }

    public function test_reimport_updates_changed_rows_in_place_and_keeps_problem_links(): void
    {
        $path = $this->files("code,stage,axis,text\nA1,1º ano,Eixo,Texto original.\nA2,1º ano,,Outro.\n");
        $this->artisan('curriculum:import', ['file' => $path])->assertSuccessful();

        $a1 = CurriculumOutcome::where('code', 'A1')->firstOrFail();
        $bank = ProblemBank::factory()->create();
        $bank->outcomes()->attach($a1);

        $this->files("code,stage,axis,text\nA1,2º ano,Eixo,Texto revisado.\nA2,1º ano,,Outro.\n", $this->manifest(['version' => '2']));
        $this->artisan('curriculum:import', ['file' => $path])
            ->expectsOutputToContain('criadas: 0, atualizadas: 1, inalteradas: 1')
            ->assertSuccessful();

        $a1->refresh();
        $this->assertSame('Texto revisado.', $a1->text);
        $this->assertSame('2º ano', $a1->stage);
        $this->assertSame('2', CurriculumFramework::firstOrFail()->version);
        $this->assertSame(2, CurriculumOutcome::count());
        $this->assertSame([$a1->id], $bank->outcomes()->pluck('curriculum_outcomes.id')->all());
    }

    public function test_outcome_missing_from_the_csv_is_kept_and_reported(): void
    {
        $path = $this->files("code,text\nA1,Um.\nA2,Dois.\n");
        $this->artisan('curriculum:import', ['file' => $path])->assertSuccessful();

        $this->files("code,text\nA1,Um.\n");
        $this->artisan('curriculum:import', ['file' => $path])
            ->expectsOutputToContain('1 habilidade(s) existem no banco e não estão no CSV; foram mantidas: A2')
            ->assertSuccessful();

        $this->assertTrue(CurriculumOutcome::where('code', 'A2')->exists());
    }

    public function test_header_may_reorder_columns_and_omit_the_optional_ones_and_carry_a_bom(): void
    {
        $path = $this->files("\xEF\xBB\xBFtext,code\n\"Com vírgula, e \"\"aspas\"\".\",X-1\n\n");
        $this->artisan('curriculum:import', ['file' => $path])->assertSuccessful();

        $outcome = CurriculumOutcome::where('code', 'X-1')->firstOrFail();
        $this->assertSame('Com vírgula, e "aspas".', $outcome->text);
        $this->assertNull($outcome->stage);
        $this->assertNull($outcome->axis);
        $this->assertSame(1, $outcome->position);
    }

    public function test_row_with_wrong_column_count_is_refused(): void
    {
        $this->assertRefused($this->files("code,stage,axis,text\nA1,1º ano,Eixo,Um.\nA2,1º ano,Dois.\n"), 'Linha 3: 3 colunas, o cabeçalho tem 4');
    }

    public function test_duplicate_code_is_refused_even_when_the_first_rows_are_valid(): void
    {
        $this->assertRefused($this->files("code,text\nA1,Um.\nA2,Dois.\nA1,Três.\n"), "Linha 4: code 'A1' repetido (já aparece na linha 2)");
    }

    public function test_empty_code_and_empty_text_are_refused(): void
    {
        $path = $this->files("code,text\n,Sem código.\nA2,  \n");
        $this->artisan('curriculum:import', ['file' => $path])
            ->expectsOutputToContain('Linha 2: code vazio')
            ->expectsOutputToContain('Linha 3: text vazio')
            ->assertFailed();
        $this->assertSame(0, CurriculumOutcome::count());
    }

    public function test_code_outside_the_allowed_alphabet_is_refused(): void
    {
        $this->assertRefused($this->files("code,text\nEF06 CO02,Um.\n"), "Linha 2: code 'EF06 CO02' inválido");
    }

    public function test_invalid_utf8_is_refused(): void
    {
        $this->assertRefused($this->files("code,text\nA1,Programa\xE7\xE3o\n"), 'Linha 2: texto não é UTF-8 válido');
    }

    public function test_unknown_or_missing_header_columns_are_refused(): void
    {
        $this->assertRefused($this->files("codigo,texto\nA1,Um.\n"), "Cabeçalho: coluna desconhecida: 'codigo'");
        $this->assertRefused($this->files("code,stage\nA1,1º ano\n"), 'Cabeçalho: coluna obrigatória ausente: text');
        $this->assertRefused($this->files("code,text,text\nA1,Um.,Dois.\n"), 'Cabeçalho: coluna repetida: text');
    }

    public function test_empty_file_and_header_only_file_are_refused(): void
    {
        $this->assertRefused($this->files(''), 'CSV vazio');
        $this->assertRefused($this->files("code,stage,axis,text\n"), 'CSV sem nenhuma habilidade.');
    }

    public function test_missing_or_invalid_manifest_is_refused(): void
    {
        file_put_contents("{$this->dir}/sem-manifesto.csv", "code,text\nA1,Um.\n");
        $this->assertRefused("{$this->dir}/sem-manifesto.csv", 'Manifesto não encontrado');

        $this->assertRefused($this->files("code,text\nA1,Um.\n", ['slug' => 'x']), 'Manifesto: campo obrigatório ausente ou vazio: source_url');
        $this->assertRefused($this->files("code,text\nA1,Um.\n", $this->manifest(['source_consulted_at' => '24/09/2026'])), 'source_consulted_at deve ser uma data AAAA-MM-DD');
        $this->assertRefused($this->files("code,text\nA1,Um.\n", $this->manifest(['source_url' => 'ftp://example.org/x'])), 'source_url deve ser uma URL http(s)');

        file_put_contents("{$this->dir}/json-quebrado.csv", "code,text\nA1,Um.\n");
        file_put_contents("{$this->dir}/json-quebrado.json", '{"slug": ');
        $this->assertRefused("{$this->dir}/json-quebrado.csv", 'Manifesto não é um objeto JSON válido');
    }

    public function test_missing_csv_is_refused(): void
    {
        $this->assertRefused("{$this->dir}/nao-existe.csv", 'Arquivo não encontrado ou ilegível');
    }

    public function test_the_versioned_manifest_records_its_source(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('database/curricula/bncc-computacao-2022.json')), true);

        $this->assertStringStartsWith('https://basenacionalcomum.mec.gov.br/', $manifest['source_url']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $manifest['source_consulted_at']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $manifest['source_sha256']);
    }
}
