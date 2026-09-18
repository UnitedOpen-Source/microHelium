<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ScratchProject;
use Tests\TestCase;

/**
 * Issue #268 -- Scratch como linguagem de submissão.
 *
 * O julgamento de verdade vive em `tests/E2E/MultiLanguageJudgingTest.php`,
 * que roda um `.sb3` real dentro da imagem do juiz, no job Judging do CI.
 * Este arquivo cobre o que NÃO precisa de sandbox: o cadastro da linguagem,
 * o limite de memória, e a tela de envio -- que era onde um ZIP virava
 * centenas de KB de U+FFFD no HTML.
 */
class ScratchLanguageTest extends TestCase
{
    use RefreshDatabase;

    private function scratch(): array
    {
        return collect(Language::getDefaultLanguages())->firstWhere('extension', 'scratch') ?? [];
    }

    // ---------------------------------------------------------------
    // O cadastro.
    // ---------------------------------------------------------------

    public function test_scratch_is_a_language_this_installation_offers(): void
    {
        $lang = $this->scratch();

        $this->assertNotEmpty($lang, 'Scratch nao esta no catalogo de linguagens');
        $this->assertTrue($lang['is_active'], 'Scratch entrou inativa, e a toolchain esta na imagem');
        $this->assertSame('sb3', $lang['file_ext']);
    }

    /**
     * O `compile_command` e o que da CE para uma linguagem que nao compila.
     *
     * `LanguageController` exige `compile_command`, e sem `--check` um
     * projeto corrompido passaria da compilacao e viraria WA -- dizendo a
     * equipe que a resposta esta errada quando o projeto nem abre.
     */
    public function test_the_compile_step_is_the_project_validation(): void
    {
        $lang = $this->scratch();

        $this->assertSame('scratch-run --check {source}', $lang['compile_command']);
        $this->assertSame('scratch-run {source}', $lang['run_command']);
    }

    /**
     * O roteamento por capacidade olha o PRIMEIRO TOKEN do comando e o sonda
     * com `command -v`. Se o comando comecasse com `node`, a maquina
     * anunciaria a capacidade "node" e nao "scratch".
     */
    public function test_the_capability_token_is_scratch_run_and_not_node(): void
    {
        $lang = $this->scratch();

        $this->assertSame('scratch-run', strtok($lang['run_command'], ' '));
        $this->assertSame('scratch-run', strtok($lang['compile_command'], ' '));
    }

    public function test_scratch_has_an_address_space_grace(): void
    {
        $this->assertSame(
            1024,
            config('autojudge.memory_grace_mb.scratch'),
            'sem folga, o bundle de Node nao sobe onde o ulimit -v entra'
        );
    }

    // ---------------------------------------------------------------
    // O projeto montado por código, que é o que a suíte E2E julga.
    // ---------------------------------------------------------------

    public function test_the_generated_project_is_a_zip_with_a_project_json(): void
    {
        $path = ScratchProject::sumOfTwoTokens();

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'o .sb3 gerado nao e um ZIP');

        $json = $zip->getFromName('project.json');
        $zip->close();
        @unlink($path);

        $this->assertNotFalse($json, 'o .sb3 saiu sem project.json');

        $projeto = json_decode((string) $json, true);
        $this->assertIsArray($projeto);

