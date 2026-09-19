<?php

namespace Tests\E2E;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\ProblemLanguageLimit;
use App\Models\Run;
use App\Models\Site;
use App\Models\TestCase as ProblemTestCase;
use App\Services\AutoJudgeService;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RequiresJudgeSandbox;
use Tests\Support\ScratchProject;
use Tests\TestCase;

/**
 * Every language Language::getDefaultLanguages() marks is_active is offered
 * to admins (Contest Wizard) and participants (submission form), which is a
 * promise that AutoJudgeService can actually compile and run it. This was
 * false for most of them until the toolchains were added to Dockerfile.dev
 * (only gcc/g++/python3 existed before) -- this test is the regression net
 * for that: it submits a real, correct "A + B" solution in every active
 * language and checks it actually gets judged AC, with nothing mocked.
 */
class MultiLanguageJudgingTest extends TestCase
{
    use RefreshDatabase;
    use RequiresJudgeSandbox;

    protected function setUp(): void
    {
        // Issue #49: judging only ever happens inside the sandbox, so the
        // test that proves judging works has to judge inside it too.
        $this->skipUnlessJudgeSandboxAvailable();

        parent::setUp();

        $this->enableJudgeSandbox();
    }

    /**
     * Issue #269 -- linguagens cujo tempo de partida nao cabe no limite do
     * problema, e o quanto elas precisam.
     *
     * Nao e uma lista de excecoes: e a demonstracao de que
     * `problem_language_limits` funciona, no caso real que o motivou.
     */
    private const LIMITES_POR_LINGUAGEM = [
        'portugol_studio' => 30,
    ];

