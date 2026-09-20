<?php

namespace Tests\E2E;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Models\TestCase as ProblemTestCase;
use App\Services\AutoJudgeService;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RequiresJudgeSandbox;
use Tests\TestCase;

/**
 * Um veredito tem de dizer a VERDADE sobre o que aconteceu.
 *
 * `MultiLanguageJudgingTest` pergunta "a linguagem funciona?" e
 * `ToolchainVersionsMatchCatalogTest` pergunta "e a versao prometida?".
 * Falta uma terceira pergunta, e e dela que tratam as issues #323, #324,
 * #327 e #331: quando a submissao NAO da certo, o juiz diz a coisa certa?
 *
 * `RE` no lugar de `CE`, `RE` no lugar de `MLE`, ou um diagnostico que
 * aponta para um arquivo que a equipe nao escreveu -- os tres enganam quem
 * esta competindo, e nenhum da como contestar. Um `a+b` que recebe `AC`
 * passa igual com qualquer um dos tres defeitos presentes, que e por que
 * eles sobreviveram a suite ate aqui.
 */
class LanguageVerdictFidelityTest extends TestCase
{
    use RefreshDatabase;
    use RequiresJudgeSandbox;

    protected function setUp(): void
    {
        $this->skipUnlessJudgeSandboxAvailable();

        parent::setUp();

        $this->enableJudgeSandbox();
    }

    // -----------------------------------------------------------------
    // Issue #323 -- a etapa de compilacao do sed nao analisava o script
    // -----------------------------------------------------------------

    /**
     * As DUAS METADES do comando de compilacao, medidas sem o juiz no meio.
     *
     * Esta e a verificacao que achou cinco comandos errados no Lote C, e a
     * razao de ela existir e que cada metade sozinha passa com um comando
     * errado:
     *
     *   - so "programa quebrado sai != 0" passaria com um comando que
     *     EXECUTA o programa em vez de analisa-lo;
     *   - so "programa valido sai 0" passaria com o `sed -n "q" {source}`
     *     de antes, que era um no-op e aceitava qualquer arquivo.
     *
     * O `stdout` vazio no programa valido e o que separa as duas: um
     * comando que executasse o script imprimiria a linha que o `p` manda
     * imprimir.
     *
     * O comando vem do CATALOGO, e nao esta escrito aqui: mutar
     * `compile_command` tem de quebrar este teste.
     */
    public function test_a_etapa_de_compilacao_do_sed_analisa_o_script_sem_executa_lo()
    {
        $comando = $this->compileCommandOf('sed');

        if (trim((string) shell_exec('command -v sed 2>/dev/null')) === '') {
            $this->markTestSkipped('sed nao esta nesta maquina (rode dentro da imagem do juiz)');
        }

        $dir = $this->scratchDir();

        // Metade 1 -- programa VALIDO: sai 0 e NAO imprime nada.
        //
        // O `p` existe de proposito: se a etapa executasse o script, o
        // texto apareceria no stdout da compilacao.
        file_put_contents("{$dir}/valido.sed", "s/3 5/8/\np\n");
        $valido = Process::path($dir)->run(str_replace('{source}', 'valido.sed', $comando));

        $this->assertSame(
            0,
            $valido->exitCode(),
            "a compilacao recusou um script sed VALIDO:\n".$valido->errorOutput()
        );
        $this->assertSame(
            '',
            trim($valido->output()),
            "a etapa de compilacao do sed EXECUTOU o script em vez de so analisa-lo:\n".$valido->output()
        );

        // Metade 2 -- programa QUEBRADO: sai != 0, com o diagnostico.
        file_put_contents("{$dir}/quebrado.sed", "Z\n");
        $quebrado = Process::path($dir)->run(str_replace('{source}', 'quebrado.sed', $comando));

        $this->assertNotSame(
            0,
            $quebrado->exitCode(),
            'a compilacao ACEITOU um script sed invalido -- o comando do catalogo e um no-op'
        );
        $this->assertStringContainsString(
            'unsupported command Z',
            $quebrado->output().$quebrado->errorOutput(),
            'a compilacao recusou o script mas sem dizer por que'
        );
    }

