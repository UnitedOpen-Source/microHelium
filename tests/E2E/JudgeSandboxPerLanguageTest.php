<?php

namespace Tests\E2E;

use App\Console\Commands\JudgehostSelfTestCommand;
use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Models\TestCase as ProblemTestCase;
use App\Services\AutoJudgeService;
use App\Services\CgroupMemoryLimiter;
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
 * Isolamento POR LINGUAGEM -- o que JudgeSandboxIsolationTest nao cobre.
 *
 * A suite de hoje prova o confinamento EM GERAL: um payload de escrita fora
 * do diretorio do run, em dez linguagens, e um a+b por linguagem ativa. Ela
 * nao pergunta se um runtime especifico interage mal com os LIMITES, e essa
 * pergunta ja tem resposta conhecida na casa: o .NET dupla-mapeia memoria
 * executavel por memfd e morre com SIGXFSZ (rc=153, saida vazia) sob
 * QUALQUER `ulimit -f`, 1 GB incluido -- o que o mantem vivo e uma unica
 * variavel de ambiente no wrapWithBwrap(), e nada cobrava isso.
 *
 * O que ESTE arquivo prova, caso a caso, esta escrito em cada teste, junto
 * com o que ele NAO prova. Em geral: nada aqui e uma auditoria de seguranca
 * do bubblewrap nem do kernel. Cada caso mede uma propriedade concreta e diz
 * qual.
 *
 * Os quatro casos, e a propriedade que cada um fixa:
 *
 * - a sonda de rede do `judgehost:selftest` DISCRIMINA -- confinado versus
 *   `--share-net`, contra um ouvinte real desta propria maquina (#335);
 * - nenhuma linguagem com pilha de rede propria abre conexao de dentro do
 *   sandbox (libc, libuv, JVM, .NET, resolvedor do Go);
 * - o `--setenv DOTNET_EnableWriteXorExecute 0` e o que mantem o C# vivo sob
 *   o `ulimit -f` do catalogo, e some sem ninguem notar se ninguem cobrar;
 * - na maquina SEM cgroup v2 delegado -- onde a tabela `memory_grace_mb`
 *   deixa de ser inerte e vira `ulimit -v` -- toda linguagem ativa ainda
 *   roda um a+b. Esse caminho a suite nunca exercitava, e foi por ele que o
 *   `java25` conseguiu ficar de fora da tabela ate o #310 (issue #334).
 */
class JudgeSandboxPerLanguageTest extends TestCase
{
    use RefreshDatabase;
    use RequiresJudgeSandbox;

    protected function setUp(): void
    {
        $this->skipUnlessJudgeSandboxAvailable();

        parent::setUp();

        $this->enableJudgeSandbox();
    }

    /**
     * PROVA: a evidencia de rede do autoteste DISCRIMINA -- separa um
     * sandbox confinado de um sandbox com rede, na MESMA maquina, no MESMO
     * kernel, com a MESMA sonda, contra um ouvinte real aberto FORA do
     * sandbox.
     *
     * NAO PROVA: que o kernel nao tenha outra via de saida que nao passe
     * pela pilha IP -- o caso mede socket de rede, nao canal lateral.
     *
     * Este e o caso que impede a regressao que motivou o conserto (#335). A
     * versao anterior de JudgehostSelfTestCommand::network() exigia que
     * `/proc/net/dev` tivesse `lo` e mais nada, e isso e FALSO em todo
     * kernel com os modulos de tunel carregados: `ipip`, `sit`,
     * `ip6_tunnel` e `ip6_gre` registram um device de fallback em cada
     * namespace de rede novo. Medido com `unshare -Un`, sem bwrap nenhum:
     * `lo tunl0 gre0 gretap0 erspan0 ip_vti0 ip6_vti0 sit0 ip6tnl0 ip6gre0`,
     * zero rotas, zero enderecos. O autoteste reprovava -- "NAO use esta
     * maquina em prova" -- uma maquina que confinava perfeitamente.
     *
     * A mutacao aqui e `--share-net`, o unico jeito de dar rede ao sandbox
     * (`--unshare-all` ja tirou o namespace; omitir `--unshare-net` nao faz
     * nada). O ouvinte fica num porto efemero desta propria maquina, e nao
     * na internet, de proposito: uma sala sem saida para fora faria a
     * segunda metade do experimento mentir.
     *
     * O assert do LOOPBACK e o que vai alem do que a lista de interfaces
     * chegava a ver: um sandbox que compartilha o namespace de rede alcanca
     * todo servico preso ao loopback do judgehost, e a lista de interfaces e
     * a tabela de rotas sao identicas nos dois mundos quando a maquina nao
     * tem rede externa nenhuma.
     */
    public function test_a_sonda_de_rede_do_autoteste_distingue_confinado_de_com_rede(): void
    {
        [$confinado, $chegouConfinado] = self::lerSondaDeRede(['allow_net' => false]);
        [$comRede, $chegouComRede] = self::lerSondaDeRede(['allow_net' => true]);

        $this->assertTrue(
            JudgehostSelfTestCommand::redeEstaConfinada($confinado, $chegouConfinado),
            'a sonda reprovou um sandbox confinado: '.json_encode($confinado)
        );

        $this->assertFalse(
            JudgehostSelfTestCommand::redeEstaConfinada($comRede, $chegouComRede),
            'a sonda aprovou um sandbox COM rede, entao ela nao prova nada: '.json_encode($comRede)
        );

        // A metade que mede TRAFEGO, e nao leitura de /proc: com rede o
        // sandbox chegou ao ouvinte; sem rede, nao. Se um dia so a contagem
        // de rotas continuar distinguindo, estes asserts caem e dizem qual
        // das duas evidencias morreu.
        $this->assertSame(
            'abriu',
            $comRede['alvos']['127.0.0.1'] ?? null,
            'com --share-net o sandbox nao alcancou o ouvinte pelo loopback desta maquina, '
            .'entao a metade de trafego da sonda nao esta medindo nada: '.json_encode($comRede)
        );

        $this->assertGreaterThan(
            0,
            $chegouComRede,
            'nenhuma conexao do sandbox com rede chegou no ouvinte aberto fora dele'
        );

        $this->assertSame(
            'recusado',
            $confinado['alvos']['127.0.0.1'] ?? null,
            'o sandbox confinado alcancou o loopback DESTA maquina: '.json_encode($confinado)
        );

        $this->assertSame(0, $chegouConfinado, 'chegou conexao de um sandbox que deveria estar confinado');

        // E a razao pela qual a lista de interfaces nao serve de evidencia:
        // ela e a mesma dos dois lados. Se um dia deixar de ser, e porque o
        // kernel da maquina mudou, nao porque o confinamento mudou.
        $this->assertSame(
            $confinado['interfaces'],
            array_values(array_diff($comRede['interfaces'], ['eth0'])),
            'as interfaces deixaram de ser as mesmas com e sem rede'
        );
    }

    /**
     * PROVA: submissao em cada uma destas linguagens nao abre conexao, e a
     * falha vem de "sem rota" (ENETUNREACH) e nao de "sem internet".
     *
     * NAO PROVA: nada sobre as linguagens fora da lista. A lista e escolhida
     * por PILHA DE REDE INDEPENDENTE, que e onde uma surpresa caberia: a JVM
     * e o .NET trazem a propria camada de socket, o Go resolve DNS com um
     * resolvedor proprio em vez da libc, o Node usa libuv, e o Python usa a
     * libc direto. As outras 45 ativas nao entram: cada uma custa uma
     * compilacao, e o que elas provariam a mais e a MESMA propriedade do
     * kernel -- o namespace de rede nao tem rota, e nenhuma biblioteca de
     * usuario contorna isso. Cinco pilhas diferentes chegando ao mesmo
     * ENETUNREACH e o que ha de nao-redundante para provar aqui.
     *
     * O programa imprime `8` quando a conexao foi recusada, entao o veredito
     * AC e a afirmacao. Um vazamento vira WA com a evidencia no stdout.
     */
    public static function sondasDeRedePorLinguagem(): array
    {
        return [
            'py3' => ['py3', 'solution.py',
                "import socket\ntry:\n    socket.create_connection(('1.1.1.1', 53), 3).close()\n    print('VAZOU-tcp')\nexcept OSError as e:\n    print(8 if 'unreachable' in str(e).lower() or e.errno in (101, 110) else 'VAZOU-' + str(e))\n"],
            'js_node24' => ['js_node24', 'solution.js',
                "const net = require('net');\nconst s = net.createConnection({host: '1.1.1.1', port: 53, timeout: 3000});\ns.on('connect', () => { console.log('VAZOU-tcp'); s.destroy(); });\ns.on('error', e => { console.log(e.code === 'ENETUNREACH' ? 8 : 'VAZOU-' + e.code); });\ns.on('timeout', () => { console.log('VAZOU-timeout'); s.destroy(); });\n"],
            'java21' => ['java21', 'Main.java',
                "import java.net.*;\npublic class Main {\n  public static void main(String[] a) {\n    try (Socket s = new Socket()) {\n      s.connect(new InetSocketAddress(\"1.1.1.1\", 53), 3000);\n      System.out.println(\"VAZOU-tcp\");\n    } catch (Exception e) {\n      System.out.println(e.getMessage() != null && e.getMessage().contains(\"unreachable\") ? \"8\" : \"VAZOU-\" + e);\n    }\n  }\n}\n"],
            'go' => ['go', 'solution.go',
                "package main\n\nimport (\n\t\"fmt\"\n\t\"net\"\n\t\"strings\"\n\t\"time\"\n)\n\nfunc main() {\n\tc, err := net.DialTimeout(\"tcp\", \"1.1.1.1:53\", 3*time.Second)\n\tif err == nil {\n\t\tc.Close()\n\t\tfmt.Println(\"VAZOU-tcp\")\n\t\treturn\n\t}\n\tif strings.Contains(err.Error(), \"unreachable\") {\n\t\tfmt.Println(8)\n\t\treturn\n\t}\n\tfmt.Println(\"VAZOU-\" + err.Error())\n}\n"],
            'cs_dotnet' => ['cs_dotnet', 'solution.cs',
                "using System;\nusing System.Net.Sockets;\nclass Program {\n  static void Main() {\n    try {\n      var c = new TcpClient();\n      if (c.ConnectAsync(\"1.1.1.1\", 53).Wait(3000)) { Console.WriteLine(\"VAZOU-tcp\"); return; }\n      Console.WriteLine(\"VAZOU-timeout\");\n    } catch (Exception e) {\n      Console.WriteLine(e.ToString().Contains(\"unreachable\") ? \"8\" : \"VAZOU-\" + e.GetType().Name);\n    }\n  }\n}\n"],
        ];
    }

    #[DataProvider('sondasDeRedePorLinguagem')]
    public function test_nenhuma_linguagem_abre_conexao_de_dentro_do_sandbox(string $extension, string $filename, string $source): void
    {
        $run = $this->createAndJudgeRun($extension, $filename, $source);

        $this->assertTrue(
            $run->answer?->is_accepted,
            "{$extension} nao recusou a conexao como 'sem rota': veredito "
            ."'{$run->answer?->short_name}', stdout '{$run->auto_judge_stdout}', stderr '{$run->auto_judge_stderr}'"
        );
    }

    /**
     * PROVA: o `--setenv DOTNET_EnableWriteXorExecute 0` do wrapWithBwrap()
     * e CARREGADOR -- com ele o C# roda sob o `run_max_file_kb` do catalogo,
     * sem ele o mesmo programa morre com rc=153 (SIGXFSZ) e saida vazia.
     *
     * NAO PROVA: que nenhum outro runtime tenha o mesmo problema -- as
     * outras 49 ativas rodam a+b sob o mesmo teto, e quem cobra isso e o
     * MultiLanguageJudgingTest, que julga todas elas com os limites do
     * catalogo. O que ESTE caso fixa e a unica linguagem ativa que so passa
     * por causa de uma linha facil de "limpar".
     *
     * Sem este teste a remocao daquela linha aparece como um C# que
     * subitamente da WA com saida vazia, e o rastro nao aponta para o
     * sandbox. Com ele, aparece aqui, com o nome do mecanismo.
     */
    public function test_o_escape_de_w_xor_x_do_dotnet_e_o_que_mantem_o_csharp_vivo_sob_ulimit_f(): void
    {
        $judge = app(AutoJudgeService::class);
        $runDir = sys_get_temp_dir().'/mh-wx-'.getmypid();
        @mkdir($runDir, 0o700, true);

        $comando = $judge->wrapWithBwrap('true', $runDir, [
            'file_size_kb' => (int) config('autojudge.run_max_file_kb'),
        ]);

        $this->assertStringContainsString(
            '--setenv DOTNET_EnableWriteXorExecute 0',
            $comando,
            'a variavel que mantem o .NET vivo sob ulimit -f sumiu do sandbox'
        );

        // A metade que prova que a variavel faz algo: o MESMO comando, com o
        // valor invertido, contra um `dotnet` de verdade.
        //
        // `dotnet --version` e nao um programa compilado: e a invocacao mais
        // barata que ainda sobe o runtime e faz o JIT mapear memoria
        // executavel, que e o que dispara o SIGXFSZ. Medido nesta imagem --
        // `--version`, `--info`, `help`, `new --list` e `msbuild -version`
        // morrem todos com rc=153 sob W^X ligado; so `--list-sdks` nao, e
        // por isso ele nao serve de sonda.
        $sonda = $judge->wrapWithBwrap(
            'dotnet --version > /dev/null',
            $runDir,
            ['file_size_kb' => (int) config('autojudge.run_max_file_kb')]
        );

        $comEscape = Process::timeout(60)->path($runDir)->run($sonda);

        if ($comEscape->exitCode() !== 0) {
            $this->markTestSkipped('nao ha `dotnet` utilizavel nesta imagem: '.$comEscape->errorOutput());
        }

        $mutado = str_replace(
            '--setenv DOTNET_EnableWriteXorExecute 0',
            '--setenv DOTNET_EnableWriteXorExecute 1',
            $sonda
        );

        $this->assertNotSame($sonda, $mutado, 'a mutacao nao foi aplicada');

        $semEscape = Process::timeout(60)->path($runDir)->run($mutado);

        $this->assertSame(
            153,
            $semEscape->exitCode(),
            'com W^X ligado o dotnet deveria morrer de SIGXFSZ sob ulimit -f; '
            ."veio exit {$semEscape->exitCode()}: {$semEscape->output()} / {$semEscape->errorOutput()}"
        );
    }

    /**
     * PROVA: um run enxerga o SEU caso de teste e mais nada do problema --
     * nem a entrada dos outros casos, nem a saida esperada de caso nenhum.
     *
     * NAO PROVA: nada sobre o que o juiz faz com esses arquivos fora do
     * sandbox.
     *
     * E a propriedade de maior consequencia numa prova e a que estava sem
     * rede de teste: `sandbox_paths` deixa `/var/www/html` de fora e
     * `executeProgram()` monta UM arquivo -- a entrada do caso corrente --
     * por `--ro-bind`. Se algum dia alguem montar o diretorio em vez do
     * arquivo, para "simplificar", a equipe passa a ler os casos ocultos e o
     * placar deixa de significar alguma coisa. Nenhum teste cairia hoje.
     *
     * O programa so responde certo quando as duas perguntas dao "nao", entao
     * o veredito AC e a afirmacao e um vazamento vira WA.
     *
     * Que ele NAO e verde a toa foi medido mutando o bind: trocando
     * `--ro-bind <arquivo do caso>` por `--ro-bind <diretorio de entradas>`,
     * a sonda passa a responder `VAZOU ["entradas=['1', '2']"]`; montando o
     * diretorio do problema inteiro, `VAZOU [... 'leu saida 1', 'leu saida
     * 2']`.
     */
    public function test_um_run_nao_enxerga_os_outros_casos_de_teste_do_problema(): void
    {
        $run = $this->createAndJudgeRun(
            'py3',
            'solution.py',
            null,
            function (Problem $problem) {
                // Segundo caso, com entrada e saida diferentes do primeiro.
                $entrada2 = "problems/{$problem->contest_id}/{$problem->id}/input/2";
                $saida2 = "problems/{$problem->contest_id}/{$problem->id}/output/2";
                Storage::disk('local')->put($entrada2, "100 200\n");
                Storage::disk('local')->put($saida2, "300\n");

                ProblemTestCase::create([
                    'problem_id' => $problem->id,
                    'number' => 2,
                    'input_file' => $entrada2,
                    'output_file' => $saida2,
                    'input_hash' => hash('sha256', "100 200\n"),
                    'output_hash' => hash('sha256', "300\n"),
                    'is_sample' => false,
                ]);
            },
            function (Problem $problem) {
                $entradas = dirname(Storage::disk('local')->path("problems/{$problem->contest_id}/{$problem->id}/input/1"));
                $saidas = dirname(Storage::disk('local')->path("problems/{$problem->contest_id}/{$problem->id}/output/1"));

                // Agnostico a QUAL caso esta rodando, de proposito: o juiz
                // roda os dois, e o programa nao sabe em qual esta. As duas
                // perguntas nao dependem disso -- "o diretorio de entradas
                // tem mais de um arquivo?" e "alguma saida esperada e
                // legivel?" -- e as duas respostas certas sao "nao".
                //
                // A primeira versao deste programa perguntava "consigo ler
                // input/2?", e reprovava o proprio caso 2 lendo a propria
                // entrada. Fica registrado porque foi exatamente o tipo de
                // falso positivo que este arquivo existe para nao produzir.
                return "import os\n"
                    ."a, b = map(int, input().split())\n"
                    ."achados = []\n"
                    ."try:\n"
                    ."    entradas = sorted(os.listdir('".$entradas."'))\n"
                    ."except OSError:\n"
                    ."    entradas = []\n"
                    ."if len(entradas) > 1:\n"
                    ."    achados.append('entradas=' + repr(entradas))\n"
                    ."try:\n"
                    ."    achados.append('saidas=' + repr(sorted(os.listdir('".$saidas."'))))\n"
                    ."except OSError:\n"
                    ."    pass\n"
                    ."for n in ('1', '2'):\n"
                    ."    try:\n"
                    ."        open('".$saidas."/' + n).read()\n"
                    ."        achados.append('leu saida ' + n)\n"
                    ."    except OSError:\n"
                    ."        pass\n"
                    ."print('VAZOU ' + repr(achados)) if achados else print(a + b)\n";
            }
        );

        $this->assertTrue(
            $run->answer?->is_accepted,
            'um run leu material de outro caso de teste do mesmo problema: '
            ."veredito '{$run->answer?->short_name}', resultado '{$run->auto_judge_result}', "
            ."stdout '{$run->auto_judge_stdout}', stderr '{$run->auto_judge_stderr}'"
        );
    }

    /**
     * PROVA: na maquina SEM cgroup v2 delegado -- onde o `ulimit -v` da
     * tabela `memory_grace_mb` e o que entra -- toda linguagem ativa ainda
     * roda um a+b.
     *
     * NAO PROVA: que os numeros da tabela sejam os melhores. Prova so que
     * nenhum deles mata a linguagem que deveria proteger.
     *
     * Este e o caminho que a suite inteira nunca exercitava. Tudo roda em
     * container `--privileged`, onde o cgroup existe e
     * `addressSpaceLimitKbFor()` devolve `null` para todo mundo -- a tabela
     * fica inerte e um erro nela e invisivel. E foi assim que o `java25`,
     * ativado no #305, ficou de fora da lista de `null` e passou a receber
     * `ulimit -v 327680` (limite de 256 MB + o `default` de 64): medido,
     * "Could not reserve enough space for code cache (245768K)", exit 1, em
     * TODA submissao -- num judgehost sem privilegio, que e exatamente a
     * maquina emprestada do #53. A entrada foi consertada no #310 (issue
     * #334); o que faltava, e entra aqui, e a rede que impede a proxima.
     *
     * A lista vem do provedor de MultiLanguageJudgingTest de proposito: uma
     * linguagem nova entra aqui sozinha, no dia em que alguem a ativa, sem
     * ninguem lembrar deste arquivo.
     */
    #[DataProvider('linguagensAtivasComFixture')]
    public function test_sem_cgroup_a_barreira_de_espaco_de_enderecamento_nao_mata_linguagem_ativa(
        string $extension,
        string $filename,
        string $source,
        string $input,
    ): void {
        // A metade sem a qual o resto nao prova nada: com o cgroup vivo a
        // barreira nem e aplicada, e o teste ficaria verde sobre um
        // mecanismo desligado.
        config(['autojudge.cgroup_root' => sys_get_temp_dir().'/mh-sem-cgroup-'.getmypid()]);
        app()->forgetInstance(CgroupMemoryLimiter::class);
        app()->forgetInstance(AutoJudgeService::class);

        $this->assertFalse(
            app(CgroupMemoryLimiter::class)->isAvailable(),
            'o cgroup continua disponivel, entao o ulimit -v nem sera aplicado e este caso nao mede nada'
        );

        $run = $this->createAndJudgeRun($extension, $filename, $source, null, null, $input);

        $this->assertTrue(
            $run->answer?->is_accepted,
            "{$extension} nao roda um a+b numa maquina sem cgroup delegado -- a barreira de "
            .'espaco de enderecamento de config/autojudge.php mata a linguagem. Veredito '
            ."'{$run->answer?->short_name}': {$run->auto_judge_result}\n"
            ."stdout: {$run->auto_judge_stdout}\nstderr: {$run->auto_judge_stderr}"
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function linguagensAtivasComFixture(): array
    {
        return MultiLanguageJudgingTest::activeLanguages();
    }

    /**
     * Roda a sonda de rede do autoteste -- literalmente o mesmo trecho --
     * dentro de um sandbox construido pelo wrapWithBwrap() de verdade,
     * contra um ouvinte TCP aberto aqui fora.
     *
     * @return array{0: array{interfaces: list<string>, rotas: int|null, alvos: array<string, string>, motivos: array<string, string>}, 1: int}
     */
    private static function lerSondaDeRede(array $options): array
    {
        $dir = sys_get_temp_dir().'/mh-rede-'.getmypid();
        @mkdir($dir, 0o700, true);

        $ouvinte = stream_socket_server('tcp://0.0.0.0:0', $errno, $errstr);
        $nome = (string) stream_socket_get_name($ouvinte, false);
        $porta = (int) substr($nome, (int) strrpos($nome, ':') + 1);

        try {
            $comando = app(AutoJudgeService::class)->wrapWithBwrap(
                JudgehostSelfTestCommand::sondaDeRede($porta, JudgehostSelfTestCommand::enderecoRoteavelDestaMaquina()),
                $dir,
                $options
            );

            $saida = Process::timeout(30)->path($dir)->run($comando)->output();

            $chegaram = 0;
            while (($c = @stream_socket_accept($ouvinte, 0)) !== false) {
                $chegaram++;
                fclose($c);
            }
        } finally {
            fclose($ouvinte);
        }

        return [JudgehostSelfTestCommand::lerEvidenciaDeRede($saida), $chegaram];
    }

    /**
     * Copia deliberada da createAndJudgeRun() de JudgeSandboxIsolationTest,
     * com duas diferencas que este arquivo precisa: um gancho que roda ANTES
     * da submissao (para criar o segundo caso de teste) e uma fonte que pode
     * ser calculada a partir do problema ja criado (para conhecer os
     * caminhos dos casos). Compartilhar as duas versoes num trait exigiria
     * mexer naquele arquivo, que nao e o assunto desta mudanca.
     */
    private function createAndJudgeRun(
        string $extension,
        string $filename,
        ?string $source,
        ?callable $antesDaSubmissao = null,
        ?callable $fonteDoProblema = null,
        ?string $entrada = null,
    ): Run {
        // "3 5" numa linha para quase todas; o Portugol Studio le com
        // Scanner.nextLine() e precisa de um valor por linha (#269).
        $entrada ??= "3 5\n";
        $esperado = (string) array_sum(array_map('intval', preg_split('/\s+/', trim($entrada))))."\n";
        $contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subMinutes(5), 'duration' => 300]);
        $site = Site::factory()->create(['contest_id' => $contest->id]);

        foreach (Language::getDefaultLanguages() as $lang) {
            Language::create(array_merge($lang, ['contest_id' => $contest->id]));
        }
        $language = Language::where('contest_id', $contest->id)->where('extension', $extension)->firstOrFail();

        foreach (Answer::getDefaultAnswers() as $answer) {
            Answer::create(array_merge($answer, ['contest_id' => $contest->id]));
        }

        // 30s porque o Portugol Studio sobe uma JVM e ainda chama `javac`
        // em tempo de execucao; nenhum caso deste arquivo mede tempo.
        $problem = Problem::factory()->create(['contest_id' => $contest->id, 'time_limit' => 30]);

        $inputRelative = "problems/{$contest->id}/{$problem->id}/input/1";
        $outputRelative = "problems/{$contest->id}/{$problem->id}/output/1";
        Storage::disk('local')->put($inputRelative, $entrada);
        Storage::disk('local')->put($outputRelative, $esperado);

        ProblemTestCase::create([
            'problem_id' => $problem->id,
            'number' => 1,
            'input_file' => $inputRelative,
            'output_file' => $outputRelative,
            'input_hash' => hash('sha256', $entrada),
            'output_hash' => hash('sha256', $esperado),
            'is_sample' => true,
        ]);

        $team = User::create([
            'fullname' => 'Time da sonda',
            'username' => 'sonda_'.$extension.'_'.substr(md5($filename.$extension.uniqid()), 0, 8),
            'email' => 'sonda-'.$extension.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'team',
            'is_enabled' => true,
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ]);

        Bus::fake();

        if ($antesDaSubmissao !== null) {
            $antesDaSubmissao($problem);
        }

        if ($fonteDoProblema !== null) {
            $source = $fonteDoProblema($problem);
        }

        $file = UploadedFile::fake()->createWithContent($filename, (string) $source);

        $this->actingAs($team)->post("/submit/{$problem->id}", [
            'language_id' => $language->id,
            'source_file' => $file,
        ]);

        $run = Run::where('contest_id', $contest->id)->where('user_id', $team->user_id)->firstOrFail();

        app(AutoJudgeService::class)->judge($run->fresh());

        return $run->refresh();
    }
}
