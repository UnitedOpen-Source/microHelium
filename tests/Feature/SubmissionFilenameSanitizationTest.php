<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Support\SourceFilename;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #311 -- o nome do arquivo submetido, nos DOIS caminhos.
 *
 * O defeito era assimetria: o formulario web saneava
 * `getClientOriginalName()` e a API gravava o valor cru em `runs.filename`,
 * de onde ele seguia, sem escape, para `{source}`/`{classname}` na linha que
 * o `AutoJudgeService` entrega a `bash -c`.
 *
 * Duas coisas que este arquivo faz de proposito:
 *
 * 1. Nao guarda nenhuma carga que funcione. As entradas abaixo sao
 *    REPRESENTANTES DE CLASSE de metacaractere (`;`, `$(`, crase, aspa,
 *    barra vertical, quebra de linha); nenhuma executa nada. O que se afirma
 *    e a propriedade -- o que sai e alfanumerico com `.`, `_` e `-` --, e
 *    nao a ausencia de uma lista de exemplos.
 *
 * 2. Tem controle positivo em pe de igualdade com a guarda. Uma sanitizacao
 *    que devolvesse `"source"` para tudo passaria em qualquer teste que so
 *    olhasse a guarda, e quebraria o Java: o compilador exige que o nome do
 *    arquivo case com o da classe publica, e o catalogo deriva
 *    `{classname}` deste mesmo valor. Por isso `Main.java` esta aqui, e por
 *    isso ele e verificado caractere a caractere.
 */
class SubmissionFilenameSanitizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O conjunto permitido, escrito uma vez. Qualquer coisa fora dele chega
     * na linha de comando sem escape.
     */
    private const PERMITIDO = '/\A[A-Za-z0-9._-]+\z/';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
    }

    /**
     * Representantes de classe, nao exploits. Cada um traz UM metacaractere
     * que o `bash -c` (ou o leitor de um dos invocadores de
     * `docker/judge/bin/`) interpretaria.
     *
     * O metacaractere fica no MIOLO do nome, antes do ultimo ponto, e isso
     * foi medido, nao escolhido por estetica. O caminho web reescreve a
     * extensao a partir da linguagem, entao um nome como `a.c; RESTO` chega
     * la e sai como `a.c` -- limpo por acidente de outra regra. Uma versao
     * anterior desta lista punha tudo depois do ponto, e a mutacao que
     * arrancava o saneamento do `SubmitController` so derrubava 2 dos 10
     * casos: o teste estava medindo a reescrita de extensao e dizendo que
     * media o saneamento. O ultimo caso e o contrario -- sem miolo nenhum --
     * e o penultimo guarda o formato antigo, que ainda interessa a API.
     *
     * @return array<string, array{0: string}>
     */
    public static function nomesHostis(): array
    {
        return [
            'separador de comando' => ['Main; INOFENSIVO.c'],
            'substituicao de comando' => ['Main$(INOFENSIVO).c'],
            'crase' => ['Main`INOFENSIVO`.c'],
            'aspa dupla fecha a string' => ['Main" INOFENSIVO.c'],
            'aspa simples fecha a string' => ["Main' INOFENSIVO.c"],
            'barra vertical' => ['Main | INOFENSIVO.c'],
            'quebra de linha' => ["Main\nINOFENSIVO.c"],
            'chave, que o invocador de Tcl usava como protecao' => ['Main}INOFENSIVO.c'],
            'parenteses, que o leitor de Clojure e de Racket consome' => ['Main()INOFENSIVO.c'],
            'redirecionamento, depois do ponto' => ['a.c > INOFENSIVO'],
            'so metacaractere, sem nada aproveitavel' => ['$;|`'],
        ];
    }

    // -----------------------------------------------------------------
    // A guarda. O caminho da API era o desprotegido.
    // -----------------------------------------------------------------

    #[DataProvider('nomesHostis')]
    public function test_the_api_never_stores_a_filename_outside_the_allowed_set(string $hostil): void
    {
        [$contest, $problem, $language, $user] = $this->prova();
        Sanctum::actingAs($user);

        $this->enviarPelaApi($contest, $problem, $language, $hostil)
            ->assertStatus(201);

        $gravado = Run::query()->latest('id')->value('filename');

        $this->assertMatchesRegularExpression(
            self::PERMITIDO,
            $gravado,
            'A API gravou em runs.filename um nome que sai daqui SEM ESCAPE para a linha de '
            ."compilacao executada por `bash -c` (#311). Enviado: {$hostil}"
        );
    }

    #[DataProvider('nomesHostis')]
    public function test_the_web_form_never_stores_a_filename_outside_the_allowed_set(string $hostil): void
    {
        // Extensao `c`, igual a dos nomes da lista: assim a reescrita de
        // extensao do caminho web nao mascara o que esta sendo medido.
        [, $problem, $language, $user] = $this->prova(extensao: 'c');

        $this->actingAs($user)
            ->post("/submit/{$problem->id}", [
                'language_id' => $language->id,
                'source_file' => $this->arquivo($hostil),
            ])
            ->assertSessionHasNoErrors();

        $this->assertMatchesRegularExpression(
            self::PERMITIDO,
            Run::query()->latest('id')->value('filename'),
            "O formulario web regrediu para o estado que a API tinha (#311). Enviado: {$hostil}"
        );
    }

    /**
     * A assimetria em si, que e o que a issue chama de pior sintoma: a mesma
     * equipe protegida pela interface e desprotegida pelo cliente de linha
     * de comando. Esta asserção falha se um dos dois caminhos mudar sozinho,
     * mesmo que os dois continuem "seguros".
     *
     * A extensao do nome enviado casa com a da linguagem de proposito. O
     * caminho web TAMBEM reescreve a extensao a partir da linguagem
     * (`Language::getFileExtension()`, comportamento anterior a esta issue e
     * alheio a ela), entao comparar `a.c` submetido numa linguagem `cpp`
     * mediria essa outra regra e nao esta. Com as duas iguais, o que sobra
     * na comparacao e exatamente o saneamento.
     */
    public function test_both_submission_paths_agree_on_the_same_hostile_name(): void
    {
        $hostil = 'Main; INOFENSIVO.c';

        [$contest, $problem, $language, $user] = $this->prova(extensao: 'c');
        Sanctum::actingAs($user);
        $this->enviarPelaApi($contest, $problem, $language, $hostil, conteudo: 'api')
            ->assertStatus(201);
        $pelaApi = Run::query()->latest('id')->value('filename');

        [, $problemWeb, $languageWeb, $userWeb] = $this->prova(extensao: 'c');
        $this->actingAs($userWeb)
            ->post("/submit/{$problemWeb->id}", [
                'language_id' => $languageWeb->id,
                'source_file' => $this->arquivo($hostil, 'web'),
            ])
            ->assertSessionHasNoErrors();
        $pelaWeb = Run::query()->latest('id')->value('filename');

        $this->assertSame(
            $pelaWeb,
            $pelaApi,
            'Os dois caminhos de submissao voltaram a tratar o nome de formas diferentes (#311). '
            .'A regra mora em App\\Support\\SourceFilename justamente para isso nao acontecer.'
        );

        // E nao "iguais porque os dois viraram a mesma coisa vazia".
        $this->assertSame('Main__INOFENSIVO.c', $pelaApi);
    }

    // -----------------------------------------------------------------
    // O controle positivo. Sem ele, devolver "source" para tudo passaria
    // em todas as asserções acima -- e quebraria o Java.
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function nomesLegitimos(): array
    {
        return [
            // O caso que torna isto requisito, e nao conveniencia: `javac`
            // exige que o arquivo se chame como a classe publica.
            'Main.java' => ['Main.java'],
            'solucao.cpp' => ['solucao.cpp'],
            'a_b-1.c' => ['a_b-1.c'],
            'Solution.kt' => ['Solution.kt'],
            'ABC123.py' => ['ABC123.py'],
        ];
    }

    #[DataProvider('nomesLegitimos')]
    public function test_the_api_keeps_a_legitimate_filename_byte_for_byte(string $legitimo): void
    {
        [$contest, $problem, $language, $user] = $this->prova();
        Sanctum::actingAs($user);

        $this->enviarPelaApi($contest, $problem, $language, $legitimo)
            ->assertStatus(201);

        $this->assertSame(
            $legitimo,
            Run::query()->latest('id')->value('filename'),
            "O saneamento comeu um nome legitimo ({$legitimo}). Para Java isso nao e cosmetico: "
            .'o `{classname}` do catalogo sai deste valor e o compilador recusa se ele nao casar '
            .'com a classe publica (#311).'
        );
    }

    /**
     * O mesmo controle positivo no caminho web, onde a extensao vem da
     * linguagem: com a linguagem "java", `Main.java` tem de sair inteiro.
     */
    public function test_the_web_form_keeps_main_java_intact(): void
    {
        [, $problem, $language, $user] = $this->prova(extensao: 'java');

        $this->actingAs($user)
            ->post("/submit/{$problem->id}", [
                'language_id' => $language->id,
                'source_file' => $this->arquivo('Main.java'),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Main.java', Run::query()->latest('id')->value('filename'));
    }

    // -----------------------------------------------------------------
    // A regra em si, sem HTTP no meio.
    // -----------------------------------------------------------------

    public function test_the_shared_rule_strips_path_components(): void
    {
        // A travessia ja nao passava pelo HTTP -- o getClientOriginalName()
        // do Symfony aplica basename() antes. Isto prova que a regra nao
        // depende disso para quem a chamar de outra origem.
        $this->assertSame('fora.c', SourceFilename::sanitize('../../../fora.c'));
        $this->assertSame('fora.c', SourceFilename::sanitize('/etc/cron.d/../../fora.c'));
    }

    public function test_the_shared_rule_never_returns_an_empty_or_dotfile_name(): void
    {
        $this->assertSame('source', SourceFilename::sanitize(''));
        $this->assertSame('source', SourceFilename::sanitize('...'));
        $this->assertSame('bashrc', SourceFilename::sanitize('.bashrc'));
    }

    public function test_the_shared_rule_bounds_the_length(): void
    {
        $longo = str_repeat('a', 400).'.c';

        $this->assertSame(
            SourceFilename::MAX_LENGTH,
            strlen(SourceFilename::sanitize($longo))
        );
    }

    // -----------------------------------------------------------------

    /**
     * @return array{0: Contest, 1: Problem, 2: Language, 3: User}
     */
    private function prova(string $extensao = 'cpp'): array
    {
        $site = Site::factory()->create();
        $contest = $site->contest;
        $contest->update([
            'is_active' => true,
            'start_time' => now()->subMinute(),
        ]);

        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create([
            'contest_id' => $contest->id,
            'is_active' => true,
            'extension' => $extensao,
        ]);
        $user = User::factory()->create([
            'site_id' => $site->id,
            'contest_id' => $contest->id,
        ]);

        return [$contest->fresh(), $problem, $language, $user];
    }

    /**
     * Conteudo distinto por chamada: a API recusa reenvio com o mesmo
     * `source_hash`, e um 422 de duplicata passaria por "a guarda pegou".
     */
    private function arquivo(string $nome, string $marca = ''): UploadedFile
    {
        static $n = 0;

        return UploadedFile::fake()->createWithContent(
            $nome,
            "int main(){return 0;} // {$marca}".(++$n)
        );
    }

    private function enviarPelaApi(
        Contest $contest,
        Problem $problem,
        Language $language,
        string $nome,
        string $conteudo = ''
    ) {
        return $this->postJson('/api/runs', [
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'source_file' => $this->arquivo($nome, $conteudo),
        ]);
    }
}