    public static function activeLanguages(): array
    {
        $solutions = [
            'c_gcc13' => ['file' => 'solution.c', 'source' => "#include <stdio.h>\nint main(){int a,b;scanf(\"%d %d\",&a,&b);printf(\"%d\\n\",a+b);return 0;}\n"],
            'cpp_gpp13' => ['file' => 'solution.cpp', 'source' => "#include <iostream>\nint main(){int a,b;std::cin>>a>>b;std::cout<<a+b<<std::endl;return 0;}\n"],
            'cpp17_gpp' => ['file' => 'solution.cpp', 'source' => "#include <iostream>\nint main(){int a,b;std::cin>>a>>b;std::cout<<a+b<<std::endl;return 0;}\n"],
            'java21' => ['file' => 'Main.java', 'source' => "import java.util.Scanner;\npublic class Main {\n  public static void main(String[] args) {\n    Scanner sc = new Scanner(System.in);\n    System.out.println(sc.nextInt() + sc.nextInt());\n  }\n}\n"],
            // Issue #305 -- a MESMA fonte para as duas LTS de Java. E de
            // proposito: o que distingue as duas entradas nao e o programa,
            // e o caminho absoluto do JDK que cada uma invoca. Se as duas
            // passarem com fontes iguais, o que foi provado e que existem
            // dois JDKs de verdade -- ver ToolchainVersionsMatchCatalogTest,
            // que e quem confere QUAL versao cada uma rodou.
            'java25' => ['file' => 'Main.java', 'source' => "import java.util.Scanner;\npublic class Main {\n  public static void main(String[] args) {\n    Scanner sc = new Scanner(System.in);\n    System.out.println(sc.nextInt() + sc.nextInt());\n  }\n}\n"],
            'py3' => ['file' => 'solution.py', 'source' => "a, b = map(int, input().split())\nprint(a + b)\n"],
            'js_node24' => ['file' => 'solution.js', 'source' => "const data = require('fs').readFileSync(0, 'utf8').trim().split(/\\s+/).map(Number);\nconsole.log(data[0] + data[1]);\n"],
            // `declare function require` avoids needing @types/node (not
            // installed globally) just to read stdin in a strict-mode file.
            'ts' => ['file' => 'solution.ts', 'source' => "declare function require(name: string): any;\nconst data: number[] = require('fs').readFileSync(0, 'utf8').trim().split(/\\s+/).map(Number);\nconsole.log(data[0] + data[1]);\n"],
            'kt' => ['file' => 'solution.kt', 'source' => "fun main() {\n    val (a, b) = readLine()!!.trim().split(\" \").map { it.toInt() }\n    println(a + b)\n}\n"],
            'cs_dotnet' => ['file' => 'solution.cs', 'source' => "using System;\nclass Program {\n  static void Main() {\n    var p = Console.ReadLine().Split(' ');\n    Console.WriteLine(int.Parse(p[0]) + int.Parse(p[1]));\n  }\n}\n"],
            'rs' => ['file' => 'solution.rs', 'source' => "use std::io::*;\nfn main() {\n    let mut s = String::new();\n    stdin().read_line(&mut s).unwrap();\n    let v: Vec<i64> = s.trim().split_whitespace().map(|x| x.parse().unwrap()).collect();\n    println!(\"{}\", v[0] + v[1]);\n}\n"],
            'go' => ['file' => 'solution.go', 'source' => "package main\nimport \"fmt\"\nfunc main() {\n  var a, b int\n  fmt.Scan(&a, &b)\n  fmt.Println(a + b)\n}\n"],
            'php' => ['file' => 'solution.php', 'source' => "<?php\nfscanf(STDIN, \"%d %d\", \$a, \$b);\necho \$a + \$b, PHP_EOL;\n"],
            'rb' => ['file' => 'solution.rb', 'source' => "a, b = gets.split.map(&:to_i)\nputs a + b\n"],
            'pas_fpc' => ['file' => 'solution.pas', 'source' => "program Solution;\nvar a, b: integer;\nbegin\n  readln(a, b);\n  writeln(a + b);\nend.\n"],

            // Issue #305, Lote B -- linguagens cujo toolchain JA estava na
            // imagem, ativadas a custo zero de disco.
            //
            // As quatro primeiras reusam a fonte de C/C++ de proposito: o
            // que muda entre elas e a flag `-std` ou o compilador, nao o
            // programa.
            'c99_gcc' => ['file' => 'solution.c', 'source' => "#include <stdio.h>\nint main(){int a,b;scanf(\"%d %d\",&a,&b);printf(\"%d\\n\",a+b);return 0;}\n"],
            'cpp14_gpp' => ['file' => 'solution.cpp', 'source' => "#include <iostream>\nint main(){int a,b;std::cin>>a>>b;std::cout<<a+b<<std::endl;return 0;}\n"],
            'c_clang17' => ['file' => 'solution.c', 'source' => "#include <stdio.h>\nint main(){int a,b;scanf(\"%d %d\",&a,&b);printf(\"%d\\n\",a+b);return 0;}\n"],
            'cpp_clang' => ['file' => 'solution.cpp', 'source' => "#include <iostream>\nint main(){int a,b;std::cin>>a>>b;std::cout<<a+b<<std::endl;return 0;}\n"],
            // `\$p` escapado: a fonte esta numa string PHP de aspas duplas,
            // e sem a barra o PHP interpola `$p` -- o Perl recebia
            // `print  + , "\n"` e dava CE. Aconteceu, e foi assim que se
            // descobriu.
            'perl' => ['file' => 'solution.pl', 'source' => "my @p = split ' ', <STDIN>;\nprint \$p[0] + \$p[1], \"\\n\";\n"],
            'sh' => ['file' => 'solution.sh', 'source' => "read a b\necho $((a + b))\n"],

            // `sed` nao tem aritmetica -- nenhum programa sed soma dois
            // numeros lidos da entrada. A fonte abaixo e uma substituicao
            // literal, e e o melhor que a linguagem permite para este
            // problema.
            //
            // Fica registrado o que este caso prova e o que NAO prova: prova
            // que o `sed -n \"q\"` da compilacao aceita o arquivo, que o
            // `sed -f` roda e que a saida chega ao comparador. Nao prova
            // nenhuma capacidade de calculo, porque nao ha nenhuma para
            // provar. Um teste que se dissesse mais do que isso seria o
            // "verde contra mecanismo que nao pode funcionar" que este
            // repositorio ja conhece.
            'sed' => ['file' => 'solution.sed', 'source' => "s/3 5/8/\n"],
            // Issue #268 -- o unico caso cuja fonte e BINARIA: um `.sb3` e
            // um ZIP. Montado por codigo em Tests\Support\ScratchProject
            // para o programa julgado ser legivel na revisao -- um blob de
            // terceiro no repositorio ninguem sabe o que faz sem abrir o
            // editor.
            'scratch' => ['file' => 'solution.sb3', 'source' => file_get_contents(ScratchProject::sumOfTwoTokens())],
            // Issue #269 -- Portugol Studio. As palavras-chave sao
            // acentuadas, e o arquivo vai como UTF-8: a classe de
            // verificacao le com `StandardCharsets.UTF_8` explicito, porque
            // a codificacao padrao da JVM depende do ambiente.
            // Issue #269 -- o unico caso que precisa da entrada em DUAS
            // LINHAS, e nao e detalhe de fixture: o console do Portugol
            // Studio le com `Scanner.nextLine()`, UMA LINHA POR `leia`.
            // Medido -- com "3 5" numa linha so o programa nao produz saida
            // nenhuma, em silencio.
            //
            // Quem escreve problema para Portugol Studio precisa por um
            // valor por linha. Esta anotado no manual do organizador.
            'portugol_studio' => [
                'file' => 'solution.por',
                'source' => "programa {\n  funcao inicio() {\n    inteiro a, b\n    leia(a)\n    leia(b)\n    escreva(a + b, \"\\n\")\n  }\n}\n",
                'input' => "3\n5\n",
            ],
        ];

        $active = collect(Language::getDefaultLanguages())->where('is_active', true)->pluck('extension');

        $cases = [];
        foreach ($active as $extension) {
            if (isset($solutions[$extension])) {
                $cases[$extension] = [
                    $extension,
                    $solutions[$extension]['file'],
                    $solutions[$extension]['source'],
                    // Issue #269 -- a entrada e "3 5" numa linha para todas,
                    // menos onde a linguagem nao consegue ler assim.
                    $solutions[$extension]['input'] ?? "3 5\n",
                ];
            }
        }

        return $cases;
    }