    /**
     * E o mesmo, pelo juiz de verdade: o veredito e `CE`, e a equipe recebe
     * a linha do parser. Antes desta correcao era `RE`, sem uma linha de
     * diagnostico -- e `CE` e o unico veredito que o sistema apresenta PARA
     * SER LIDO.
     */
    public function test_um_script_sed_invalido_e_erro_de_compilacao_e_nao_erro_de_execucao()
    {
        $run = $this->judgeSolution('sed', 'solution.sed', "Z\n");

        $this->assertSame(
            'CE',
            $run->answer?->short_name,
            "script sed invalido nao virou CE: veredito '{$run->answer?->short_name}'\n"
            ."stdout: {$run->auto_judge_stdout}\nstderr: {$run->auto_judge_stderr}"
        );

        $this->assertStringContainsString(
            'unsupported command Z',
            $run->auto_judge_stdout.$run->auto_judge_stderr,
            'o CE do sed saiu sem a mensagem do parser'
        );
    }

    /**
     * O controle positivo. Sem ele o teste acima passaria igual se a etapa
     * de compilacao do sed recusasse TODO script -- que e o outro jeito de
     * errar a mesma coisa.
     */
    public function test_um_script_sed_valido_continua_sendo_aceito()
    {
        $run = $this->judgeSolution('sed', 'solution.sed', "s/3 5/8/\n");

        $this->assertTrue(
            (bool) $run->answer?->is_accepted,
            "script sed valido virou '{$run->answer?->short_name}': {$run->auto_judge_stderr}"
        );
    }

    // -----------------------------------------------------------------
    // Issue #324 -- o limite de memoria do problema nao chegava ao PHP
    // -----------------------------------------------------------------

    /**
     * O problema declara 256 MB; o PHP tinha teto proprio de 128 MB.
     *
     * Uma equipe que dimensionou a estrutura de dados pelo enunciado
     * recebia `RE` num programa correto, na METADE do orcamento prometido.
     * 160 MB estao dentro dos 256 MB do problema e fora dos 128 MB do
     * interpretador: e exatamente a faixa onde o enunciado mentia.
     */
    public function test_o_limite_de_memoria_do_problema_chega_ao_php()
    {
        $fonte = "<?php\n"
            ."fscanf(STDIN, \"%d %d\", \$a, \$b);\n"
            ."\$blocos = [];\n"
            ."for (\$i = 0; \$i < 20; \$i++) { \$blocos[] = str_repeat('x', 8 * 1024 * 1024); }\n"
            ."echo count(\$blocos) === 20 ? \$a + \$b : 0, \"\\n\";\n";

        $run = $this->judgeSolution('php', 'solution.php', $fonte);

        $this->assertTrue(
            (bool) $run->answer?->is_accepted,
            "um programa PHP que usa 160 MB num problema de 256 MB recebeu '{$run->answer?->short_name}'.\n"
            ."Isso e o teto de 128 MB do interpretador, e nao o limite do problema.\n"
            ."stdout: {$run->auto_judge_stdout}\nstderr: {$run->auto_judge_stderr}"
        );
    }

    /**
     * E quando estoura de verdade, o veredito e `MLE` e nao `RE`.
     *
     * `MLE` diz "reduza a memoria"; `RE` diz "o teu programa quebrou", e
     * manda a equipe procurar o erro no lugar errado. As outras vinte
     * linguagens que alocam memoria ja respondiam `MLE` aqui.
     */
    public function test_php_que_estoura_a_memoria_recebe_mle_e_nao_re()
    {
        $fonte = "<?php\n"
            ."\$blocos = [];\n"
            ."for (\$i = 0; \$i < 4096; \$i++) { \$blocos[] = str_repeat('x', 8 * 1024 * 1024); }\n"
            ."echo 8, \"\\n\";\n";

        $run = $this->judgeSolution('php', 'solution.php', $fonte);

        $this->assertSame(
            'MLE',
            $run->answer?->short_name,
            "PHP estourando a memoria recebeu '{$run->answer?->short_name}' em vez de MLE.\n"
            ."stdout: {$run->auto_judge_stdout}\nstderr: {$run->auto_judge_stderr}"
        );
    }

