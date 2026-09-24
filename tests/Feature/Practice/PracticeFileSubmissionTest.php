<?php

namespace Tests\Feature\Practice;

use App\Jobs\JudgeRunJob;
use App\Models\Contest;
use App\Models\ProblemBank;
use App\Models\Run;
use Helium\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ScratchProject;
use Tests\TestCase;

/**
 * Issue #390 (P2) -- o `.sb3` do Scratch no Treino Livre
 * (docs/specs/390-envio-de-arquivo-no-treino.md).
 *
 * O julgamento de um `.sb3` de verdade vive em
 * tests/E2E/MultiLanguageJudgingTest.php, dentro da imagem do juiz. O que
 * este arquivo prova e que o treino entrega o projeto ao MESMO caminho da
 * prova -- RunSubmissionService, nome saneado, JudgeRunJob -- com os mesmos
 * limites, e que nada relaxou para as linguagens de texto.
 */
class PracticeFileSubmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mesmo motivo do PracticeLibraryTest: o envio de treino so abre com
        // o executor isolado saudavel (#49), e isso nao e o assunto aqui.
        config(['autojudge.use_bwrap' => true, 'autojudge.bwrap_path' => '/bin/sh']);
        Storage::fake('local');
        Bus::fake();
    }

    private function publish(): int
    {
        $bank = ProblemBank::create([
            'code' => 'SOMA'.$this->uniqueSuffix(),
            'name' => 'Soma '.$this->uniqueSuffix(),
            'description' => 'Some dois inteiros.',
            'input_description' => 'Dois inteiros a e b.',
            'output_description' => 'A soma de a e b.',
            'sample_input' => "3 5\n",
            'sample_output' => "8\n",
            'time_limit' => 2,
            'memory_limit' => 128,
            'difficulty' => 'easy',
            'tags' => ['bncc'],
            'is_active' => true,
            'version' => 1,
        ]);

        $response = $this->actingAs($this->createAdminUser())->postJson(
            "/api/frontend/bank-governance/{$bank->id}/practice",
            ['published' => true, 'version' => (string) $bank->fresh()->version],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertOk();

        Auth::logout();
        $this->app['auth']->forgetGuards();

        return (int) $response->json('data.practice_problem_id');
    }

    private function scratchId(): int
    {
        return (int) Contest::query()->practice()->firstOrFail()
            ->languages()->where('extension', 'scratch')->value('id');
    }

    private function textLanguageId(): int
    {
        return (int) Contest::query()->practice()->firstOrFail()
            ->languages()->where('extension', 'py3')->value('id');
    }

    private function sb3Bytes(): string
    {
        $path = ScratchProject::sumOfTwoTokens();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * Multipart, como o navegador manda um `FormData`.
     */
    private function send(User $user, int $problemId, array $payload, ?string $key = null): TestResponse
    {
        return $this->actingAs($user)->post(
            "/api/frontend/practice/problems/{$problemId}/runs",
            $payload,
            ['Accept' => 'application/json', 'Idempotency-Key' => $key ?? (string) Str::uuid()],
        );
    }

    // --- O que a página precisa para oferecer o upload --------------------

    public function test_the_problem_detail_says_which_languages_take_a_file(): void
    {
        $problemId = $this->publish();

        $languages = collect($this->getJson("/api/frontend/practice/problems/{$problemId}")
            ->assertOk()->json('problem.languages'))->keyBy('id');

        $this->assertSame('file', $languages[$this->scratchId()]['source_kind']);
        $this->assertSame('.sb3', $languages[$this->scratchId()]['accept']);

        $this->assertSame('text', $languages[$this->textLanguageId()]['source_kind']);
        $this->assertNull($languages[$this->textLanguageId()]['accept']);

        // Nenhuma linguagem de texto virou arquivo por acaso: hoje so o
        // Scratch e fonte-arquivo.
        $this->assertSame(
            [$this->scratchId()],
            $languages->where('source_kind', 'file')->keys()->map(fn ($id) => (int) $id)->values()->all(),
        );
    }

    // --- O `.sb3` entra, pelo caminho da prova ----------------------------

    public function test_an_sb3_is_accepted_and_handed_to_the_same_judge_path_as_a_contest_run(): void
    {
        $user = $this->createTestUser();
        $problemId = $this->publish();
        $bytes = $this->sb3Bytes();

        $response = $this->send($user, $problemId, [
            'language_id' => $this->scratchId(),
            // O nome do cliente mente de proposito: a extensao gravada e a
            // da linguagem, nunca a que veio (#311).
            'source_file' => UploadedFile::fake()->createWithContent('meu projeto;$(id).zip', $bytes),
        ])->assertStatus(202);

        $run = Run::findOrFail($response->json('data.id'));

        $this->assertSame($problemId, (int) $run->problem_id);
        $this->assertSame($this->scratchId(), (int) $run->language_id);
        $this->assertStringEndsWith('.sb3', $run->filename);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $run->filename);

        // Byte a byte: nada de UTF-8, trim ou normalizacao no caminho.
        $this->assertSame($bytes, Storage::disk('local')->get($run->source_file));
        $this->assertSame(hash('sha256', $bytes), $run->source_hash);

        // O mesmo job que julga o envio de prova, e a linguagem traz o
        // `scratch-run --check` que da CE para projeto invalido (#268).
        Bus::assertDispatched(JudgeRunJob::class, fn (JudgeRunJob $job) => $job->run->is($run));
        $this->assertSame('scratch-run --check {source}', $run->language->compile_command);
    }

    public function test_a_file_larger_than_the_limit_is_refused(): void
    {
        $user = $this->createTestUser();
        $problemId = $this->publish();

        // O mesmo numero do texto do treino e do padrao da prova (#284).
        $limitKb = Contest::defaultMaxSourceKb();

        $this->send($user, $problemId, [
            'language_id' => $this->scratchId(),
            'source_file' => UploadedFile::fake()->create('grande.sb3', $limitKb + 1),
        ])->assertStatus(422)->assertJsonValidationErrors('source_file');

        $this->assertSame(0, Run::count());
        Bus::assertNotDispatched(JudgeRunJob::class);
    }

    public function test_the_limit_follows_the_installation_default(): void
    {
        config(['autojudge.max_file_size' => 1]);
        $user = $this->createTestUser();
        $problemId = $this->publish();

        $this->send($user, $problemId, [
            'language_id' => $this->scratchId(),
            'source_file' => UploadedFile::fake()->createWithContent('p.sb3', str_repeat('x', 1025)),
        ])->assertStatus(422)->assertJsonValidationErrors('source_file');

        $this->send($user, $problemId, [
            'language_id' => $this->scratchId(),
            'source_file' => UploadedFile::fake()->createWithContent('p.sb3', str_repeat('x', 1024)),
        ])->assertStatus(202);
    }

    public function test_scratch_without_a_file_is_refused(): void
    {
        $user = $this->createTestUser();
        $problemId = $this->publish();

        $this->send($user, $problemId, ['language_id' => $this->scratchId()])
            ->assertStatus(422)->assertJsonValidationErrors('source_file');

        // Texto colado numa linguagem fonte-arquivo tambem nao e o canal.
        $this->send($user, $problemId, ['language_id' => $this->scratchId(), 'source' => 'say 8'])
            ->assertStatus(422)->assertJsonValidationErrors('source_file');

        $this->send($user, $problemId, [
            'language_id' => $this->scratchId(),
            'source_file' => UploadedFile::fake()->createWithContent('vazio.sb3', ''),
        ])->assertStatus(422)->assertJsonValidationErrors('source_file');

        $this->assertSame(0, Run::count());
    }

    // --- Nada relaxou para as linguagens de texto -------------------------

    public function test_a_file_for_a_text_language_is_still_refused(): void
    {
        $user = $this->createTestUser();
        $problemId = $this->publish();

        $this->send($user, $problemId, [
            'language_id' => $this->textLanguageId(),
            'source_file' => UploadedFile::fake()->createWithContent('projeto.sb3', $this->sb3Bytes()),
        ])->assertStatus(422)->assertJsonValidationErrors('source_file');

        $this->assertSame(0, Run::count());
    }

    public function test_binary_bytes_as_text_are_still_refused(): void
    {
        $user = $this->createTestUser();
        $problemId = $this->publish();

        // Um `.sb3` de verdade colado no campo de texto: UTF-8 invalido.
        $this->send($user, $problemId, [
            'language_id' => $this->textLanguageId(),
            'source' => $this->sb3Bytes(),
        ])->assertStatus(422)->assertJsonValidationErrors('source');

        // Um ZIP sem compressao com conteudo ASCII e UTF-8 VALIDO -- so o
        // byte nulo do cabecalho o denuncia. Antes da #390 isto passava.
        $storedZip = "PK\x03\x04\x14\x00\x00\x00\x00\x00".'print(8)';
        $this->assertTrue(mb_check_encoding($storedZip, 'UTF-8'), 'a premissa do teste: passa em UTF-8');

        $this->send($user, $problemId, [
            'language_id' => $this->textLanguageId(),
            'source' => $storedZip,
        ])->assertStatus(422)->assertJsonValidationErrors('source');

        $this->assertSame(0, Run::count());
    }

    public function test_plain_text_submission_is_unchanged(): void
    {
        $user = $this->createTestUser();
        $problemId = $this->publish();

        $response = $this->actingAs($user)->postJson(
            "/api/frontend/practice/problems/{$problemId}/runs",
            ['language_id' => $this->textLanguageId(), 'source' => "print(8)\n"],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertStatus(202);

        $run = Run::findOrFail($response->json('data.id'));
        $this->assertStringEndsWith('.py', $run->filename);
    }

    // --- Idempotência com arquivo -----------------------------------------

    public function test_the_same_key_with_the_same_file_replays_and_with_another_file_conflicts(): void
    {
        $user = $this->createTestUser();
        $problemId = $this->publish();
        $key = (string) Str::uuid();
        $bytes = $this->sb3Bytes();

        $first = $this->send($user, $problemId, [
            'language_id' => $this->scratchId(),
            'source_file' => UploadedFile::fake()->createWithContent('p.sb3', $bytes),
        ], $key)->assertStatus(202);

        $retry = $this->send($user, $problemId, [
            'language_id' => $this->scratchId(),
            'source_file' => UploadedFile::fake()->createWithContent('p.sb3', $bytes),
        ], $key)->assertStatus(202);

        $this->assertSame($first->json('data.id'), $retry->json('data.id'));

        // Um `UploadedFile` vira `{}` em JSON: sem o hash do conteudo, este
        // segundo arquivo receberia o 202 do primeiro sem ser enviado.
        $this->send($user, $problemId, [
            'language_id' => $this->scratchId(),
            'source_file' => UploadedFile::fake()->createWithContent('p.sb3', $bytes.'outro'),
        ], $key)->assertStatus(409);

        $this->assertSame(1, Run::count());
    }
}