    /**
     * activeLanguages() silently skips any is_active language missing from
     * its $solutions map (a data provider can't fail loudly per-case), so
     * this is the actual regression net: if someone flips another language
     * to is_active without adding a solution, this fails instead of the
     * suite quietly going green while that language ships untested.
     */
    public function test_every_active_language_has_a_solution_fixture_covering_it()
    {
        $active = collect(Language::getDefaultLanguages())->where('is_active', true)->pluck('extension');
        $covered = array_keys(self::activeLanguages());

        $missing = $active->diff($covered)->values()->all();

        $this->assertEmpty($missing, 'is_active languages with no MultiLanguageJudgingTest fixture: '.implode(', ', $missing));
    }

    /**
     * Issue #268 -- stderr NAO entra na saida comparada.
     *
     * O comando de execucao usava `2>&1`, misturando o erro do programa no
     * arquivo que vai para o diff. Em ICPC o que se compara e o stdout;
     * stderr e diagnostico, e e o que BOCA e DOMjudge ignoram.
     *
     * Quem forcou a questao foi o `scratch-run`, que escreve em stderr. Mas
     * o defeito nunca foi do Scratch: ate aqui QUALQUER programa que
     * imprimisse uma linha de depuracao em stderr recebia WA, em todas as
     * linguagens -- e a equipe lia "resposta errada" sobre uma resposta
     * certa.
     *
     * Este teste roda em C de proposito: a linguagem mais antiga da suite,
     * para deixar claro que o conserto nao e sobre Scratch.
     */
    public function test_a_program_that_writes_to_stderr_is_still_accepted()
    {
        $fonte = "#include <stdio.h>\n"
            .'int main(){int a,b;scanf("%d %d",&a,&b);'
            .'fprintf(stderr,"depuracao: li %d e %d\\n",a,b);'
            ."printf(\"%d\\n\",a+b);return 0;}\n";

        $run = $this->judgeSolution('c_gcc13', 'solution.c', $fonte);

        $this->assertTrue(
            $run->answer->is_accepted,
            "stderr entrou na saida comparada: veredito '{$run->answer?->short_name}'\n"
            ."stdout: {$run->auto_judge_stdout}\nstderr: {$run->auto_judge_stderr}"
        );

        // E o texto NAO se perdeu -- foi para onde o juiz o le.
        $this->assertStringContainsString(
            'depuracao: li 3 e 5',
            (string) $run->auto_judge_stderr,
            'o stderr do programa sumiu em vez de ir para o diagnostico'
        );
    }