        // O esquema do SB3 exige `blocks` em TODO target, palco incluso --
        // sem isso o projeto inteiro e recusado com "should have required
        // property 'blocks'". Medido contra o scratch-run de verdade.
        foreach ($projeto['targets'] as $target) {
            $this->assertArrayHasKey('blocks', $target, 'um target saiu sem `blocks`, e o SB3 exige');
        }
    }

    /**
     * O projeto de soma usa a convenção de entrada do `scratch-run`:
     * `ask [read_token] and wait` lê um token.
     */
    public function test_the_sum_project_reads_two_tokens_and_says_the_sum(): void
    {
        $path = ScratchProject::sumOfTwoTokens();

        $zip = new \ZipArchive;
        $zip->open($path);
        $projeto = json_decode((string) $zip->getFromName('project.json'), true);
        $zip->close();
        @unlink($path);

        $blocos = $projeto['targets'][1]['blocks'];
        $opcodes = array_column($blocos, 'opcode');

        $this->assertSame(2, count(array_keys($opcodes, 'sensing_askandwait')), 'o projeto nao le dois valores');
        $this->assertContains('operator_add', $opcodes);
        $this->assertContains('looks_say', $opcodes);

        foreach ($blocos as $bloco) {
            if ($bloco['opcode'] === 'sensing_askandwait') {
                $this->assertSame(
                    'read_token',
                    $bloco['inputs']['QUESTION'][1][1],
                    'a pergunta precisa ser read_token para ler UM token, e nao a linha toda'
                );
            }
        }
    }

    // ---------------------------------------------------------------
    // A tela de envio.
    // ---------------------------------------------------------------

    /**
     * @return array{0: Run, 1: User}
     */
    private function envio(string $conteudo, string $filename): array
    {
        $contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subMinutes(5)]);
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);
        $answer = Answer::factory()->create(['contest_id' => $contest->id, 'short_name' => 'YES', 'is_accepted' => true]);

        $team = $this->createTestUser([
            'email' => 'time-scratch@example.com',
            'username' => 'time-scratch',
            'user_type' => User::TYPE_TEAM,
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ]);

        $run = Run::factory()->create([
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'user_id' => $team->user_id,
            'language_id' => $language->id,
            'answer_id' => $answer->id,
            'status' => 'judged',
            'filename' => $filename,
        ]);

        Storage::disk('local')->put(ltrim(str_replace(storage_path('app').'/', '', $run->getSourcePath()), '/'), $conteudo);

        return [$run, $team];
    }

    public function test_a_binary_submission_is_offered_as_a_download_and_not_rendered(): void
    {
        $sb3 = (string) file_get_contents($caminho = ScratchProject::sumOfTwoTokens());
        @unlink($caminho);

        [$run, $team] = $this->envio($sb3, 'solution.sb3');

        $resposta = $this->actingAs($team)->get("/submission/{$run->id}");

        $resposta->assertSuccessful();
        $resposta->assertViewHas('sourceIsBinary', true);
        $resposta->assertViewHas('sourceCode', null);
        $resposta->assertSee('arquivo binário', false);
        $resposta->assertSee(route('submission.source', $run), false);

        // E o lixo NAO entrou no HTML.
        $this->assertStringNotContainsString(
            "\u{FFFD}\u{FFFD}\u{FFFD}",
            $resposta->getContent(),
            'o ZIP foi despejado na pagina como U+FFFD'
        );
    }

    /**
     * O controle positivo: fonte de texto continua sendo MOSTRADO. Sem isto,
     * o teste acima passaria igual se a tela tivesse parado de mostrar fonte
     * nenhum.
     */
    public function test_a_text_submission_is_still_rendered(): void
    {
        [$run, $team] = $this->envio("int main(){return 0;}\n", 'solution.c');

        $this->actingAs($team)->get("/submission/{$run->id}")
            ->assertSuccessful()
            ->assertViewHas('sourceIsBinary', false)
            ->assertSee('int main()', false);
    }

    /**
     * Byte nulo conta como binario mesmo em conteudo que passe por UTF-8:
     * nenhum fonte de programa legitimo tem um.
     */
    public function test_a_null_byte_makes_it_binary(): void
    {
        [$run, $team] = $this->envio("texto\0com nulo", 'solution.c');

        $this->actingAs($team)->get("/submission/{$run->id}")
            ->assertSuccessful()
            ->assertViewHas('sourceIsBinary', true);
    }

    public function test_the_owner_can_download_the_submitted_file(): void
    {
        $sb3 = (string) file_get_contents($caminho = ScratchProject::sumOfTwoTokens());
        @unlink($caminho);

        [$run, $team] = $this->envio($sb3, 'solution.sb3');

        $resposta = $this->actingAs($team)->get("/submission/{$run->id}/source");

        $resposta->assertSuccessful();
        // Sempre octet-stream, mesmo para texto: um `.html` de competidor
        // servido como `text/html` do proprio dominio seria XSS armazenado.
        $resposta->assertHeader('content-type', 'application/octet-stream');
        $this->assertSame($sb3, $resposta->streamedContent(), 'o download nao entregou os bytes enviados');
    }

    public function test_another_team_cannot_download_someone_elses_source(): void
    {
        [$run] = $this->envio("int main(){}\n", 'solution.c');

        $intruso = $this->createTestUser([
            'email' => 'intruso@example.com',
            'username' => 'intruso',
            'user_type' => User::TYPE_TEAM,
            'contest_id' => $run->contest_id,
        ]);

        $this->actingAs($intruso)->get("/submission/{$run->id}/source")->assertForbidden();
    }
}