    // -----------------------------------------------------------------
    // Issue #327 -- recursao de 10^4 niveis
    // -----------------------------------------------------------------

    /**
     * Profundidade banal numa busca em profundidade sobre um grafo de dez
     * mil vertices, e que reprovava em quatro das vinte e quatro
     * linguagens ativas enquanto passava nas outras vinte.
     *
     * As tres aqui sao as que TEM conserto: Python pelo
     * `sys.setrecursionlimit` do sitecustomize, Node e TypeScript pelo
     * `--stack-size` derivado em `judge-runtime/node/run.sh`.
     *
     * O `sh` (bash) nao esta nesta lista, e agora por medicao e nao por
     * hipotese: o custo da forma natural (`$(...)`) cresce com n^4 -- 0,06 s
     * de CPU em 100 niveis, 7,37 s em 500 --, de modo que um limite de 5 s
     * ja estoura por volta de 450 niveis; e nao ha botao nenhum a girar,
     * porque `FUNCNEST` so LIMITA o aninhamento (medido: `FUNCNEST=100000`
     * nao muda nada, `FUNCNEST=50` faz falhar antes) e nao existe
     * equivalente ao `setrecursionlimit` ou ao `--stack-size`. Escrita sem
     * `$(...)` e com a pilha aumentada ela chega a 10^4, custando 26,4 s de
     * CPU -- troca `RE` por `TLE`, nao por `AC`.
     *
     * Por isso o `sh` fecha a #327 por documentacao: ver
     * docs/manuais/organizador.md, "Recursao profunda: o Bash nao aguenta".
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function recursaoProfunda(): array
    {
        return [
            'py3' => ['py3', 'solution.py',
                "import sys\n"
                ."def f(n):\n    return 0 if n == 0 else n + f(n - 1)\n"
                ."a, b = map(int, input().split())\n"
                ."print(a + b if f(10000) == 50005000 else 0)\n",
            ],
            'js_node24' => ['js_node24', 'solution.js',
                "function f(n){return n===0?0:n+f(n-1);}\n"
                ."const p=require('fs').readFileSync(0,'utf8').trim().split(/\\s+/).map(Number);\n"
                ."console.log(f(10000)===50005000 ? p[0]+p[1] : 0);\n",
            ],
            // `declare function require` pelo mesmo motivo que em
            // MultiLanguageJudgingTest: o `tsc --strict` da imagem nao tem
            // os @types/node, e sem a declaracao o programa nao COMPILA --
            // o teste falharia por CE sem nunca exercitar a pilha.
            'ts' => ['ts', 'solution.ts',
                "declare function require(name: string): any;\n"
                ."function f(n: number): number { return n === 0 ? 0 : n + f(n - 1); }\n"
                ."const p: number[] = require('fs').readFileSync(0, 'utf8').trim().split(/\\s+/).map(Number);\n"
                ."console.log(f(10000) === 50005000 ? p[0] + p[1] : 0);\n",
            ],
        ];
    }

    #[DataProvider('recursaoProfunda')]
    public function test_recursao_de_dez_mil_niveis_e_aceita(string $extensao, string $arquivo, string $fonte)
    {
        $run = $this->judgeSolution($extensao, $arquivo, $fonte);

        $this->assertTrue(
            (bool) $run->answer?->is_accepted,
            "recursao de 10^4 niveis em '{$extensao}' recebeu '{$run->answer?->short_name}'.\n"
            ."A escolha de linguagem nao pode mudar o algoritmo que se pode escrever.\n"
            ."stdout: {$run->auto_judge_stdout}\nstderr: {$run->auto_judge_stderr}"
        );
    }

    /**
     * A armadilha que a issue #327 avisa: subir a pilha pode trocar um erro
     * LEGIVEL por um SIGSEGV mudo.
     *
     * Uma recursao que nao termina tem de continuar morrendo com a
     * mensagem do runtime, e nao com "Runtime Error (Segmentation Fault)".
     * Sem este teste, um `--stack-size` alto demais passaria despercebido:
     * o caso de 10^4 niveis acima ficaria verde exatamente igual.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function recursaoInfinita(): array
    {
        return [
            'py3' => ['py3', 'solution.py', "def f(n):\n    return n + f(n + 1)\nprint(f(1))\n", 'RecursionError'],
            'js_node24' => ['js_node24', 'solution.js', "function f(n){return n+f(n+1);}\nconsole.log(f(1));\n", 'RangeError'],
        ];
    }

    #[DataProvider('recursaoInfinita')]
    public function test_recursao_sem_fim_continua_dando_erro_legivel(string $extensao, string $arquivo, string $fonte, string $agulha)
    {
        $run = $this->judgeSolution($extensao, $arquivo, $fonte);

        $this->assertSame(
            'RE',
            $run->answer?->short_name,
            "recursao infinita em '{$extensao}' virou '{$run->answer?->short_name}'"
        );

        $this->assertStringNotContainsString(
            'Segmentation Fault',
            (string) $run->auto_judge_result,
            "subir a pilha de '{$extensao}' trocou o erro legivel por um SIGSEGV: "
            .'o --stack-size (ou o limite de recursao) passou do que a pilha do sistema aguenta'
        );

        $this->assertStringContainsString(
            $agulha,
            (string) $run->auto_judge_stderr,
            "a equipe ficou sem o diagnostico de '{$extensao}': esperado '{$agulha}'"
        );
    }

    // -----------------------------------------------------------------
    // Issue #331 -- o CE de C#
    // -----------------------------------------------------------------

    /**
     * O diagnostico de C# tem de ter a mesma forma das outras 17 familias
     * de fonte: o nome do arquivo QUE A EQUIPE SUBMETEU, a linha, e nada
     * mais.
     *
     * Antes: quinze linhas de banner de primeira execucao do SDK e entao
     * `/tmp/autojudge/run_1/proj/Program.cs(4,14): error CS1002 [.../proj.csproj]`
     * -- um arquivo que nao existe no computador da equipe, num caminho de
     * dentro do sandbox.
     */
    public function test_o_ce_de_csharp_nomeia_o_arquivo_que_a_equipe_submeteu()
    {
        $fonte = "using System;\nclass Program {\n  static void Main() {\n    isto nao e csharp @@@\n  }\n}\n";

        $run = $this->judgeSolution('cs_dotnet', 'solution.cs', $fonte);

        $this->assertSame('CE', $run->answer?->short_name, "esperado CE, veio '{$run->answer?->short_name}'");

        $diagnostico = $run->auto_judge_stdout."\n".$run->auto_judge_stderr;

        $this->assertStringContainsString(
            'solution.cs(4,',
            $diagnostico,
            "o CE de C# nao aponta para o arquivo submetido:\n{$diagnostico}"
        );

        $this->assertStringNotContainsString(
            'Program.cs',
            $diagnostico,
            "o CE de C# ainda manda a equipe procurar 'Program.cs', que ela nao escreveu"
        );

        $this->assertStringNotContainsString(
            'Welcome to .NET',
            $diagnostico,
            'o banner de primeira execucao do SDK voltou a enterrar o erro da equipe'
        );

        $this->assertStringNotContainsString(
            '.csproj]',
            $diagnostico,
            'o sufixo do projeto descartavel voltou a aparecer em cada linha do diagnostico'
        );
    }