    /**
     * Issue #269 -- erro de sintaxe em Portugol Studio da CE, e nao WA.
     *
     * E o ponto inteiro da parte A. Medido, o `Console` com `-no-wait` sai
     * com codigo 0 num programa que nao compila: o `System.exit` mora dentro
     * de `aguardar()`, e `-no-wait` pula esse ramo. Como o CE vem do codigo
     * de saida da compilacao, o erro de sintaxe passaria da compilacao,
     * despejaria as mensagens de erro e viraria WA -- dizendo a equipe que a
     * resposta esta errada quando o programa nem compilou.
     *
     * Por isso o `compile_command` e `portugol-studio-check`, que chama
     * `Portugol.compilarParaAnalise()` -- analisa sem executar.
     *
     * A mutacao que a issue pede nominalmente: trocar o compile_command pelo
     * Console com -no-wait; este teste tem de deixar de dar CE.
     */
    public function test_a_portugol_syntax_error_is_a_compilation_error_and_not_a_wrong_answer()
    {
        $run = $this->judgeSolution(
            'portugol_studio',
            'solution.por',
            "programa {\n  funcao inicio() {\n    isto nao e portugol @@@\n  }\n}\n",
            "3\n5\n"
        );

        $this->assertSame(
            'CE',
            $run->answer?->short_name,
            "erro de sintaxe nao virou CE: veredito '{$run->answer?->short_name}'\n"
            ."stdout: {$run->auto_judge_stdout}\nstderr: {$run->auto_judge_stderr}"
        );

        // E a equipe recebe ONDE consertar, e nao so "o codigo contem erros".
        $this->assertStringContainsString(
            'Linha: 3',
            (string) $run->auto_judge_stderr,
            'o CE saiu sem dizer a linha do erro'
        );
    }

    /**
     * O controle positivo do CE: um programa Portugol VALIDO nao vira CE.
     *
     * Sem ele, o teste acima passaria igual se a etapa de verificacao
     * recusasse todo programa.
     */
    public function test_a_valid_portugol_program_is_not_a_compilation_error()
    {
        $run = $this->judgeSolution(
            'portugol_studio',
            'solution.por',
            "programa {\n  funcao inicio() {\n    inteiro a, b\n    leia(a)\n    leia(b)\n    escreva(a + b, \"\\n\")\n  }\n}\n",
            "3\n5\n"
        );

        $this->assertTrue($run->answer->is_accepted, "programa valido virou '{$run->answer?->short_name}'");
    }

    #[DataProvider('activeLanguages')]
    public function test_active_language_compiles_and_judges_a_correct_solution_as_accepted(string $extension, string $filename, string $source, string $input = "3 5\n")
    {
        $run = $this->judgeSolution($extension, $filename, $source, $input);

        $this->assertSame('judged', $run->status);
        $this->assertNotNull($run->answer_id, "no verdict produced for {$extension} -- stderr: {$run->auto_judge_stderr}");
        $this->assertTrue(
            $run->answer->is_accepted,
            "expected AC for {$extension}, got '{$run->answer?->short_name}': {$run->auto_judge_result}\nstdout: {$run->auto_judge_stdout}\nstderr: {$run->auto_judge_stderr}"
        );
    }

    /**
     * O corpo comum: monta uma prova de "A + B", envia e julga de verdade.
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
            ['name' => 'Contest Stopped', 'short_name' => 'CS', 'is_accepted' => false],
        ] as $answer) {
            Answer::create(array_merge($answer, ['contest_id' => $contest->id]));
        }

        $problem = Problem::factory()->create(['contest_id' => $contest->id]);

        // Issue #269 -- o Portugol Studio precisa de mais que um segundo de
        // CPU, e isso nao e frouxidao de teste.
        //
        // Medido: o comando do juiz aplica `ulimit -t 1` (o limite do
        // problema), e o Portugol Studio COMPILA PARA JAVA em tempo de
        // execucao -- chama `javac` e sobe uma segunda JVM. Duas partidas de
        // JVM nao cabem em um segundo de CPU, e o console engole a falha
        // como "Erro na compilacao!".
        //
        // `problem_language_limits` e o mecanismo que existe exatamente para
        // isto, e usa-lo aqui e o que prova que ele funciona. Afrouxar o
        // limite do PROBLEMA daria mais tempo a todas as linguagens, que e o
        // que este campo existe para evitar.
        if (($limite = self::LIMITES_POR_LINGUAGEM[$extension] ?? null) !== null) {
            ProblemLanguageLimit::create([
                'problem_id' => $problem->id,
                'language_id' => $language->id,
                'time_limit' => $limite,
            ]);
        }

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
            'fullname' => 'Lang Test Team',
            'username' => 'lang_test_'.$extension,
            'email' => "lang-test-{$extension}@example.com",
            'password' => bcrypt('password'),
            'user_type' => 'team',
            'is_enabled' => true,
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ]);

        Bus::fake();

        $file = UploadedFile::fake()->createWithContent($filename, $source);

        $submitResponse = $this->actingAs($team)->post("/submit/{$problem->id}", [
            'language_id' => $language->id,
            'source_file' => $file,
        ]);

        $submitResponse->assertRedirect();

        $run = Run::where('contest_id', $contest->id)->where('user_id', $team->user_id)->firstOrFail();

        app(AutoJudgeService::class)->judge($run->fresh());

        return $run->fresh();
    }
}
