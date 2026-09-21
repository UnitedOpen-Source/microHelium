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

        // Issue #305, Lote C -- o limite padrao do problema e 1 SEGUNDO DE
        // CPU, e ele vale para a PARTIDA do runtime tambem, nao so para a
        // conta. Sao duas as linguagens deste lote que nao cabem nele, e as
        // duas gastam o tempo antes de ler a entrada. Medido nesta imagem,
        // sob os mesmos limites da etapa de execucao (`ulimit -f 32768`),
        // tres partidas cada:
        //
        //     clj   1,73 e 1,78 s de CPU    (JVM + carregar o clojure.jar)
        //     r     1,03 / 0,99 / 0,91 s    (em cima do limite, as tres)
        //
        // O `r` e o caso que mostra por que isto nao e frouxidao: ele passa
        // ou nao passa conforme a carga da maquina no segundo em que rodar,
        // e um veredito que depende disso nao e um veredito.
        //
        // A BEAM NAO esta nesta lista, e isso foi medido e nao suposto. Uma
        // versao anterior deste patch dava 10 s a `erl` e a `ex` alegando
        // ate 6,2 s de parede; a parede era consequencia de um defeito, nao
        // da linguagem. O que matava as duas era o `ulimit -f`, nao o
        // tempo: a maquina virtual nem subia (ver o `+JMsingle true` no
        // catalogo). Com aquilo corrigido, medido igual:
        //
        //     erl   0,29 s de CPU, 1,19 s de parede
        //     ex    0,55 s de CPU, 0,33 s de parede
        //
        // ou seja, dentro de 1 s de CPU e muito abaixo do corte de
        // seguranca de parede do juiz (limite + 5 s). Deixa-las aqui
        // esconderia justamente a regressao que a flag evita: um `erl` que
        // voltasse a nao subir passaria despercebido sob 10 s de folga.
        //
        // De novo `problem_language_limits`, e nao o limite do problema:
        // afrouxar o problema daria mais tempo a TODAS as linguagens.
        'clj' => 10,
        'r' => 10,
        // Issue #305, Lote D -- as duas linguagens de JVM do lote.
        //
        // Nao e o mesmo caso do Portugol Studio (que sobe DUAS JVMs), mas e
        // o mesmo mecanismo: o limite do problema e de 1 s de CPU, e so a
        // partida da JVM com o runtime de Scala ou de Groovy na frente ja
        // passa disso. O `scala` do lancador oficial carrega o dist inteiro
        // no classpath, e o `groovy` COMPILA o script antes de executa-lo.
        //
        // Afrouxar o limite do PROBLEMA daria mais tempo a todas as
        // linguagens; `problem_language_limits` existe exatamente para nao
        // ter de fazer isso.
        'scala' => 30,
        'groovy' => 30,
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

            // Issue #305, Lote D -- linguagens que NAO existem no Alpine e
            // vieram de fora da distribuicao.

            // Scala: o arquivo TEM de se chamar Main.scala. O
            // `run_command` e `scala {classname}`, e o AutoJudgeService
            // troca `{classname}` pelo nome do arquivo sem extensao -- o
            // mesmo mecanismo do Java, pelo mesmo motivo.
            'scala' => ['file' => 'Main.scala', 'source' => "object Main {\n  def main(args: Array[String]): Unit = {\n    val t = scala.io.StdIn.readLine().trim.split(\"\\\\s+\").map(_.toInt)\n    println(t(0) + t(1))\n  }\n}\n"],

            'groovy' => ['file' => 'solution.groovy', 'source' => "def linha = System.in.newReader().readLine()\ndef p = linha.trim().split(/\\s+/)\nprintln(p[0].toInteger() + p[1].toInteger())\n"],

            // COBOL: `DISPLAY R` sobre um `PIC S9(9)` imprime
            // `+000000008`, e nao `8` -- medido. O campo editado com
            // `FUNCTION TRIM` e o que produz a saida que um problema de
            // maratona espera.
            'cob' => [
                'file' => 'solution.cob',
                'source' => "       IDENTIFICATION DIVISION.\n"
                    ."       PROGRAM-ID. SOMA.\n"
                    ."       DATA DIVISION.\n"
                    ."       WORKING-STORAGE SECTION.\n"
                    ."       01 LINHA PIC X(80).\n"
                    ."       01 A     PIC S9(9).\n"
                    ."       01 B     PIC S9(9).\n"
                    ."       01 R     PIC S9(9).\n"
                    ."       01 SAIDA PIC -(9)9.\n"
                    ."       PROCEDURE DIVISION.\n"
                    ."           ACCEPT LINHA FROM CONSOLE\n"
                    ."           UNSTRING LINHA DELIMITED BY ALL SPACES INTO A B\n"
                    ."           COMPUTE R = A + B\n"
                    ."           MOVE R TO SAIDA\n"
                    ."           DISPLAY FUNCTION TRIM(SAIDA)\n"
                    ."           STOP RUN.\n",
            ],

            // Dart: `\\s+` escapado porque a fonte vive numa string PHP de
            // aspas duplas -- o Dart precisa receber `r'\s+'`.
            'dart' => ['file' => 'solution.dart', 'source' => "import 'dart:io';\nvoid main() {\n  var p = stdin.readLineSync()!.trim().split(RegExp(r'\\s+'));\n  print(int.parse(p[0]) + int.parse(p[1]));\n}\n"],

            // SWI-Prolog: `main/0` e o predicado que o `run_command`
            // chama (`-g "main,halt"`), entao o nome nao e escolha do
            // fixture, e contrato do catalogo.
            'prolog_swi' => [
                'file' => 'solution.pl',
                'source' => "main :-\n"
                    ."    read_line_to_string(user_input, S),\n"
                    ."    split_string(S, \" \", \"\", P),\n"
                    ."    [A, B] = P,\n"
                    ."    number_string(X, A),\n"
                    ."    number_string(Y, B),\n"
                    ."    Z is X + Y,\n"
                    ."    write(Z), nl.\n",
            ],

            // GNU Prolog: `:- initialization(main).` e o que faz o binario
            // compilado rodar alguma coisa -- sem isso ele nao executa o
            // predicado, so termina.
            'prolog_gnu' => [
                'file' => 'solution.pro',
                'source' => "main :-\n"
                    ."    read_integer(A),\n"
                    ."    read_integer(B),\n"
                    ."    C is A + B,\n"
                    ."    write(C), nl.\n"
                    .":- initialization(main).\n",
            ],

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

            // Issue #305, Lote C -- linguagens cujo toolchain o Alpine
            // publica. Cada uma destas fontes foi compilada e executada
            // dentro do sandbox antes de a entrada virar `is_active`, e o
            // programa quebrado equivalente foi rodado para conferir que a
            // etapa de compilacao FALHA (varios `compile_command` do
            // catalogo executavam o programa em vez de analisa-lo).
            'lua' => ['file' => 'solution.lua', 'source' => "local a, b = io.read(\"n\", \"n\")\nprint(a + b)\n"],
            'awk' => ['file' => 'solution.awk', 'source' => "{ print \$1 + \$2 }\n"],
            'tcl' => ['file' => 'solution.tcl', 'source' => "lassign [split [string trim [gets stdin]]] a b\nputs [expr {\$a + \$b}]\n"],
            'clj' => ['file' => 'solution.clj', 'source' => "(let [[a b] (map read-string (clojure.string/split (clojure.string/trim (read-line)) #\"\\s+\"))]\n  (println (+ a b)))\n"],
            'r' => ['file' => 'solution.r', 'source' => "x <- scan(\"stdin\", n = 2, quiet = TRUE)\ncat(x[1] + x[2], \"\\n\", sep = \"\")\n"],
            'ex' => ['file' => 'solution.ex', 'source' => "[a, b] = IO.read(:line) |> String.split() |> Enum.map(&String.to_integer/1)\nIO.puts(a + b)\n"],
            // Erlang exige que o modulo tenha o nome do arquivo, e o
            // `run_command` chama `{classname}:main/0` -- ou seja, esta
            // fonte so funciona salva como `solution.erl`. E a mesma regra
            // do `Main.java` acima, e esta no manual do organizador.
            'erl' => ['file' => 'solution.erl', 'source' => "-module(solution).\n-export([main/0]).\nmain() ->\n    {ok, [A, B]} = io:fread(\"\", \"~d ~d\"),\n    io:format(\"~p~n\", [A + B]).\n"],
            'f90' => ['file' => 'solution.f90', 'source' => "program solucao\n  integer :: a, b\n  read(*,*) a, b\n  write(*,'(I0)') a + b\nend program solucao\n"],
            // Formato FIXO: as seis colunas em branco no inicio de cada
            // linha nao sao estilo, sao o F77. E o que `-std=legacy`
            // aceita e o gfortran moderno recusaria.
            'f77' => ['file' => 'solution.f', 'source' => "      PROGRAM SOL\n      INTEGER A, B\n      READ(*,*) A, B\n      WRITE(*,'(I0)') A + B\n      END\n"],
            // `procedure Solution` e nao `Main`: em Ada o nome da unidade
            // tem de casar com o nome do arquivo.
            'adb' => ['file' => 'solution.adb', 'source' => "with Ada.Text_IO; use Ada.Text_IO;\nwith Ada.Integer_Text_IO; use Ada.Integer_Text_IO;\nprocedure Solution is\n   A, B : Integer;\nbegin\n   Get (A);\n   Get (B);\n   Put (A + B, Width => 0);\n   New_Line;\nend Solution;\n"],
            'lisp_sbcl' => ['file' => 'solution.lisp', 'source' => "(let ((a (read)) (b (read)))\n  (format t \"~a~%\" (+ a b)))\n"],
            'lisp_clisp' => ['file' => 'solution.lisp', 'source' => "(let ((a (read)) (b (read)))\n  (format t \"~a~%\" (+ a b)))\n"],
            'scm' => ['file' => 'solution.scm', 'source' => "(let* ((a (read)) (b (read)))\n  (display (+ a b))\n  (newline))\n"],
            'rkt' => ['file' => 'solution.rkt', 'source' => "#lang racket\n(define a (read))\n(define b (read))\n(displayln (+ a b))\n"],
            // Zig 0.16: `std.fs.File` e `std.io.getStdOut()` nao existem
            // mais, e `std.posix` desta versao exporta `read` mas nao
            // `write`. A saida sai pela interface nova `std.Io`, com um
            // executor explicito -- medido nesta imagem, as duas tentativas
            // anteriores nem compilaram.
            'zig' => ['file' => 'solution.zig', 'source' => "const std = @import(\"std\");\npub fn main() !void {\n    var buf: [256]u8 = undefined;\n    const n = try std.posix.read(0, &buf);\n    var it = std.mem.tokenizeAny(u8, buf[0..n], \" \\t\\r\\n\");\n    const a = try std.fmt.parseInt(i64, it.next().?, 10);\n    const b = try std.fmt.parseInt(i64, it.next().?, 10);\n    var saida: [64]u8 = undefined;\n    const s = try std.fmt.bufPrint(&saida, \"{d}\\n\", .{a + b});\n    var io: std.Io.Threaded = .init_single_threaded;\n    defer io.deinit();\n    try std.Io.File.stdout().writeStreamingAll(io.io(), s);\n}\n"],
            'nim' => ['file' => 'solution.nim', 'source' => "import std/strutils\nlet p = stdin.readLine().splitWhitespace()\necho parseInt(p[0]) + parseInt(p[1])\n"],
            'cr' => ['file' => 'solution.cr', 'source' => "a, b = gets.not_nil!.split.map(&.to_i)\nputs a + b\n"],
            'd_ldc' => ['file' => 'solution.d', 'source' => "import std.stdio;\nvoid main() {\n    int a, b;\n    readf(\" %d %d\", &a, &b);\n    writeln(a + b);\n}\n"],
            'hs' => ['file' => 'solution.hs', 'source' => "main :: IO ()\nmain = do\n  l <- getLine\n  let [a, b] = map read (words l) :: [Integer]\n  print (a + b)\n"],
            'ml' => ['file' => 'solution.ml', 'source' => "let () = Scanf.scanf \" %d %d\" (fun a b -> Printf.printf \"%d\\n\" (a + b))\n"],
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

            // Issue #296 -- G-Portugol, e sem o `input` em duas linhas do
            // irmao acima. O `leia()` daqui nao recebe argumento e vira
            // `scanf("%d")` no C traduzido, entao "3 5" numa linha so
            // funciona: medido, e nao herdado da entrada do Portugol Studio.
            'gportugol' => [
                'file' => 'solution.gpt',
                'source' => "algoritmo somaab;\n\nvari\u{e1}veis\n  a : inteiro;\n  b : inteiro;\nfim-vari\u{e1}veis\n\nin\u{ed}cio\n  a := leia();\n  b := leia();\n  imprima(a + b);\nfim\n",
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
    /**
     * Issue #296 -- o MESMO par de testes, para o outro portugol, e aqui
     * ele nao precisou de contorno nenhum.
     *
     * O `portugol-studio-check` acima existe porque o console do Portugol
     * Studio nao analisa sem executar. O `gpt` analisa: `gpt -t` traduz e
     * SAI COM 1 quando a analise falha (`src/main.cpp:271` -- `return
     * success ? EXIT_SUCCESS : EXIT_FAILURE`). Entao o CE sai do codigo de
     * saida, sem invocador escrito por nos.
     *
     * A MUTACAO QUE ESTE TESTE PEGA, e ela nao e hipotetica: trocar o
     * `gpt -t` do compile.sh pelo `gpt -i` (o interpretador embutido, que
     * parece o caminho obvio e dispensa o gcc). Medido -- o `-i` imprime o
     * mesmo diagnostico e SAI COM 0. Este teste deixa de dar CE.
     */
    public function test_a_gportugol_syntax_error_is_a_compilation_error_and_not_a_wrong_answer()
    {
        $run = $this->judgeSolution(
            'gportugol',
            'solution.gpt',
            "algoritmo compilacao;\n\nin\u{ed}cio\n  isto nao e g-portugol @@@\nfim\n",
            "3 5\n"
        );

        $this->assertSame(
            'CE',
            $run->answer?->short_name,
            "erro de sintaxe nao virou CE: veredito '{$run->answer?->short_name}'\n"
            ."stdout: {$run->auto_judge_stdout}\nstderr: {$run->auto_judge_stderr}"
        );

        // E a equipe recebe ONDE consertar, com o nome do arquivo que ELA
        // enviou -- e nao o do C intermediario que o `gpt -t` gera.
        $this->assertStringContainsString(
            'solution.gpt:4',
            (string) $run->auto_judge_stderr,
            'o CE saiu sem dizer o arquivo e a linha do erro'
        );
    }

    /**
     * O controle positivo do teste acima: sem ele, um `compile.sh` que
     * recusasse TODO programa passaria igual.
     */
    public function test_a_valid_gportugol_program_is_not_a_compilation_error()
    {
        $run = $this->judgeSolution(
            'gportugol',
            'solution.gpt',
            "algoritmo somaab;\n\nvari\u{e1}veis\n  a : inteiro;\n  b : inteiro;\nfim-vari\u{e1}veis\n\nin\u{ed}cio\n  a := leia();\n  b := leia();\n  imprima(a + b);\nfim\n",
            "3 5\n"
        );

        $this->assertTrue($run->answer->is_accepted, "programa valido virou '{$run->answer?->short_name}'");
    }

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