    /**
     * O controle positivo do filtro: um programa C# VALIDO continua
     * compilando e rodando.
     *
     * Sem ele, o teste acima passaria igual se o filtro (ou o `-v quiet`)
     * estivesse engolindo a saida inteira do `dotnet build`, inclusive um
     * erro de restore offline.
     */
    public function test_um_programa_csharp_valido_continua_sendo_aceito()
    {
        $fonte = "using System;\nclass Program {\n  static void Main() {\n"
            ."    var p = Console.ReadLine().Split(' ');\n"
            ."    Console.WriteLine(int.Parse(p[0]) + int.Parse(p[1]));\n"
            ."  }\n}\n";

        $run = $this->judgeSolution('cs_dotnet', 'solution.cs', $fonte);

        $this->assertTrue(
            (bool) $run->answer?->is_accepted,
            "programa C# valido virou '{$run->answer?->short_name}': {$run->auto_judge_stderr}"
        );
    }

    // -----------------------------------------------------------------
    // Issue #339 -- a BEAM que nao sobe, de vez em quando
    // -----------------------------------------------------------------

    /**
     * REPETICAO, e nao um caso unico.
     *
     * Erlang e Elixir falham de forma intermitente com
     * `sys_sigaltstack(): Failed to set alternate signal stack`, e um
     * `a+b` que passa em 3 de 4 execucoes e exatamente como isto escapou
     * do CI do #310. `MultiLanguageJudgingTest` sobe a BEAM uma vez por
     * linguagem; este teste a sobe vinte, dentro do MESMO sandbox em que
     * uma submissao roda, e exige vinte sucessos.
     *
     * A causa medida NAO e nossa (ver o relatorio da #339 e
     * erlang/otp#11248): o `sys_signal_stack.c` da BEAM dimensiona a pilha
     * alternativa de sinal com o `SIGSTKSZ` ESTATICO da musl, e o kernel
     * recusa quando o `AT_MINSIGSTKSZ` da CPU e maior. Por isso a
     * mensagem de falha aponta para la: quando este teste quebrar, o
     * proximo a ler nao deve gastar o dia procurando no lugar errado.
     */
    public function test_a_beam_sobe_vinte_vezes_seguidas_dentro_do_sandbox()
    {
        if (trim((string) shell_exec('command -v erl 2>/dev/null')) === '') {
            $this->markTestSkipped('erl nao esta nesta maquina (rode dentro da imagem do juiz)');
        }

        $judge = app(AutoJudgeService::class);
        $dir = $this->scratchDir();

        $partidas = 20;
        $falhas = [];

        for ($i = 1; $i <= $partidas; $i++) {
            $comando = $judge->wrapWithBwrap(
                'erl +JMsingle true -noshell -eval \'io:format("ok"), halt().\'',
                $dir,
                ['allow_net' => false, 'cpu_seconds' => 10, 'max_processes' => 256]
            );

            $resultado = Process::path($dir)->timeout(30)->run($comando);

            if ($resultado->exitCode() !== 0 || trim($resultado->output()) !== 'ok') {
                $falhas[] = "  partida {$i}: rc={$resultado->exitCode()} "
                    .trim($resultado->output().' '.$resultado->errorOutput());
            }
        }

        $this->assertSame(
            [],
            $falhas,
            count($falhas)." de {$partidas} partidas da BEAM falharam dentro do sandbox:\n"
            .implode("\n", $falhas)."\n\n"
            ."Se a mensagem for 'Failed to set alternate signal stack', a causa NAO e deste\n"
            ."repositorio: e erlang/otp#11248 -- a BEAM em musl dimensiona a pilha alternativa\n"
            ."de sinal com o SIGSTKSZ estatico (8192 em x86_64) e o kernel recusa em CPUs cujo\n"
            ."AT_MINSIGSTKSZ e maior (AVX-512/AMX). Corrigido a montante pelos PRs #11249 e\n"
            ."#11376; a imagem fixa erlang27, que nao os carrega. As saidas sao subir a OTP no\n"
            .'Dockerfile.judge ou desativar `erl` e `ex` no catalogo.'
        );
    }

