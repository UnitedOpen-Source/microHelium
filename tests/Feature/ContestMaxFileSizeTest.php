<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Site;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #284 -- `contests.max_file_size` passa a ser lido.
 *
 * A coluna era gravada pelo assistente, pela edicao, pelas duas rotas da API
 * e pelo importador de evento, e lida por ninguem: os tres pontos de envio
 * liam `config('autojudge.max_file_size')`. No stack de desenvolvimento
 * deste repositorio a prova semeada dizia 1024 KB na tela enquanto o envio
 * recusava acima de 100 -- dez vezes de diferenca, em silencio.
 *
 * Mesmo padrao que a #50 consertou em `Site.ip_address`: dado coletado com
 * cuidado, exibido na interface, e nunca aplicado.
 *
 * A constante global fica em 100 KB em todos os testes daqui, e a coluna e
 * movida para os dois lados dela, para que nenhuma asserção possa passar por
 * os dois numeros coincidirem.
 */
class ContestMaxFileSizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');

        config(['autojudge.max_file_size' => 100]);
    }

    /**
     * @return array{0: Contest, 1: Problem, 2: Language, 3: User}
     */
    private function prova(int $maxFileSizeKb): array
    {
        $site = Site::factory()->create();
        $contest = $site->contest;
        $contest->update([
            'is_active' => true,
            'start_time' => now()->subMinute(),
            'max_file_size' => $maxFileSizeKb,
        ]);

        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id, 'is_active' => true]);
        $user = User::factory()->create([
            'site_id' => $site->id,
            'contest_id' => $contest->id,
        ]);

        return [$contest->fresh(), $problem, $language, $user];
    }

    private function enviarPelaApi(Contest $contest, Problem $problem, Language $language, int $kb)
    {
        return $this->postJson('/api/runs', [
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'source_file' => UploadedFile::fake()->create('solucao.cpp', $kb, 'text/plain'),
        ]);
    }

    private function enviarPelaWeb(Problem $problem, Language $language, int $kb)
    {
        return $this->post("/submit/{$problem->id}", [
            'language_id' => $language->id,
            'source_file' => UploadedFile::fake()->create('solucao.cpp', $kb, 'text/plain'),
        ]);
    }

    // ---------------------------------------------------------------
    // A guarda: a coluna abaixo do padrao tem de RECUSAR.
    // ---------------------------------------------------------------

    public function test_the_api_refuses_a_file_over_the_contest_limit(): void
    {
        [$contest, $problem, $language, $user] = $this->prova(maxFileSizeKb: 20);
        Sanctum::actingAs($user);

        // 50 KB passaria folgado pela constante global de 100.
        $this->enviarPelaApi($contest, $problem, $language, kb: 50)
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_file');
    }

    public function test_the_web_form_refuses_a_file_over_the_contest_limit(): void
    {
        [, $problem, $language, $user] = $this->prova(maxFileSizeKb: 20);

        $this->actingAs($user)
            ->from("/submit/{$problem->id}")
            ->post("/submit/{$problem->id}", [
                'language_id' => $language->id,
                'source_file' => UploadedFile::fake()->create('solucao.cpp', 50, 'text/plain'),
            ])
            ->assertSessionHasErrors('source_file');
    }

    // ---------------------------------------------------------------
    // O controle positivo: sem ele, tudo acima valeria igual se alguem
    // trocasse o limite por zero e passasse a recusar todo mundo.
    // ---------------------------------------------------------------

    public function test_the_api_accepts_a_file_the_global_constant_would_refuse(): void
    {
        [$contest, $problem, $language, $user] = $this->prova(maxFileSizeKb: 1024);
        Sanctum::actingAs($user);

        // 300 KB e MAIOR que a constante global de 100: so passa se a coluna
        // do contest tiver sido lida de fato.
        $this->enviarPelaApi($contest, $problem, $language, kb: 300)
            ->assertStatus(201);
    }

    public function test_the_web_form_accepts_a_file_the_global_constant_would_refuse(): void
    {
        [, $problem, $language, $user] = $this->prova(maxFileSizeKb: 1024);

        $this->actingAs($user)
            ->post("/submit/{$problem->id}", [
                'language_id' => $language->id,
                'source_file' => UploadedFile::fake()->create('solucao.cpp', 300, 'text/plain'),
            ])
            ->assertSessionHasNoErrors();
    }

    // ---------------------------------------------------------------
    // Bordas.
    // ---------------------------------------------------------------

    /**
     * Uma linha antiga, ou um importador que tenha gravado 0, nao pode
     * deixar a prova incapaz de receber envio nenhum.
     */
    public function test_a_zeroed_column_falls_back_to_the_installation_default(): void
    {
        [$contest, $problem, $language, $user] = $this->prova(maxFileSizeKb: 0);
        Sanctum::actingAs($user);

        $this->enviarPelaApi($contest, $problem, $language, kb: 50)->assertStatus(201);
        $this->assertSame(100, $contest->fresh()->maxSourceKb(), 'a coluna zerada nao caiu no padrao');
    }

    /**
     * A ordem das mensagens de erro nao muda.
     *
     * O contest e resolvido ANTES da validacao para que `max:` tenha um
     * numero. Um `contest_id` inexistente tem de continuar sendo reprovado
     * por `exists`, e nao virar "o arquivo e grande demais".
     */
    public function test_an_unknown_contest_still_reads_as_an_unknown_contest(): void
    {
        [, $problem, $language, $user] = $this->prova(maxFileSizeKb: 20);
        Sanctum::actingAs($user);

        $this->postJson('/api/runs', [
            'contest_id' => 999999,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'source_file' => UploadedFile::fake()->create('solucao.cpp', 50, 'text/plain'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contest_id');
    }

    /**
     * `?contest_id[]=1&contest_id[]=2` faria `Contest::find()` devolver uma
     * Collection, e `?->maxSourceKb()` morreria com 500 no lugar do 422.
     */
    public function test_an_array_contest_id_is_a_validation_error_and_not_a_crash(): void
    {
        [, $problem, $language, $user] = $this->prova(maxFileSizeKb: 20);
        Sanctum::actingAs($user);

        $this->postJson('/api/runs', [
            'contest_id' => [1, 2],
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'source_file' => UploadedFile::fake()->create('solucao.cpp', 10, 'text/plain'),
        ])->assertStatus(422);
    }

    /**
     * O caminho de colar codigo acompanha.
     *
     * `code_text` deriva do mesmo numero, em caracteres. As duas portas para
     * o mesmo envio nao podem discordar sobre o tamanho aceito: se o limite
     * da prova so valesse para o upload, bastaria colar o fonte para
     * passar por cima dele.
     */
    public function test_pasted_source_follows_the_same_contest_limit(): void
    {
        [, $problem, $language, $user] = $this->prova(maxFileSizeKb: 20);

        // 50 KB de caracteres: abaixo da constante global de 100, acima do
        // limite de 20 desta prova.
        $this->actingAs($user)
            ->from("/submit/{$problem->id}")
            ->post("/submit/{$problem->id}", [
                'language_id' => $language->id,
                'code_text' => str_repeat('a', 50 * 1024),
            ])
            ->assertSessionHasErrors('code_text');
    }

    /**
     * O Treino Livre (#43) nao pertence a contest nenhum, e continua na
     * constante da instalacao. Sem este teste, a mudanca poderia vazar para
     * uma superficie que nao tem coluna para ler.
     */
    public function test_free_practice_stays_on_the_installation_default(): void
    {
        $this->prova(maxFileSizeKb: 1024);

        $this->assertSame(100, Contest::defaultMaxSourceKb());
    }
}
