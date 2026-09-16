<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Problem;
use App\Models\ProblemBank;
use App\Services\ProblemPackageService;
use App\Services\ZipPathException;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * Issue #242 -- os outros três lugares que extraem ZIP enviado por alguém.
 *
 * A guarda em si é testada em `Tests\Unit\SafeZipExtractorTest`, incluindo a
 * medição que a motiva: o `extractTo()` do PHP não deixa escapar do destino,
 * ele reescreve o caminho em silêncio. O que se verifica aqui é que cada uma
 * das quatro portas usa a guarda e devolve a recusa no formato daquela porta
 * -- frase na sessão, 422 ou exceção.
 */
class ZipPathRefusalTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = storage_path('app/temp/zippath-'.uniqid());
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

        foreach (glob($dir.'/*') ?: [] as $path) {
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Um ZIP que, extraído como está, escreveria por cima de um arquivo
     * legítimo do próprio pacote.
     */
    private function travessia(): UploadedFile
    {
        $path = $this->workspace.'/travessia-'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('problem.info', "basename=soma\nfullname=Soma\n");
        $zip->addFromString('x/../../limits/c', 'limite trocado');
        $zip->close();

        return new UploadedFile($path, 'pacote.zip', 'application/zip', null, true);
    }

    public function test_the_boca_import_route_refuses_and_says_why(): void
    {
        $this->actingAs($this->createAdminUser())
            ->from(route('backend.import-boca'))
            ->post('/backend/import-boca/upload', ['boca_zip' => $this->travessia()])
            ->assertRedirect(route('backend.import-boca'))
            ->assertSessionHas('error', fn (string $erro) => str_contains($erro, 'sai do próprio diretório'));

        $this->assertSame(0, ProblemBank::count(), 'nada pode ter sido importado');
    }

    public function test_the_api_import_answers_422_and_not_500(): void
    {
        $contest = Contest::factory()->notStarted()->create();
        Sanctum::actingAs($this->createAdminUser());

        $this->postJson('/api/problems', [
            'contest_id' => $contest->id,
            'package' => $this->travessia(),
        ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'O arquivo tem um caminho que sai do próprio diretório: x/../../limits/c']);

        $this->assertSame(0, Problem::where('contest_id', $contest->id)->count());
    }

    public function test_the_package_service_throws_instead_of_importing(): void
    {
        $contest = Contest::factory()->notStarted()->create();

        $this->expectException(ZipPathException::class);

        try {
            app(ProblemPackageService::class)->importFromZip($contest, $this->travessia());
        } finally {
            $this->assertSame(0, Problem::where('contest_id', $contest->id)->count());
        }
    }

    /**
     * Controle positivo das três portas: um pacote bem formado continua
     * passando. Sem isto, uma guarda larga demais recusaria tudo e os testes
     * acima passariam do mesmo jeito.
     */
    public function test_a_clean_package_still_gets_through(): void
    {
        $contest = Contest::factory()->notStarted()->create();

        $path = $this->workspace.'/limpo.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('problem.yaml', "problem_format_version: legacy-icpc\nname: Soma\nlimits:\n    time_limit: 1\n");
        $zip->addFromString('data/sample/01.in', "1 2\n");
        $zip->addFromString('data/sample/01.ans', "3\n");
        $zip->addFromString('data/secret/01.in', "3 4\n");
        $zip->addFromString('data/secret/01.ans', "7\n");
        $zip->close();

        Sanctum::actingAs($this->createAdminUser());

        $this->postJson('/api/problems', [
            'contest_id' => $contest->id,
            'package' => new UploadedFile($path, 'limpo.zip', 'application/zip', null, true),
        ])->assertCreated();

        $this->assertSame(1, Problem::where('contest_id', $contest->id)->count());

        $this->removeTree(storage_path("app/problems/{$contest->id}"));
    }
}