    // -----------------------------------------------------------------

    private function compileCommandOf(string $extension): string
    {
        foreach (Language::getDefaultLanguages() as $language) {
            if ($language['extension'] === $extension) {
                return (string) $language['compile_command'];
            }
        }

        $this->fail("a linguagem '{$extension}' sumiu do catalogo");
    }

    /** @var list<string> */
    private array $scratchDirs = [];

    protected function tearDown(): void
    {
        // FORA de storage/app, e limpo ao sair.
        //
        // A primeira versao deste helper usava storage_path('app/...'), e
        // isso custou uma investigacao: `storage/app/problems` e `drwx------`
        // do usuario `judge`, e um diretorio deixado ali por um processo com
        // outro dono faz os testes SEGUINTES falharem com "Failed to open
        // stream" num arquivo que eles acabaram de escrever. O sintoma
        // aparece longe da causa -- seis linguagens de MultiLanguageJudgingTest
        // recebendo 'CS: Judging Error' --, que e a pior forma de um teste
        // sujar o proximo.
        foreach ($this->scratchDirs as $dir) {
            foreach ((array) glob($dir.'/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($dir);
        }

        $this->scratchDirs = [];

        parent::tearDown();
    }

    private function scratchDir(): string
    {
        $dir = sys_get_temp_dir().'/fidelidade_'.uniqid();
        @mkdir($dir, 0o755, true);

        $this->scratchDirs[] = $dir;

        return $dir;
    }

    /**
     * O mesmo corpo de MultiLanguageJudgingTest::judgeSolution(): monta uma
     * prova de "A + B", envia e julga de verdade. O problema fica com os
     * padroes da tabela -- 1 s de CPU e 256 MB --, que sao os numeros de
     * que as issues #324 e #327 falam.
     */
    private function judgeSolution(string $extension, string $filename, string $source, string $input = "3 5\n"): Run
    {
        $contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subMinutes(5), 'duration' => 300]);
        $site = Site::factory()->create(['contest_id' => $contest->id]);

        foreach (Language::getDefaultLanguages() as $lang) {
            Language::create(array_merge($lang, ['contest_id' => $contest->id]));
        }
        $language = Language::where('contest_id', $contest->id)->where('extension', $extension)->firstOrFail();

        foreach ([
            ['name' => 'Accepted', 'short_name' => 'AC', 'is_accepted' => true],
            ['name' => 'Wrong Answer', 'short_name' => 'WA', 'is_accepted' => false],
            ['name' => 'Compilation Error', 'short_name' => 'CE', 'is_accepted' => false],
            ['name' => 'Runtime Error', 'short_name' => 'RE', 'is_accepted' => false],
            ['name' => 'Time Limit Exceeded', 'short_name' => 'TLE', 'is_accepted' => false],
            ['name' => 'Memory Limit Exceeded', 'short_name' => 'MLE', 'is_accepted' => false],
            ['name' => 'Contest Stopped', 'short_name' => 'CS', 'is_accepted' => false],
        ] as $answer) {
            Answer::create(array_merge($answer, ['contest_id' => $contest->id]));
        }

        $problem = Problem::factory()->create(['contest_id' => $contest->id]);

        $inputRelative = "problems/{$contest->id}/{$problem->id}/input/1";
        $outputRelative = "problems/{$contest->id}/{$problem->id}/output/1";
        Storage::disk('local')->put($inputRelative, $input);
        Storage::disk('local')->put($outputRelative, "8\n");

        ProblemTestCase::create([
            'problem_id' => $problem->id,
            'number' => 1,
            'input_file' => $inputRelative,
            'output_file' => $outputRelative,
            'input_hash' => hash('sha256', $input),
            'output_hash' => hash('sha256', "8\n"),
            'is_sample' => true,
        ]);

        $team = User::create([
            'fullname' => 'Fidelity Test Team',
            'username' => 'fidelity_'.$extension.'_'.uniqid(),
            'email' => 'fidelity-'.$extension.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'team',
            'is_enabled' => true,
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ]);

        Bus::fake();

        $file = UploadedFile::fake()->createWithContent($filename, $source);

        $this->actingAs($team)
            ->post("/submit/{$problem->id}", ['language_id' => $language->id, 'source_file' => $file])
            ->assertRedirect();

        $run = Run::where('contest_id', $contest->id)->where('user_id', $team->user_id)->firstOrFail();

        app(AutoJudgeService::class)->judge($run->fresh());

        return $run->fresh();
    }
}
