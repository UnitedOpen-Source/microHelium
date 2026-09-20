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
 * Issues #300 e #305 -- conformidade PROFUNDA das linguagens do auto-judge.
 *
 * O que já existia (MultiLanguageJudgingTest) prova uma coisa só: que cada
 * linguagem `is_active` compila e executa um `a+b` e recebe `AC`. É
 * necessário e é MUITO insuficiente. Aquele teste passaria igual numa
 * linguagem que:
 *
 *   - transforma erro de execução em resposta errada,
 *   - imprime `3,14` em vez de `3.14` por causa de locale,
 *   - estoura a pilha em recursão rasa,
 *   - leva oito segundos só para iniciar,
 *   - não é morta por estourar o tempo,
 *   - não é morta por estourar a memória.
 *
 * Nós já achamos um caso exatamente assim -- Portugol Studio transforma erro
 * de execução em `WA` silencioso (#301, causa upstream
 * UNIVALI-LITE/Portugol-Studio#1218) -- e achamos à mão. Este arquivo é a
 * medição que responde "quantas das outras têm defeito da mesma família?".
 *
 * COMO LER ESTE ARQUIVO
 *
 * A tabela de capacidades (self::CAPACIDADES) declara, para cada linguagem e
 * cada item, um de quatro estados:
 *
 *   CONFORME          -- a linguagem tem de passar no item. Uma falha aqui é
 *                        um defeito, e não um teste para afrouxar.
 *   LIMITE_DA_LINGUAGEM -- medido, estável, e o juiz está CERTO: quem não dá
 *                        conta é o runtime. Fixa o veredito para avisar
 *                        quando o toolchain mudar.
 *   DEFEITO_*         -- medido, quebrado, com issue aberta. O teste FIXA o
 *                        comportamento ATUAL de propósito: quando a issue
 *                        for consertada, ele falha -- e é assim que se
 *                        descobre que foi consertada. Não é um teste verde
 *                        sobre um mecanismo quebrado: a mensagem de falha diz
 *                        qual era o veredito correto.
 *
 * O CONTRATO DE UM `DEFEITO_CONHECIDO`, dito uma vez e valendo para todos
 * -- é o mesmo de tests/Feature/JudgingPipelineRaceTest.php, que fixa as
 * janelas do pipeline pelas issues #312/#313/#314:
 *
 *   (a) o veredito exigido é o que o juiz produz HOJE, e não o certo;
 *   (b) a issue que cobre cada um está em defeitos()['<ling>']['<item>']
 *       ['issue'], junto com o veredito correto e a medição;
 *   (c) quando aquela issue fechar, ESTE TESTE VAI FICAR VERMELHO, e a
 *       falha é o sinal. A resposta certa é INVERTER a asserção -- trocar
 *       a linha da linguagem em capacidades() para CONFORME e apagar a
 *       entrada de defeitos() --, e não "consertar" o teste.
 *
 * Não altere um destes casos sem fechar a issue que ele cita. E não os
 * remova para deixar a suíte verde: um caso removido do provedor de dados
 * para de ser medido, que é o oposto do que esta suíte existe para fazer.
 *
 * HOJE NÃO HÁ NENHUM `DEFEITO_CONHECIDO` NESTA SUÍTE, e isso é o resultado,
 * não a ausência dele. A tabela teve seis, e as seis saíram pelo caminho
 * certo -- a issue foi consertada e o teste ficou VERMELHO avisando:
 *
 *   `sed`/CE (#323), `php`/MLE (#324), a agulha do CE de C# (#331) e a
 *   recursão de `py3`/`js_node24`/`ts` (#327)  -> consertadas pelo #345
 *   `portugol_studio`/RE (#301) e `prolog_gnu`/RE (#347, aberta por esta
 *   auditoria)                                 -> consertadas pelo #351
 *
 * Em nenhuma delas alguém precisou lembrar de voltar aqui. O conserto
 * entrou, a suíte reprovou com "veio o veredito correto, o defeito FOI
 * CONSERTADO", e a asserção foi invertida. O contrato acima deixou de ser
 * promessa e virou histórico.
 *
 * O que sobra fixado são OITO `LIMITE_DA_LINGUAGEM`, todos no item de
 * recursão: `sh`, `tcl`, `r`, `lisp_clisp`, `groovy`, `nim`, `clj` e
 * `portugol_studio`. Ali o juiz está certo e quem não dá conta é o runtime
 * -- a #327 foi fechada documentando isso no manual do organizador, e o
 * teste existe para avisar se algum dia mudar.
 *
 * SEIS vieram da #327. As duas últimas vieram DESTA SUÍTE, e vale contar
 * como: a tabela declarava `clj` e `portugol_studio` CONFORME no item de
 * recursão, e na PRIMEIRA vez que a suíte rodou dentro da imagem do juiz
 * -- no CI, onde os 512 casos deixam de pular -- as duas responderam `RE`.
 * A #327 tinha olhado 24 linguagens; hoje são 48, e estas duas estavam no
 * pedaço que ninguém tinha medido.
 *
 * Nenhuma das duas foi "consertada" afrouxando o teste. O que o juiz
 * respondeu virou a tabela, com a justificativa escrita em limites() e o
 * manual do organizador atualizado junto, porque é ele que quem escreve o
 * problema vai ler.
 *   NAO_SE_APLICA     -- a linguagem não tem a construção que o item exige
 *                        (sed não tem recursão nem ponto flutuante). Exige
 *                        justificativa escrita; é registro de limite, e não
 *                        de cobertura.
 *   NAO_MEDIDO        -- lacuna honesta: sabemos que o item se aplicaria, e
 *                        não construímos o programa. Exige justificativa.
 *
 * Preferimos essa tabela declarada a um `try/catch` genérico exatamente
 * porque um `try/catch` transformaria "não medimos" em "passou".
 *
 * O QUE CADA ITEM PROVA E O QUE NÃO PROVA -- ver o docblock de cada teste.
 *
 * DEPENDÊNCIA DE AMBIENTE, dita em voz alta: os itens de memória só fecham
 * com um cgroup v2 delegado, o que exige `docker run --privileged` (ver
 * config/autojudge.php, 'cgroup_root'). Sem ele a decisão vem do pico de RSS
 * e alguns casos mudam de veredito. E sem `bwrap` utilizável TODO este
 * arquivo se pula -- "passou com tudo pulado" é resultado vazio, então conte
 * os pulados antes de acreditar num verde.
 */
class LanguageConformanceTest extends TestCase
{
    use RefreshDatabase;
    use RequiresJudgeSandbox;

    /** Quantos envios este teste já julgou -- ver julgar(). */
    private int $envios = 0;

    // ------------------------------------------------------------------
    // Os itens medidos.
    // ------------------------------------------------------------------

    /** Erro de execução vira RE, e não WA. Com controle positivo. */
    private const ITEM_RE = 'erro_de_execucao';

    /** Erro de compilação vira CE, e não WA, e diz a linha. */
    private const ITEM_CE = 'erro_de_compilacao';

    /** Laço infinito é morto e vira TLE. */
    private const ITEM_TLE = 'tempo_excedido';

    /** Alocação sem limite é barrada e vira MLE. */
    private const ITEM_MLE = 'memoria_excedida';

    /** Saída correta impressa e depois `exit(3)` continua sendo RE. */
    private const ITEM_SAIDA = 'codigo_de_saida';

    /** `3.14`, nunca `3,14`. */
    private const ITEM_PONTO = 'ponto_decimal';

    /** 10^5 inteiros lidos e somados com E/S ingênua. */
    private const ITEM_ENTRADA = 'entrada_grande';

    /** 10^4 níveis de recursão. */
    private const ITEM_RECURSAO = 'recursao_profunda';

    /** Custo fixo de partida da linguagem. */
    private const ITEM_PARTIDA = 'tempo_de_partida';

    /** stderr não contamina a saída comparada e sobrevive a um AC. */
    private const ITEM_STDERR = 'stderr_separado';

    /**
     * A linguagem também roda no caminho SEM cgroup v2 delegado.
     *
     * config/autojudge.php promete esse caminho em voz alta -- "an
     * unprivileged container, a cgroup v1 host, a developer's laptop [...]
     * Judging must never stop because cgroups are unavailable" -- e lá o
     * `ulimit -v` da tabela `memory_grace_mb` entra no lugar do
     * `memory.max`. Uma linguagem esquecida naquela tabela não roda de jeito
     * nenhum nessas máquinas, e nada media isso até aqui.
     */
    private const ITEM_SEM_CGROUP = 'sem_cgroup_delegado';

    /**
     * NÃO é um item medido: é o controle positivo do item de erro de
     * execução -- o `a+b` correto, julgado pelo mesmo caminho, na mesma
     * linguagem. Fica fora da tabela de capacidades de propósito, porque
     * não há o que "declarar conforme" a respeito dele: ou o caminho de AC
     * funciona para a linguagem, ou nenhuma conclusão sobre RE é possível.
     */
    private const ITEM_CONTROLE = 'controle_positivo';

    // ------------------------------------------------------------------
    // Os estados que a tabela de capacidades pode declarar.
    // ------------------------------------------------------------------

    private const CONFORME = 'conforme';

    /**
     * Medido, quebrado, com issue aberta.
     *
     * O caso CONTINUA sendo julgado -- ele entra no provedor de dados como
     * qualquer outro -- e o que muda é o veredito exigido: o teste fixa o
     * veredito ERRADO que o defeito produz hoje, com a mensagem dizendo qual
     * seria o certo e onde o defeito está registrado.
     *
     * Isso não é afrouxar o teste. Um caso removido do provedor pararia de
     * ser medido; este continua medido e falha nos DOIS sentidos -- se o
     * defeito for consertado, o teste falha e é assim que se descobre; se
     * ele piorar, o teste falha também.
     */
    private const DEFEITO_CONHECIDO = 'defeito_conhecido';

    /**
     * Medido, estável, e NÃO é defeito do juiz: é o que aquela linguagem
     * aguenta.
     *
     * A diferença para DEFEITO_CONHECIDO é o dono do problema. Num
     * DEFEITO_CONHECIDO o juiz está errado e há issue aberta para consertar;
     * aqui o juiz está certo e quem não dá conta é o runtime -- não existe
     * conserto do nosso lado, e a resposta acordada foi DOCUMENTAR, no
     * manual do organizador, para quem escreve o problema saber.
     *
     * O caso continua sendo julgado e o veredito continua fixado, porque o
     * que se quer saber é quando ele MUDAR: uma atualização de toolchain que
     * passe a aguentar 10^4 níveis faz este teste ficar vermelho, e aí a
     * linha vira CONFORME e o manual perde um parágrafo.
     */
    private const LIMITE_DA_LINGUAGEM = 'limite_da_linguagem';

    private const NAO_SE_APLICA = 'nao_se_aplica';

    private const NAO_MEDIDO = 'nao_medido';

    /**
     * Issue #269, parte A -- linguagens cujo tempo de partida não cabe no
     * limite de um problema comum, e o quanto precisam.
     *
     * Medido nesta auditoria, na imagem do juiz em aarch64, com
     * `/usr/bin/time -f '%U+%S'` sobre o comando de execução de um programa
     * que só imprime uma constante:
     *
     *   Portugol Studio   2,72 s de CPU (1,17 s de parede)
     *   Java 21 / 25      0,04 s
     *   Node 24           0,04 s
     *   Ruby / Kotlin     0,03 s
     *   C, Perl, Bash     abaixo de 0,01 s
     *
     * Só o Portugol Studio não cabe no `ulimit -t 1` que um problema padrão
     * aplica, e cabe com folga em qualquer limite acima de três segundos.
     * O valor aqui é o que `problem_language_limits` serve -- e usá-lo é o
     * que prova que aquele mecanismo funciona.
     */
    private const LIMITE_DE_TEMPO_POR_LINGUAGEM = [
        'portugol_studio' => 30,

        // Issue #305, Lote C. Os dois números vêm da mesma medição que
        // MultiLanguageJudgingTest já registra em voz alta: `clj` gasta
        // 1,7-1,8 s de CPU só para subir a JVM e carregar o clojure.jar, e
        // o `r` fica entre 0,91 e 1,03 s -- em cima do limite padrão de 1 s,
        // as três vezes. É esse "em cima" que obriga a linha: um veredito
        // que depende da carga da máquina no segundo em que rodar não é um
        // veredito.
        'clj' => 10,
        'r' => 10,

        // Issue #305, PR #336 -- de novo a recompilação na partida do
        // `groovy`: 2,10 s de CPU no item de memória e 1,70 s no de entrada
        // grande. Menos de três vezes de folga contra o padrão de 5 s, num
        // item que não é sobre tempo, é ruído esperando para acontecer.
        'groovy' => 10,
    ];

    /**
     * O limite usado no item de TLE. Precisa ser maior que o custo de
     * partida da linguagem, ou o laço infinito seria morto pela PARTIDA e o
     * teste passaria pelo motivo errado -- verde sobre mecanismo que não
     * provou nada.
     */
    private const LIMITE_TLE_POR_LINGUAGEM = [
        // REGRA, e ela tem dois lados -- errar para qualquer um dos dois
        // estraga a medição:
        //
        //   piso  -- o valor tem de ser MAIOR que a partida medida da
        //            linguagem. Senão o laço infinito recebe TLE sem o laço
        //            ter rodado: verde sobre um mecanismo que o teste não
        //            exercitou.
        //   teto  -- o valor tem de ficar BEM abaixo do backstop de tempo de
        //            PAREDE do juiz, que é `limite + 5 s`
        //            (AutoJudgeService::executeProgram). Quando aquele
        //            backstop dispara, o veredito é `CS` -- "a infraestrutura
        //            falhou" --, e não `TLE`.
        //
        // O teto não é hipótese: com `'r' => 10` este item devolveu `CS` com
        // "O julgamento excedeu 15 s de tempo de parede na etapa de
        // execucao". É a #329 acontecendo dentro da própria suíte que a
        // documenta. Os valores abaixo são o menor número que ainda passa do
        // custo de partida, para deixar a maior folga possível contra o
        // backstop -- e para este item não virar um portão de CI que depende
        // da carga da máquina.
        //
        //                       partida medida (CPU)   limite   folga de parede
        'portugol_studio' => 6,  //  2,72 s                6 s        ~11 s
        'clj' => 5,              //  2,01 s                5 s        ~10 s
        'groovy' => 5,           //  1,42-1,81 s           5 s        ~10 s
        'r' => 4,                //  ~1,0 s                4 s         ~9 s
        'scala' => 5,            //  0,28-0,35 s           5 s        ~10 s

        // `erl` e `ex` seguem aqui, inativas, pelo motivo escrito em
        // capacidades(): 0,29 s e 0,55 s de CPU de partida.
        'erl' => 5,
        'ex' => 5,
    ];

    /**
     * Como cada cadeia de ferramentas diz A LINHA do erro -- medido, uma a
     * uma, compilando as fontes de self::programasPorItem() na imagem do
     * juiz.
     *
     * Não existe formato comum, e exigir um seria exigir que todas as
     * ferramentas de terceiro do catálogo concordassem. O que se exige é que a linha
     * ONDE O LIXO ESTÁ apareça no diagnóstico que chega à equipe, no
     * formato que aquela ferramenta usa:
     *
     *   gcc / clang / javac / kotlinc / rustc / go / ruby   arquivo:linha
     *   tsc / fpc / Roslyn                                  arquivo(linha,coluna)
     *   php / bash / perl / python                          "line N" / "on line N"
     *   Portugol Studio                                     "Linha: N"
     *
     * O `Program.cs` do C# não é engano: `resources/judge-runtime/csharp/
     * compile.sh` copia a submissão para um projeto descartável, e é esse
     * nome que o Roslyn reporta -- a equipe recebe o erro apontando para um
     * arquivo que ela não escreveu.
     *
     * `null` = a ferramenta não tem linha para reportar, e o teste só exige
     * diagnóstico não vazio. Ver as justificativas.
     */
    private const AGULHA_DA_LINHA_DO_ERRO = [
        'c' => 'solution.c:3',
        'cpp' => 'solution.cpp:3',
        'java' => 'Main.java:3',
        'py' => 'line 3',
        'js' => 'solution.js:4',
        'ts' => 'solution.ts(5,',
        'kt' => 'solution.kt:2',
        // O #331 consertou isto: o `compile.sh` do C# copiava a submissão
        // para `proj/Program.cs` e o Roslyn reportava o nome do arquivo que
        // ELE compilou -- a equipe lia um arquivo que nunca escreveu. A
        // agulha era `Program.cs(4,`, e esta suíte ficou vermelha quando o
        // conserto entrou. Medido agora: `solution.cs(4,14): error CS1002`.
        'cs' => 'solution.cs(4,',
        'rs' => 'solution.rs:2',
        'go' => 'solution.go:4',
        'php' => 'on line 2',
        'rb' => 'solution.rb:2',
        'perl' => 'line 1',
        'sh' => 'line 2',
        'pas' => 'solution.pas(3,',
        'portugol' => 'Linha: 3',
        // Um `.sb3` é um ZIP com um JSON dentro: não tem linha de fonte
        // para apontar, e o diagnóstico do `scratch-run` é o erro de
        // validação do esquema.
        'scratch' => null,
        // Ver o achado registrado em programasSed(): a etapa de compilação
        // do catálogo não analisa o script, então não há diagnóstico nenhum.
        'sed' => null,

        // --- Issue #305, Lote C -----------------------------------------
        'lua' => 'solution.lua:2',
        'awk' => 'solution.awk:2',
        'r' => 'solution.r:3',
        'scm' => 'solution.scm:3:',
        'rkt' => 'solution.rkt:3:',
        'zig' => 'solution.zig:3:',
        'nim' => 'solution.nim(3,',
        'cr' => 'solution.cr:3:',
        'd' => 'solution.d(3)',
        'hs' => 'solution.hs:3',
        'ml' => '"solution.ml", line 3',
        'f90' => 'solution.f90:3',
        'f77' => 'solution.f:3',
        'adb' => 'solution.adb:3',
        'erl' => 'solution.erl:3',

        // --- Issue #305, PR #336 ----------------------------------------
        'scala' => 'Main.scala:3',
        // O espaço depois dos dois-pontos é do Groovy, e não erro de digitação.
        'groovy' => 'solution.groovy: 3',
        'dart' => 'solution.dart:3',
        'prolog_swi' => 'solution.pl:3',
        // A única do catálogo cujo diagnóstico de compilação sai na SAÍDA
        // PADRÃO, e não no stderr: o `gplc` deixa só `compilation failed` no
        // stderr. A asserção junta os dois canais, então fecha -- mas ficou
        // escrito, porque é o tipo de coisa que quebra em silêncio se alguém
        // passar a olhar só um dos dois.
        'prolog_gnu' => 'solution.pro:3',
        'cob' => 'solution.cob:3',

        // O `clojure-check` cita a LINHA da submissão, mas não o arquivo: a
        // primeira linha do erro aponta `REPL:5`, que é a linha do `-e` do
        // próprio invocador. A linha certa só aparece na segunda.
        'clj' => 'starting at line 3',

        // `nofile`, e não `solution.ex`: ver o achado no docblock de
        // programasElixir(). A agulha documenta um diagnóstico ruim.
        'ex' => 'nofile:3',

        // `tcl-check` decide com `info complete`, que devolve um booleano:
        // não existe linha para reportar, e a mensagem é a frase fixa do
        // script. Ver o docblock de programasTcl().
        'tcl' => null,
    ];

    /**
     * Exceção à tabela acima, POR EXTENSÃO e não por família.
     *
     * As duas entradas de Common Lisp compilam a MESMA fonte e escrevem a
     * linha do erro de formas incompatíveis -- medido:
     *
     *     lisp_sbcl    `Line: 3, Column: 29, File-Position: 57`
     *     lisp_clisp   `#P".../solution.lisp" @3>`
     *
     * Não existe substring comum que contenha a linha (só o dígito `3`
     * solto, que casaria por acidente). Separar as FAMÍLIAS resolveria a
     * agulha e apagaria a evidência de que a fonte é a mesma; separar só a
     * agulha preserva as duas coisas.
     *
     * Confirmado que o `@N` do CLISP é a LINHA e não a posição de caractere:
     * com o lixo na linha 2 sai `@2`, e com ele na linha 3 e
     * `File-Position: 107` sai `@3`.
     */
    private const AGULHA_POR_EXTENSAO = [
        'lisp_sbcl' => 'Line: 3',
        'lisp_clisp' => 'solution.lisp" @3',
    ];

    /**
     * Teto de tempo de PARTIDA, em milissegundos de parede, por linguagem.
     *
     * Medido (acima) e com folga de uma ordem de grandeza, porque a máquina
     * de quem roda a suíte não é esta. O que o teto captura é a regressão de
     * categoria -- uma linguagem que passe a precisar de segundos onde
     * precisava de dezenas de milissegundos --, e não a variação de carga.
     *
     * LIMITE DESTE ITEM, achado mutando-o e dito aqui para ninguém ler mais
     * do que ele prova: `measured_wall_ms` vem do `time -f` com resolução de
     * 10 ms, e a maioria das linguagens compiladas mede **0 ms** para
     * imprimir uma constante. Para essas, a comparação com o teto é
     * verdadeira por construção e não pode falhar -- a mutação que baixa o
     * teto para zero NÃO é pega, porque `0 <= 0`. O item só tem força onde a
     * partida custa algo mensurável (`portugol_studio`, `clj`, `groovy`,
     * `rkt`, `r`, `scratch`), e é lá que a mutação foi confirmada: com o teto
     * do `portugol_studio` em 1 ms, o teste falha.
     *
     * O que continua valendo para TODAS é a outra metade do item: o programa
     * mínimo tem de receber `AC` e tem de haver medição gravada.
     */
    private const TETO_DE_PARTIDA_MS = [
        'portugol_studio' => 20000,
        'kt' => 5000,
        'cs_dotnet' => 5000,
        'scratch' => 5000,
        'java25' => 5000,
        'java21' => 5000,
        'ts' => 5000,
        'js_node24' => 5000,

        // Issue #305, Lote C -- as duas do lote que não cabem no padrão.
        // Medido, parede, só para imprimir `8`: `clj` 1,00 s (JVM mais o
        // clojure.jar) e `erl` 1,27 s (a BEAM). As outras dezessete ficam
        // abaixo de 0,5 s e cabem no `default` com folga -- `rkt`, a mais
        // lenta delas, leva 0,37 s.
        'clj' => 5000,
        'erl' => 5000,
        'ex' => 5000,
        'r' => 5000,

        // Issue #305, PR #336 -- medido, parede, três repetições, só
        // imprimindo `8`: `scala` 0,19-0,21 s e `groovy` 0,58-0,64 s. As
        // outras quatro do lote ficam em 0,00 s e cabem no `default`.
        'scala' => 5000,
        'groovy' => 5000,

        'default' => 3000,
    ];

    /**
     * Os dois guardas da própria tabela não julgam nada.
     *
     * Eles comparam listas -- o catálogo de linguagens contra
     * `capacidades()`, e `capacidades()` contra `limites()`,
     * `defeitos()`, `justificativas()` e `programas()`. Não compilam, não
     * executam e não precisam de `bwrap`.
     *
     * Deixá-los atrás da mesma porta dos itens medidos seria entregar a
     * rede de regressão do catálogo a quem tem sandbox e a mais ninguém:
     * numa máquina sem `bwrap` -- que é a de quase todo desenvolvedor e a
     * do CI -- uma linguagem nova em `is_active` sem linha na tabela
     * passaria batida, porque o guarda que a pegaria estaria PULADO. Estes
     * dois rodam sempre; os outros 510 continuam exigindo o sandbox.
     */
    private const GUARDAS_QUE_NAO_JULGAM = [
        'test_toda_linguagem_ativa_tem_linha_na_tabela_de_capacidades',
        'test_toda_excecao_declarada_tem_justificativa_e_todo_conforme_tem_programa',
    ];

    protected function setUp(): void
    {
        // Issue #49: julgamento só acontece dentro do sandbox, então o teste
        // que mede julgamento tem de medir dentro dele também.
        if (! in_array($this->name(), self::GUARDAS_QUE_NAO_JULGAM, true)) {
            $this->skipUnlessJudgeSandboxAvailable();
        }

        parent::setUp();

        $this->enableJudgeSandbox();
    }

    // ==================================================================
    // A TABELA DE CAPACIDADES
    // ==================================================================

    /**
     * Cada linguagem `is_active` aponta para a família de fonte que ela
     * compila e, item a item, para o estado declarado.
     *
     * "Família" é o que permite dizer a verdade sobre as sete entradas de
     * C/C++ e as duas de Java: o que distingue `cpp14_gpp` de `cpp17_gpp`
     * não é o programa, é a flag `-std`. Rodar a mesma fonte nas duas prova
     * que existem duas cadeias de ferramentas, e não que existem dois
     * programas.
     *
     * @return array<string, array{familia: string, itens: array<string, string>}>
     */
    public static function capacidades(): array
    {
        // A maioria das linguagens é "conforme em tudo": só se escreve o que
        // foge disso, para a tabela ser legível.
        $tudoConforme = array_fill_keys(self::itens(), self::CONFORME);

        $c = ['familia' => 'c', 'itens' => $tudoConforme];
        $cpp = ['familia' => 'cpp', 'itens' => $tudoConforme];
        $java = ['familia' => 'java', 'itens' => $tudoConforme];
        $tudoConformePhp = ['familia' => 'php', 'itens' => $tudoConforme];

        // Recursão de 10^4 níveis -- profundidade banal numa busca em
        // profundidade sobre um grafo de 10 mil vértices. Medido nesta
        // revisão contra TODAS as linguagens ativas. Ver limites(), que
        // registra o teto de cada uma -- e note que eles são de naturezas
        // diferentes (limite do interpretador, pilha do runtime, e, no
        // caso do Nim, uma escolha do comando de compilação do catálogo).
        $recursaoLimitada = [self::ITEM_RECURSAO => self::LIMITE_DA_LINGUAGEM];

        return [
            // --- C: três entradas, uma fonte, três invocações distintas ---
            'c_gcc13' => $c,
            'c_clang17' => $c,
            'c99_gcc' => $c,

            // --- C++: quatro entradas, uma fonte C++14-compatível ---
            'cpp_gpp13' => $cpp,
            'cpp14_gpp' => $cpp,
            'cpp17_gpp' => $cpp,
            'cpp_clang' => $cpp,

            // --- Java: duas LTS, mesma fonte, JDKs diferentes ---
            //
            // HIPÓTESE DERRUBADA nesta revisão: uma versão anterior desta
            // tabela marcava `java25` como DEFEITO_CONHECIDO no item do
            // caminho sem cgroup delegado, com a #325 aberta. A #325 foi
            // CONSERTADA e fechada, e o `master` de hoje já traz
            // `'java25' => null` em `memory_grace_mb` (config/autojudge.php).
            // Medido de novo nesta revisão: `java25` dá AC sem cgroup
            // delegado. Por isso a linha voltou a ser CONFORME -- e é o
            // próprio mecanismo da tabela funcionando: um defeito consertado
            // faz o teste falhar até alguém trocar a linha.
            'java25' => $java,
            'java21' => $java,

            // INVERTIDOS nesta revisão -- os três eram DEFEITO_CONHECIDO no
            // item de recursão pela #327, e o #345 os consertou sem tocar na
            // fonte da submissão: `sitecustomize.py` sobe o
            // `setrecursionlimit` do CPython e `node/run.sh` passa
            // `--stack-size` casado com o `ulimit -s` real. Os três ficaram
            // vermelhos dizendo "veio AC", que é o sinal que esta suíte
            // existe para dar. Medidos de novo aqui: AC.
            'py3' => ['familia' => 'py', 'itens' => $tudoConforme],
            'js_node24' => ['familia' => 'js', 'itens' => $tudoConforme],
            'ts' => ['familia' => 'ts', 'itens' => $tudoConforme],
            'kt' => ['familia' => 'kt', 'itens' => $tudoConforme],
            'cs_dotnet' => ['familia' => 'cs', 'itens' => $tudoConforme],
            'rs' => ['familia' => 'rs', 'itens' => $tudoConforme],
            'go' => ['familia' => 'go', 'itens' => $tudoConforme],
            // INVERTIDO nesta revisão: o item de memória era
            // DEFEITO_CONHECIDO pela #324 (o `run_command` era `php
            // {source}`, sem `-d memory_limit`, então valia o teto de 128 MB
            // do interpretador e estourar dava RE, nunca MLE). O #345 passou
            // a `php -d memory_limit={memory}M` e este caso ficou vermelho
            // com "veio MLE". Medido de novo aqui: MLE.
            'php' => $tudoConformePhp,
            'rb' => ['familia' => 'rb', 'itens' => $tudoConforme],
            'perl' => ['familia' => 'perl', 'itens' => $tudoConforme],
            'pas_fpc' => ['familia' => 'pas', 'itens' => $tudoConforme],

            'sh' => ['familia' => 'sh', 'itens' => array_merge($tudoConforme, $recursaoLimitada)],

            // ---------------------------------------------------------
            // sed -- o caso em que "não se aplica" é a resposta honesta
            // para a maior parte da tabela.
            //
            // `sed` está ativo (#305) porque o toolchain já estava na
            // imagem, e não porque alguém vá resolver um problema de
            // maratona com ele. O que se pode provar sobre sed é pouco, e
            // fingir o contrário seria o "verde contra mecanismo que não
            // pode funcionar" que este repositório já conhece.
            //
            // Medido: o `sed` da imagem é o do BusyBox, e não o GNU
            // ("This is not GNU sed version 4.0").
            // ---------------------------------------------------------
            'sed' => ['familia' => 'sed', 'itens' => array_merge($tudoConforme, [
                // INVERTIDO nesta revisão, e é o mecanismo funcionando: o
                // item de CE era DEFEITO_CONHECIDO pela #323 (o
                // `compile_command` era `sed -n "q" {source}`, em que
                // `{source}` é a ENTRADA do sed e não o script, então
                // nenhum script era analisado). O #345 trocou o comando por
                // `sed -n -f {source} < /dev/null`, e o teste FICOU
                // VERMELHO dizendo "veio CE, o defeito foi consertado".
                // Medido de novo aqui: script inválido recebe CE com
                // `sed: unsupported command Z`.
                self::ITEM_RE => self::NAO_SE_APLICA,
                self::ITEM_MLE => self::NAO_SE_APLICA,
                self::ITEM_SAIDA => self::NAO_SE_APLICA,
                self::ITEM_PONTO => self::NAO_SE_APLICA,
                self::ITEM_ENTRADA => self::NAO_SE_APLICA,
                self::ITEM_RECURSAO => self::NAO_SE_APLICA,
                self::ITEM_STDERR => self::NAO_SE_APLICA,
            ])],

            // ---------------------------------------------------------
            // Scratch -- o que se mede é o que os construtores de `.sb3`
            // em Tests\Support\ScratchProject já sabem montar. O resto é
            // lacuna declarada, e não cobertura fingida.
            // ---------------------------------------------------------
            'scratch' => ['familia' => 'scratch', 'itens' => array_merge($tudoConforme, [
                self::ITEM_RE => self::NAO_SE_APLICA,
                self::ITEM_SAIDA => self::NAO_SE_APLICA,
                self::ITEM_MLE => self::NAO_MEDIDO,
                self::ITEM_PONTO => self::NAO_MEDIDO,
                self::ITEM_ENTRADA => self::NAO_MEDIDO,
                self::ITEM_RECURSAO => self::NAO_MEDIDO,
                self::ITEM_STDERR => self::NAO_SE_APLICA,
            ])],

            // ---------------------------------------------------------
            // Issue #305, Lote C -- as linguagens que os PRs #307 e
            // #310 acrescentaram depois que esta suíte foi escrita. Todas
            // medidas item a item nesta revisão, com os comandos do
            // catálogo, dentro do sandbox.
            //
            // Quatro delas entram com defeito medido no item de recursão --
            // e as quatro são da mesma família da #327. Ver defeitos().
            // ---------------------------------------------------------
            'lua' => ['familia' => 'lua', 'itens' => $tudoConforme],
            'awk' => ['familia' => 'awk', 'itens' => $tudoConforme],
            'tcl' => ['familia' => 'tcl', 'itens' => array_merge($tudoConforme, $recursaoLimitada)],
            'r' => ['familia' => 'r', 'itens' => array_merge($tudoConforme, $recursaoLimitada)],
            'clj' => ['familia' => 'clj', 'itens' => array_merge($tudoConforme, $recursaoLimitada)],
            // `erl` e `ex` saíram de `is_active` no #346 (a BEAM não sobe de
            // forma confiável em x86_64 -- #339). As linhas FICAM: o
            // provedor de dados filtra por `is_active`, então elas não
            // rodam hoje, e no dia em que houver release do OTP com a
            // correção a medição volta sem ninguém ter de reescrevê-la.
            // Foram medidas enquanto estavam ativas, e passavam nos onze
            // itens.
            'erl' => ['familia' => 'erl', 'itens' => $tudoConforme],
            'ex' => ['familia' => 'ex', 'itens' => $tudoConforme],

            // Common Lisp: DUAS implementações, UMA fonte -- o mesmo
            // arranjo de `c_gcc13`/`c_clang17`. O que as separa não é o
            // programa, é o que cada uma aguenta: medido, o SBCL faz 10^4
            // níveis de recursão e o CLISP 2.49 estoura a pilha entre 3000
            // e 5000. O CLISP ao lado do SBCL é o controle que torna isso
            // uma afirmação sobre a IMPLEMENTAÇÃO, e não sobre o programa.
            'lisp_sbcl' => ['familia' => 'lisp', 'itens' => $tudoConforme],
            'lisp_clisp' => ['familia' => 'lisp', 'itens' => array_merge($tudoConforme, $recursaoLimitada)],

            'scm' => ['familia' => 'scm', 'itens' => $tudoConforme],
            'rkt' => ['familia' => 'rkt', 'itens' => $tudoConforme],
            'zig' => ['familia' => 'zig', 'itens' => $tudoConforme],
            'nim' => ['familia' => 'nim', 'itens' => array_merge($tudoConforme, $recursaoLimitada)],
            'cr' => ['familia' => 'cr', 'itens' => $tudoConforme],
            'd_ldc' => ['familia' => 'd', 'itens' => $tudoConforme],
            'hs' => ['familia' => 'hs', 'itens' => $tudoConforme],
            'ml' => ['familia' => 'ml', 'itens' => $tudoConforme],
            'f90' => ['familia' => 'f90', 'itens' => $tudoConforme],
            'f77' => ['familia' => 'f77', 'itens' => $tudoConforme],
            'adb' => ['familia' => 'adb', 'itens' => $tudoConforme],

            // ---------------------------------------------------------
            // Issue #305, PR #336 -- as seis de fora da distribuição do
            // Alpine, ativadas depois do Lote C.
            // ---------------------------------------------------------
            'scala' => ['familia' => 'scala', 'itens' => $tudoConforme],
            'groovy' => ['familia' => 'groovy', 'itens' => array_merge($tudoConforme, $recursaoLimitada)],
            'dart' => ['familia' => 'dart', 'itens' => $tudoConforme],
            'prolog_swi' => ['familia' => 'prolog_swi', 'itens' => $tudoConforme],

            // O irmão que se comporta errado -- e o `prolog_swi` logo acima,
            // com o MESMO erro, é o controle que mostra que a diferença é do
            // runtime e não da linguagem: o SWI sai com 2 e escreve no
            // stderr, e recebe RE. Ver defeitos() e a #347.
            'prolog_gnu' => ['familia' => 'prolog_gnu', 'itens' => array_merge($tudoConforme, [
                self::ITEM_MLE => self::NAO_SE_APLICA,
            ])],

            'cob' => ['familia' => 'cob', 'itens' => $tudoConforme],

            'portugol_studio' => ['familia' => 'portugol', 'itens' => array_merge($tudoConforme, $recursaoLimitada, [
                self::ITEM_SAIDA => self::NAO_SE_APLICA,
                self::ITEM_STDERR => self::NAO_SE_APLICA,
                self::ITEM_MLE => self::NAO_MEDIDO,
            ])],
        ];
    }

    /**
     * O registro dos DEFEITOS medidos: o veredito ERRADO que o juiz produz
     * hoje, o veredito que deveria produzir, a issue aberta e a medição.
     *
     * HOJE ESTA TABELA ESTÁ VAZIA, e isso é um resultado, não um descuido.
     * Ela teve seis entradas ao longo desta auditoria, e as seis saíram
     * daqui do único jeito certo: a issue foi consertada e o teste ficou
     * VERMELHO avisando.
     *
     *   `sed`/CE            #323, consertada pelo #345
     *   `php`/MLE           #324, consertada pelo #345
     *   a agulha do CE de C# #331, consertada pelo #345
     *   `py3`,`js_node24`,`ts`/recursão  #327, consertadas pelo #345
     *   `portugol_studio`/RE #301, consertada pelo #351
     *   `prolog_gnu`/RE      #347 (aberta por esta auditoria), pelo #351
     *
     * Em nenhum desses casos alguém precisou lembrar de voltar aqui: o
     * conserto entrou, a suíte reprovou com "veio o veredito correto, o
     * defeito FOI CONSERTADO", e a asserção foi invertida. É exatamente o
     * que o contrato no topo desta classe promete, e ficou provado em seis
     * ocasiões reais em vez de em teoria.
     *
     * A estrutura fica porque a próxima linguagem com defeito vai precisar
     * dela, e o guarda que exige `issue` e `nota` continua ativo.
     *
     * @return array<string, array<string, array{veredito: string, correto: string, issue: string, nota: string}>>
     */
    public static function defeitos(): array
    {
        return [];
    }

    /**
     * O registro dos LIMITES DE LINGUAGEM medidos -- ver a constante
     * self::LIMITE_DA_LINGUAGEM para a diferença em relação a um defeito.
     *
     * Todos os de hoje são o mesmo item, recursão de 10^4 níveis, e seis
     * vêm da #327: a issue foi FECHADA (#345 consertou Python, Node e
     * TypeScript, que tinham botão; o #350 documentou o Bash, que não tem).
     * O que sobrou ali não é defeito do juiz -- é a profundidade que cada
     * runtime aguenta, medida uma a uma, e está no manual do organizador
     * para quem escreve o problema.
     *
     * As outras duas -- `clj` e `portugol_studio` -- não vêm de issue
     * nenhuma: vêm desta suíte. A tabela as declarava CONFORME, a primeira
     * execução dentro da imagem do juiz respondeu `RE` nas duas, e o teste
     * ficou vermelho. A #327 tinha olhado 24 linguagens; hoje são 48, e
     * estas duas estavam no pedaço que ninguém tinha medido.
     *
     * @return array<string, array<string, array{veredito: string, porque: string, onde: string}>>
     */
    public static function limites(): array
    {
        return [
            'sh' => [
                self::ITEM_RECURSAO => [
                    'veredito' => 'TLE',
                    'porque' => 'o bash não estoura a pilha em 10^4 níveis: ele fica lento demais e é morto '
                        .'pelo limite de CPU. O custo é da ordem de n^4 -- medido no #350: profundidade 400 '
                        .'custa 3,12 s de CPU e 500 custa 7,37 s, ou seja por volta de 450 já se estourou um '
                        .'limite de 5 s. A profundidade 10^4 está fora de alcance por várias ordens de '
                        .'grandeza, e NÃO há o que configurar: não existe `--stack-size` de shell.',
                    'onde' => 'docs/manuais/organizador.md, "Recursão profunda: o Bash não aguenta"',
                ],
            ],
            'tcl' => [
                self::ITEM_RECURSAO => [
                    'veredito' => 'RE',
                    'porque' => 'o teto é do INTERPRETADOR, e não da pilha: `interp recursionlimit {}` '
                        .'devolve 1000 nesta imagem (medido). A 1000ª chamada morre com "too many nested '
                        .'evaluations (infinite loop?)". Subir o teto exigiria um `interp recursionlimit` '
                        .'DENTRO da submissão, e o que este item mede é o que um competidor escreve.',
                    'onde' => 'docs/manuais/organizador.md, tabela de profundidade por linguagem',
                ],
            ],
            'r' => [
                self::ITEM_RECURSAO => [
                    'veredito' => 'RE',
                    'porque' => '`options(expressions)` vale 5000 e cada nível desta função gasta mais de '
                        .'uma "expressão". Medido degrau a degrau: f(2000) devolve o valor certo e f(5000) '
                        .'já morre com "evaluation nested too deeply". NÃO é a pilha de C -- '
                        .'`Cstack_info()[["size"]]` é de 7 969 177 bytes e sobra.',
                    'onde' => 'docs/manuais/organizador.md, tabela de profundidade por linguagem',
                ],
            ],
            'lisp_clisp' => [
                self::ITEM_RECURSAO => [
                    'veredito' => 'RE',
                    'porque' => 'o CLISP 2.49 estoura a pilha ("*** - Lisp stack overflow. RESET"). Medido '
                        .'o corte: f(3000) devolve 4501500 e f(5000) já estoura. O controle que torna isto '
                        .'uma afirmação sobre a IMPLEMENTAÇÃO e não sobre o programa é o `lisp_sbcl`, que '
                        .'compila a MESMA fonte, byte a byte, e faz os 10^4 níveis com AC.',
                    'onde' => 'docs/manuais/organizador.md, tabela de profundidade por linguagem',
                ],
            ],
            'groovy' => [
                self::ITEM_RECURSAO => [
                    'veredito' => 'RE',
                    'porque' => '`java.lang.StackOverflowError`, e o corte é raso: medido por bisseção, 800 '
                        .'níveis passam (320400) e 900 estouram. NÃO é "a pilha da JVM é curta" -- o '
                        .'controle é o `scala`, que na MESMA JVM faz a mesma recursão e devolve 50005000. '
                        .'O custo por quadro é do Groovy, que despacha dinamicamente.',
                    'onde' => 'docs/manuais/organizador.md, tabela de profundidade por linguagem',
                ],
            ],
            // As duas entradas abaixo NÃO vieram da #327: vieram desta suíte,
            // na primeira vez que ela rodou dentro da imagem do juiz. A
            // tabela afirmava CONFORME para as duas, o juiz respondeu RE, e
            // o teste ficou vermelho -- que é exatamente o que ele existe
            // para fazer. O que segue é a tabela corrigida para o medido.
            'clj' => [
                self::ITEM_RECURSAO => [
                    'veredito' => 'RE',
                    'porque' => '`Execution error (StackOverflowError) at user/f (solution.clj:2).` A '
                        .'recursão deste item é `(+ n (f (dec n)))`, que NÃO é de cauda -- `recur` não se '
                        .'aplica a ela, e o que o item mede é o que um competidor escreve. O controle que '
                        .'torna isto uma afirmação sobre a IMPLEMENTAÇÃO e não sobre a JVM é o `scala`, que '
                        .'na MESMA JVM faz os 10^4 níveis e devolve 50005000: o que é caro é o quadro do '
                        .'Clojure (despacho por `IFn.invoke` e aritmética boxeada em `clojure.lang.Numbers`), '
                        .'como no `groovy`. Dos três da JVM, só o Scala passa. NÃO medimos o corte exato nem '
                        .'o tamanho da pilha padrão. O que está verificado no repositório é que '
                        .'`docker/judge/bin/clojure-run` é `java -cp /usr/share/clojure/clojure.jar '
                        .'clojure.main`, SEM `-Xss`: a pilha é a padrão da JVM. Um `-Xss` ali é candidato a '
                        .'conserto do nosso lado, e não foi medido -- quem for medir, saiba que este teste '
                        .'fica vermelho quando funcionar, e é assim que se descobre.',
                    'onde' => 'docs/manuais/organizador.md, tabela de profundidade por linguagem',
                ],
            ],
            'portugol_studio' => [
                self::ITEM_RECURSAO => [
                    'veredito' => 'RE',
                    'porque' => 'quem detecta e aborta é o PRÓPRIO núcleo do Portugol Studio, antes de a '
                        .'pilha da JVM estourar: "Ocorreu um estouro de pilha de memória no programa", com '
                        .'`Linha: 3, Coluna: 8` -- a chamada recursiva. NÃO é a #301 de volta: lá o erro de '
                        .'execução virava `WA` mudo, e aqui o diagnóstico chega ao stderr e o veredito é '
                        .'`RE`, ou seja é o conserto do #351 funcionando. O corte exato não foi medido. '
                        .'RESSALVA PARA QUEM ESCREVE O PROBLEMA: a mensagem do núcleo AFIRMA que falta '
                        .'condição de parada e mostra um exemplo de recursão infinita -- e neste caso ela '
                        .'está errada, porque a condição existe. A equipe vai ler um diagnóstico que aponta '
                        .'para o defeito errado.',
                    'onde' => 'docs/manuais/organizador.md, tabela de profundidade por linguagem',
                ],
            ],
            'nim' => [
                self::ITEM_RECURSAO => [
                    'veredito' => 'RE',
                    'porque' => 'este é o único dos oito com conserto do nosso lado já MEDIDO, e por isso vale '
                        .'ler duas vezes: não é limite do runtime, é o comando do catálogo. O '
                        .'`compile_command` é `nim c -o:{output} {source}`, sem `-d:release`, e o build de '
                        .'depuração do Nim impõe um teto de 2000 quadros -- "call depth limit reached in a '
                        .'debug build (2000 function calls)". Medido: o MESMO programa com `nim c '
                        .'-d:release` imprime 50005000 e sai com 0. Fica como LIMITE e não como defeito '
                        .'porque a #327 foi fechada e ninguém decidiu mexer no comando; se alguém decidir, '
                        .'este teste fica vermelho e avisa.',
                    'onde' => 'docs/manuais/organizador.md, tabela de profundidade por linguagem',
                ],
            ],
        ];
    }

    /**
     * A justificativa de cada `NAO_SE_APLICA` e `NAO_MEDIDO` da tabela.
     *
     * Existe como dado, e não como comentário, porque um teste desta suíte
     * exige que TODO estado não-conforme tenha uma -- é isso que impede
     * "não se aplica" de virar o lugar onde as medições difíceis somem.
     *
     * @return array<string, array<string, string>>
     */
    public static function justificativas(): array
    {
        return [
            'prolog_gnu' => [
                self::ITEM_MLE => 'as pilhas do GNU Prolog têm tamanho FIXO e a global está em 32 MB, '
                    .'então não existe programa que chegue perto dos 256 MB do problema. Medido de duas '
                    .'formas independentes, as duas com o run_command do catálogo: `length(L, 100000000)` '
                    .'e 20 milhões de `assertz` morrem com o MESMO "Fatal Error: global stack overflow '
                    .'(size: 32768 Kb ... GLOBALSZ)" e saída 1, com pico de RSS de 34 976 kB -- um oitavo '
                    .'do limite. O único jeito de subir isso é a variável de ambiente GLOBALSZ, que a '
                    .'submissão não controla. Um item aqui mediria o limite do RUNTIME, e não o do juiz.',
            ],
            'sed' => [
                self::ITEM_RE => 'sed não tem erro de execução dirigido por dados: o único modo de falha '
                    .'é o parse do script, que acontece antes de a entrada ser lida -- e é exatamente o item de CE.',
                self::ITEM_MLE => 'qualquer crescimento de memória em sed exige laço infinito sobre o hold '
                    .'space (`:a` / `G` / `H` / `ba`), então o TLE chega antes do MLE e não há como separar os dois.',
                self::ITEM_SAIDA => 'medido: o sed do BusyBox não aceita `q<código>` ("sed: missing command"), '
                    .'então não existe script sed que imprima a saída certa e saia com código diferente de zero.',
                self::ITEM_PONTO => 'sed não tem aritmética nem ponto flutuante.',
                self::ITEM_ENTRADA => 'sed não soma: nenhum script sed reduz 10^5 inteiros a um total.',
                self::ITEM_RECURSAO => 'sed não tem sub-rotinas; `b` é desvio, e não chamada com pilha.',
                self::ITEM_STDERR => 'o sed do BusyBox não tem como escrever em stderr a partir do script.',
            ],
            'scratch' => [
                self::ITEM_RE => 'a VM do Scratch não tem estado de erro de execução: divisão por zero dá '
                    .'`Infinity` e índice fora da lista dá string vazia. Não há programa Scratch que morra.',
                self::ITEM_SAIDA => 'Scratch não tem primitiva de código de saída; quem decide o código de '
                    .'saída é o `scratch-run`, e não o projeto julgado.',
                self::ITEM_MLE => 'exigiria um construtor novo de `.sb3` que cresça uma lista sem parar; '
                    .'não construído nesta auditoria. Lacuna conhecida, não cobertura.',
                self::ITEM_PONTO => 'exigiria um construtor novo de `.sb3`; não construído nesta auditoria.',
                self::ITEM_ENTRADA => 'exigiria um construtor novo de `.sb3` com laço de leitura; não '
                    .'construído nesta auditoria.',
                self::ITEM_RECURSAO => 'exigiria um construtor novo de `.sb3` com bloco `define` recursivo; '
                    .'não construído nesta auditoria.',
                self::ITEM_STDERR => 'o projeto Scratch não escolhe o canal de saída; quem escreve em stderr '
                    .'é o próprio `scratch-run` (é o motivo do conserto da #268).',
            ],
            'portugol_studio' => [
                self::ITEM_SAIDA => 'Portugol Studio não tem primitiva de código de saída; quem devolve o '
                    .'código é o console que o invoca -- e é justamente o que a #301 mostra estar quebrado.',
                self::ITEM_STDERR => 'Portugol Studio não tem canal de erro separado: `escreva` escreve em '
                    .'stdout e não há `escreva_erro`.',
                self::ITEM_MLE => 'Portugol declara vetor com tamanho constante e não tem alocação dinâmica; '
                    .'um vetor grande o bastante é recusado na análise, que daria CE e não MLE. Não medido.',
            ],
        ];
    }

    /** @return string[] */
    private static function itens(): array
    {
        return [
            self::ITEM_RE, self::ITEM_CE, self::ITEM_TLE, self::ITEM_MLE,
            self::ITEM_SAIDA, self::ITEM_PONTO, self::ITEM_ENTRADA,
            self::ITEM_RECURSAO, self::ITEM_PARTIDA, self::ITEM_STDERR,
            self::ITEM_SEM_CGROUP,
        ];
    }

    // ==================================================================
    // OS PROGRAMAS
    // ==================================================================

    /**
     * Um programa por família e por item.
     *
     * Cada entrada é `[arquivo, fonte]`. A entrada padrão e a saída esperada
     * vêm de self::provaDoItem(); só as famílias que precisam de outra
     * coisa (Portugol lê um valor por linha) a sobrescrevem.
     *
     * @return array<string, array<string, array{0: string, 1: string}>>
     */
    public static function programas(): array
    {
        $familias = self::programasPorItem();

        // O controle positivo entra por fora da tabela de itens: ele não é
        // um item medido, é a condição sem a qual nenhum item conclui nada.
        foreach (self::controlesPositivos() as $familia => $programa) {
            $familias[$familia][self::ITEM_CONTROLE] = $programa;

            // O item do caminho sem cgroup roda o MESMO programa correto: o
            // que muda entre ele e o controle positivo não é o programa, é
            // o mecanismo de limite de memória por baixo.
            $familias[$familia][self::ITEM_SEM_CGROUP] = $programa;
        }

        return $familias;
    }

    /**
     * O `a+b` correto de cada família -- o mesmo problema, a mesma entrada,
     * a mesma saída esperada dos itens.
     *
     * Existe separado dos itens porque é o que torna o item de erro de
     * execução conclusivo: sem provar que ESTA linguagem, por ESTE caminho,
     * chega a `AC`, um `RE` não distingue "o juiz detectou o erro" de "o
     * juiz reprova tudo".
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function controlesPositivos(): array
    {
        return [
            'c' => ['solution.c', "#include <stdio.h>\nint main(void){int a,b;if(scanf(\"%d %d\",&a,&b)!=2)return 9;printf(\"%d\\n\",a+b);return 0;}\n"],
            'cpp' => ['solution.cpp', "#include <iostream>\nint main(){int a,b;if(!(std::cin>>a>>b))return 9;std::cout<<a+b<<std::endl;return 0;}\n"],
            'java' => ['Main.java', "import java.util.Scanner;\npublic class Main {\n    public static void main(String[] args) {\n        Scanner sc = new Scanner(System.in);\n        System.out.println(sc.nextInt() + sc.nextInt());\n    }\n}\n"],
            'py' => ['solution.py', "a, b = map(int, input().split())\nprint(a + b)\n"],
            'js' => ['solution.js', "const d = require('fs').readFileSync(0, 'utf8').trim().split(/\\s+/).map(Number);\nconsole.log(d[0] + d[1]);\n"],
            'ts' => ['solution.ts', "declare function require(name: string): any;\nconst d: number[] = require('fs').readFileSync(0, 'utf8').trim().split(/\\s+/).map(Number);\nconsole.log(d[0] + d[1]);\n"],
            'kt' => ['solution.kt', "fun main() {\n    val (a, b) = readLine()!!.trim().split(\" \").map { it.toInt() }\n    println(a + b)\n}\n"],
            'cs' => ['solution.cs', "using System;\nclass Program {\n  static void Main() {\n    var p = Console.ReadLine().Split(' ');\n    Console.WriteLine(int.Parse(p[0]) + int.Parse(p[1]));\n  }\n}\n"],
            'rs' => ['solution.rs', "use std::io::*;\nfn main() {\n    let mut s = String::new();\n    stdin().read_line(&mut s).unwrap();\n    let v: Vec<i64> = s.trim().split_whitespace().map(|x| x.parse().unwrap()).collect();\n    println!(\"{}\", v[0] + v[1]);\n}\n"],
            'go' => ['solution.go', "package main\n\nimport \"fmt\"\n\nfunc main() {\n\tvar a, b int\n\tfmt.Scan(&a, &b)\n\tfmt.Println(a + b)\n}\n"],
            'php' => ['solution.php', "<?php\nfscanf(STDIN, \"%d %d\", \$a, \$b);\necho \$a + \$b, PHP_EOL;\n"],
            'rb' => ['solution.rb', "a, b = gets.split.map(&:to_i)\nputs a + b\n"],
            'perl' => ['solution.pl', "my @p = split ' ', <STDIN>;\nprint \$p[0] + \$p[1], \"\\n\";\n"],
            'sh' => ['solution.sh', "read a b\necho \$((a + b))\n"],
            'sed' => ['solution.sed', "s/3 5/8/\n"],
            'pas' => ['solution.pas', "program Solution;\nvar a, b: integer;\nbegin\n  readln(a, b);\n  writeln(a + b);\nend.\n"],
            'scratch' => ['solution.sb3', file_get_contents(ScratchProject::sumOfTwoTokens())],
            'portugol' => ['solution.por', "programa {\n  funcao inicio() {\n    inteiro a, b\n    leia(a)\n    leia(b)\n    escreva(a + b, \"\\n\")\n  }\n}\n"],

            // Issue #305, Lote C. São, DE PROPÓSITO, as mesmas fontes que
            // MultiLanguageJudgingTest já usa: ali elas provam que a
            // linguagem compila e dá AC, e aqui são o controle positivo sem
            // o qual nenhum RE, CE, TLE ou MLE desta suíte conclui nada.
            // Duas fontes iguais em dois arquivos é redundância barata; um
            // controle positivo diferente do `a+b` já testado seria uma
            // variável a mais entre a medição e a conclusão.
            'lua' => ['solution.lua', "local a, b = io.read(\"n\", \"n\")\nprint(a + b)\n"],
            'awk' => ['solution.awk', "{ print \$1 + \$2 }\n"],
            'tcl' => ['solution.tcl', "lassign [split [string trim [gets stdin]]] a b\nputs [expr {\$a + \$b}]\n"],
            'r' => ['solution.r', "x <- scan(\"stdin\", n = 2, quiet = TRUE)\ncat(x[1] + x[2], \"\\n\", sep = \"\")\n"],
            'clj' => ['solution.clj', "(let [[a b] (map read-string (clojure.string/split (clojure.string/trim (read-line)) #\"\\s+\"))]\n  (println (+ a b)))\n"],
            // Erlang exige que o módulo tenha o nome do arquivo, e o
            // `run_command` chama `{classname}:main/0`.
            'erl' => ['solution.erl', "-module(solution).\n-export([main/0]).\nmain() ->\n    {ok, [A, B]} = io:fread(\"\", \"~d ~d\"),\n    io:format(\"~p~n\", [A + B]).\n"],
            'ex' => ['solution.ex', "[a, b] = IO.read(:line) |> String.split() |> Enum.map(&String.to_integer/1)\nIO.puts(a + b)\n"],
            'lisp' => ['solution.lisp', "(let ((a (read)) (b (read)))\n  (format t \"~a~%\" (+ a b)))\n"],
            'scm' => ['solution.scm', "(let* ((a (read)) (b (read)))\n  (display (+ a b))\n  (newline))\n"],
            'rkt' => ['solution.rkt', "#lang racket\n(define a (read))\n(define b (read))\n(displayln (+ a b))\n"],
            'zig' => ['solution.zig', "const std = @import(\"std\");\npub fn main() !void {\n    var buf: [256]u8 = undefined;\n    const n = try std.posix.read(0, &buf);\n    var it = std.mem.tokenizeAny(u8, buf[0..n], \" \\t\\r\\n\");\n    const a = try std.fmt.parseInt(i64, it.next().?, 10);\n    const b = try std.fmt.parseInt(i64, it.next().?, 10);\n    var saida: [64]u8 = undefined;\n    const sres = try std.fmt.bufPrint(&saida, \"{d}\\n\", .{a + b});\n    var io: std.Io.Threaded = .init_single_threaded;\n    defer io.deinit();\n    try std.Io.File.stdout().writeStreamingAll(io.io(), sres);\n}\n"],
            'nim' => ['solution.nim', "import std/strutils\nlet p = stdin.readLine().splitWhitespace()\necho parseInt(p[0]) + parseInt(p[1])\n"],
            'cr' => ['solution.cr', "a, b = gets.not_nil!.split.map(&.to_i)\nputs a + b\n"],
            'd' => ['solution.d', "import std.stdio;\nvoid main() {\n    int a, b;\n    readf(\" %d %d\", &a, &b);\n    writeln(a + b);\n}\n"],
            'hs' => ['solution.hs', "main :: IO ()\nmain = do\n  l <- getLine\n  let [a, b] = map read (words l) :: [Integer]\n  print (a + b)\n"],
            'ml' => ['solution.ml', "let () = Scanf.scanf \" %d %d\" (fun a b -> Printf.printf \"%d\\n\" (a + b))\n"],
            'f90' => ['solution.f90', "program solucao\n  integer :: a, b\n  read(*,*) a, b\n  write(*,'(I0)') a + b\nend program solucao\n"],
            // Formato FIXO: as seis colunas em branco no início de cada linha
            // não são estilo, são o F77.
            'f77' => ['solution.f', "      PROGRAM SOL\n      INTEGER A, B\n      READ(*,*) A, B\n      WRITE(*,'(I0)') A + B\n      END\n"],
            // `procedure Solution` e não `Main`: em Ada o nome da unidade tem
            // de casar com o nome do arquivo.
            'adb' => ['solution.adb', "with Ada.Text_IO; use Ada.Text_IO;\nwith Ada.Integer_Text_IO; use Ada.Integer_Text_IO;\nprocedure Solution is\n   A, B : Integer;\nbegin\n   Get (A);\n   Get (B);\n   Put (A + B, Width => 0);\n   New_Line;\nend Solution;\n"],

            // Issue #305, PR #336. Mesmas fontes de MultiLanguageJudgingTest,
            // pelo mesmo motivo do bloco acima.
            'scala' => ['Main.scala', "object Main {\n  def main(args: Array[String]): Unit = {\n    val t = scala.io.StdIn.readLine().trim.split(\"\\\\s+\").map(_.toInt)\n    println(t(0) + t(1))\n  }\n}\n"],
            'groovy' => ['solution.groovy', "def linha = System.in.newReader().readLine()\ndef p = linha.trim().split(/\\s+/)\nprintln(p[0].toInteger() + p[1].toInteger())\n"],
            'dart' => ['solution.dart', "import 'dart:io';\nvoid main() {\n  var p = stdin.readLineSync()!.trim().split(RegExp(r'\\s+'));\n  print(int.parse(p[0]) + int.parse(p[1]));\n}\n"],
            // SWI-Prolog: `main/0` é o predicado que o `run_command` chama
            // (`-g "main,halt"`), então o nome é contrato do catálogo.
            'prolog_swi' => ['solution.pl', "main :-\n    read_line_to_string(user_input, S),\n    split_string(S, \" \", \"\", P),\n    [A, B] = P,\n    number_string(X, A),\n    number_string(Y, B),\n    Z is X + Y,\n    write(Z), nl.\n"],
            // GNU Prolog: `:- initialization(main).` é o que faz o binário
            // compilado rodar alguma coisa.
            'prolog_gnu' => ['solution.pro', "main :-\n    read_integer(A),\n    read_integer(B),\n    C is A + B,\n    write(C), nl.\n:- initialization(main).\n"],
            // COBOL: `DISPLAY R` sobre um `PIC S9(9)` imprime `+000000008`, e
            // não `8` -- medido na #305. O campo editado com `FUNCTION TRIM` é
            // o que produz a saída que um problema de maratona espera.
            'cob' => ['solution.cob', "       IDENTIFICATION DIVISION.\n       PROGRAM-ID. SOMA.\n       DATA DIVISION.\n       WORKING-STORAGE SECTION.\n       01 LINHA PIC X(80).\n       01 A     PIC S9(9).\n       01 B     PIC S9(9).\n       01 R     PIC S9(9).\n       01 E     PIC -(9)9.\n       PROCEDURE DIVISION.\n           ACCEPT LINHA\n           UNSTRING LINHA DELIMITED BY ALL SPACES INTO A B\n           COMPUTE R = A + B\n           MOVE R TO E\n           DISPLAY FUNCTION TRIM(E)\n           STOP RUN.\n"],
        ];
    }

    /**
     * @return array<string, array<string, array{0: string, 1: string}>>
     */
    private static function programasPorItem(): array
    {
        return [
            'c' => self::programasC(),
            'cpp' => self::programasCpp(),
            'java' => self::programasJava(),
            'py' => self::programasPython(),
            'js' => self::programasJavaScript(),
            'ts' => self::programasTypeScript(),
            'kt' => self::programasKotlin(),
            'cs' => self::programasCSharp(),
            'rs' => self::programasRust(),
            'go' => self::programasGo(),
            'php' => self::programasPhp(),
            'rb' => self::programasRuby(),
            'perl' => self::programasPerl(),
            'sh' => self::programasBash(),
            'sed' => self::programasSed(),
            'pas' => self::programasPascal(),
            'scratch' => self::programasScratch(),
            'portugol' => self::programasPortugol(),

            // Issue #305, Lote C -- as dezenove famílias que os PRs #307 e
            // #310 acrescentaram ao catálogo. Cada programa abaixo foi
            // compilado e executado nesta imagem com os comandos EXATOS do
            // catálogo antes de entrar aqui.
            'lua' => self::programasLua(),
            'awk' => self::programasAwk(),
            'tcl' => self::programasTcl(),
            'r' => self::programasR(),
            'clj' => self::programasClojure(),
            'erl' => self::programasErlang(),
            'ex' => self::programasElixir(),
            'lisp' => self::programasLisp(),
            'scm' => self::programasScheme(),
            'rkt' => self::programasRacket(),
            'zig' => self::programasZig(),
            'nim' => self::programasNim(),
            'cr' => self::programasCrystal(),
            'd' => self::programasD(),
            'hs' => self::programasHaskell(),
            'ml' => self::programasOCaml(),
            'f90' => self::programasFortran(),
            'f77' => self::programasFortran77(),
            'adb' => self::programasAda(),

            // Issue #305, PR #336 -- as seis linguagens de fora da
            // distribuição do Alpine, ativadas depois das do Lote C.
            'scala' => self::programasScala(),
            'groovy' => self::programasGroovy(),
            'dart' => self::programasDart(),
            'prolog_swi' => self::programasPrologSwi(),
            'prolog_gnu' => self::programasPrologGnu(),
            'cob' => self::programasCobol(),
        ];
    }

    /**
     * C -- uma fonte para `c_gcc13`, `c_clang17` e `c99_gcc`, escrita em
     * C99 porque uma das três compila com `-std=c99`.
     *
     * MEDIÇÃO QUE MUDOU O PROGRAMA: divisão inteira por zero NÃO serve como
     * erro de execução aqui. Medido nesta imagem (aarch64), `a/z` com
     * `volatile int z = 0` imprime `div=0` e sai com 0 -- o `sdiv` do ARM64
     * não gera exceção, ao contrário do `idiv` do x86. O programa usa
     * escrita em ponteiro nulo, que é SIGSEGV nas duas arquiteturas.
     */
    private static function programasC(): array
    {
        $arquivo = 'solution.c';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
#include <stdio.h>
int main(void) {
    int a, b;
    if (scanf("%d %d", &a, &b) != 2) return 9;
    volatile int *p = 0;
    *p = a + b;
    printf("%d\n", a + b);
    return 0;
}

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
#include <stdio.h>
int main(void) {
    isto nao e c @@@
}

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
#include <stdio.h>
int main(void) {
    volatile long x = 0;
    for (;;) x++;
    printf("%ld\n", x);
    return 0;
}

FONTE],
            // MEDIÇÃO QUE MUDOU O PROGRAMA: a primeira versão guardava os
            // blocos em variável local e nunca os lia de volta. O gcc
            // mantinha a alocação e dava MLE; o **clang 22 removia
            // malloc+memset inteiros** (a memória não é observável) e o
            // mesmo programa dava AC em 0 ms. Guardar os ponteiros num
            // vetor global e somar um byte de cada torna a alocação
            // observável, e nenhum dos dois pode descartá-la.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
static char *blocos[64];
int main(void) {
    size_t bloco = 32u * 1024u * 1024u;
    long long soma = 0;
    int i;
    for (i = 0; i < 64; i++) {
        blocos[i] = (char *) malloc(bloco);
        if (blocos[i] == 0) return 7;
        memset(blocos[i], i + 1, bloco);
    }
    for (i = 0; i < 64; i++) soma += blocos[i][bloco - 1];
    printf("%d\n", soma != 0 ? 8 : 9);
    return 0;
}

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
#include <stdio.h>
int main(void) {
    printf("8\n");
    fflush(stdout);
    return 3;
}

FONTE],
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
#include <stdio.h>
int main(void) {
    printf("%.2f\n", 3.14159);
    return 0;
}

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
#include <stdio.h>
int main(void) {
    long long s = 0;
    int x;
    while (scanf("%d", &x) == 1) s += x;
    printf("%lld\n", s);
    return 0;
}

FONTE],
            // O `pad` volátil existe para o `-O2` não transformar a
            // recursão em laço: sem ele o que o teste mediria seria a
            // otimização do compilador, e não a pilha.
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
#include <stdio.h>
long long f(int n) {
    volatile char pad[64];
    pad[0] = (char) n;
    if (n == 0) return 0;
    return n + f(n - 1) + (pad[0] - (char) n);
}
int main(void) {
    printf("%lld\n", f(10000));
    return 0;
}

FONTE],
            self::ITEM_PARTIDA => [$arquivo, "#include <stdio.h>\nint main(void) { printf(\"8\\n\"); return 0; }\n"],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
#include <stdio.h>
int main(void) {
    int a, b;
    if (scanf("%d %d", &a, &b) != 2) return 9;
    fprintf(stderr, "depuracao: li %d e %d\n", a, b);
    printf("%d\n", a + b);
    return 0;
}

FONTE],
        ];
    }

    /** C++ -- fonte compatível com C++14, porque uma das quatro entradas usa `-std=c++14`. */
    private static function programasCpp(): array
    {
        $arquivo = 'solution.cpp';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
#include <iostream>
#include <vector>
int main() {
    int a, b;
    if (!(std::cin >> a >> b)) return 9;
    std::vector<int> v(1, a);
    std::cout << v.at(b + 10) << std::endl;
    return 0;
}

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
#include <iostream>
int main() {
    isto nao e cpp @@@
}

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
#include <iostream>
int main() {
    volatile long x = 0;
    for (;;) x++;
    std::cout << x << std::endl;
    return 0;
}

FONTE],
            // Ver o comentário do mesmo item em C: a soma no fim é o que
            // impede o otimizador de descartar a alocação.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
#include <iostream>
#include <vector>
int main() {
    std::vector<std::vector<char> > todos;
    long long soma = 0;
    for (int i = 0; i < 64; i++) {
        todos.push_back(std::vector<char>(32u * 1024u * 1024u, (char) (i + 1)));
    }
    for (size_t i = 0; i < todos.size(); i++) soma += todos[i].back();
    std::cout << (soma != 0 ? 8 : 9) << std::endl;
    return 0;
}

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
#include <iostream>
int main() {
    std::cout << "8" << std::endl;
    return 3;
}

FONTE],
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
#include <cstdio>
int main() {
    std::printf("%.2f\n", 3.14159);
    return 0;
}

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
#include <iostream>
int main() {
    long long s = 0, x;
    while (std::cin >> x) s += x;
    std::cout << s << std::endl;
    return 0;
}

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
#include <iostream>
long long f(int n) {
    volatile char pad[64];
    pad[0] = (char) n;
    if (n == 0) return 0;
    return n + f(n - 1) + (pad[0] - (char) n);
}
int main() {
    std::cout << f(10000) << std::endl;
    return 0;
}

FONTE],
            self::ITEM_PARTIDA => [$arquivo, "#include <iostream>\nint main() { std::cout << \"8\" << std::endl; return 0; }\n"],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
#include <iostream>
int main() {
    int a, b;
    if (!(std::cin >> a >> b)) return 9;
    std::cerr << "depuracao: li " << a << " e " << b << std::endl;
    std::cout << (a + b) << std::endl;
    return 0;
}

FONTE],
        ];
    }

    /**
     * Java -- a mesma fonte para as duas LTS. O arquivo precisa se chamar
     * `Main.java`: o `run_command` usa `{classname}`, que é o basename.
     */
    private static function programasJava(): array
    {
        $arquivo = 'Main.java';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
public class Main {
    public static void main(String[] args) {
        int[] v = new int[1];
        System.out.println(v[5]);
    }
}

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
public class Main {
    public static void main(String[] args) {
        isto nao e java @@@
    }
}

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
public class Main {
    static volatile long x = 0;
    public static void main(String[] args) {
        while (true) x++;
    }
}

FONTE],
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
import java.util.ArrayList;
public class Main {
    public static void main(String[] args) {
        ArrayList<byte[]> todos = new ArrayList<>();
        for (int i = 0; i < 4096; i++) {
            byte[] bloco = new byte[8 * 1024 * 1024];
            java.util.Arrays.fill(bloco, (byte) (i + 1));
            todos.add(bloco);
        }
        System.out.println("8");
    }
}

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
public class Main {
    public static void main(String[] args) {
        System.out.println("8");
        System.out.flush();
        System.exit(3);
    }
}

FONTE],
            // `String.format` usa a locale padrão da JVM -- é o caminho
            // sensível a locale de propósito. `Double.toString` não seria.
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
public class Main {
    public static void main(String[] args) {
        System.out.println(String.format("%.2f", 3.14159));
    }
}

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
import java.util.Scanner;
public class Main {
    public static void main(String[] args) {
        Scanner sc = new Scanner(System.in);
        long s = 0;
        while (sc.hasNextLong()) s += sc.nextLong();
        System.out.println(s);
    }
}

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
public class Main {
    static long f(int n) { return n == 0 ? 0 : n + f(n - 1); }
    public static void main(String[] args) {
        System.out.println(f(10000));
    }
}

FONTE],
            self::ITEM_PARTIDA => [$arquivo, "public class Main {\n    public static void main(String[] args) { System.out.println(\"8\"); }\n}\n"],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
import java.util.Scanner;
public class Main {
    public static void main(String[] args) {
        Scanner sc = new Scanner(System.in);
        int a = sc.nextInt(), b = sc.nextInt();
        System.err.println("depuracao: li " + a + " e " + b);
        System.out.println(a + b);
    }
}

FONTE],
        ];
    }

    private static function programasPython(): array
    {
        $arquivo = 'solution.py';

        return [
            self::ITEM_RE => [$arquivo, "a, b = map(int, input().split())\nprint((a + b) // (b - b))\n"],
            // O lixo vai na linha 3 para o diagnóstico ter uma linha
            // distinta de 1 -- "line 1" é o que qualquer erro de arquivo
            // vazio também diria.
            self::ITEM_CE => [$arquivo, "x = 1\ny = 2\ndef f(:\n    return 1\nprint(f())\n"],
            self::ITEM_TLE => [$arquivo, "x = 0\nwhile True:\n    x += 1\n"],
            self::ITEM_MLE => [$arquivo, "b = bytearray(1024 * 1024 * 1024)\nb[0] = 1\nprint(8)\n"],
            self::ITEM_SAIDA => [$arquivo, "import sys\nprint(8)\nsys.stdout.flush()\nsys.exit(3)\n"],
            self::ITEM_PONTO => [$arquivo, "print('%.2f' % 3.14159)\n"],
            self::ITEM_ENTRADA => [$arquivo, "import sys\nprint(sum(int(l) for l in sys.stdin))\n"],
            // Sem `sys.setrecursionlimit`, de propósito: é o que um
            // iniciante escreve, e é o que esta auditoria quer medir.
            self::ITEM_RECURSAO => [$arquivo, "def f(n):\n    return 0 if n == 0 else n + f(n - 1)\nprint(f(10000))\n"],
            self::ITEM_PARTIDA => [$arquivo, "print(8)\n"],
            self::ITEM_STDERR => [$arquivo, "import sys\na, b = map(int, input().split())\nsys.stderr.write('depuracao: li %d e %d\\n' % (a, b))\nprint(a + b)\n"],
        ];
    }

    private static function programasJavaScript(): array
    {
        $arquivo = 'solution.js';

        return [
            self::ITEM_RE => [$arquivo, "const o = null;\nconsole.log(o.valor);\n"],
            // Duas linhas de enchimento antes do lixo: o `node --check`
            // reporta a linha do TOKEN que falhou (a do `return`), e com o
            // erro no topo do arquivo o diagnóstico diria "linha 2" para
            // qualquer coisa.
            self::ITEM_CE => [$arquivo, "const a = 1;\nconst b = 2;\nfunction f( {\n  return 1;\n}\nconsole.log(f());\n"],
            self::ITEM_TLE => [$arquivo, "let x = 0;\nfor (;;) x++;\n"],
            // `Buffer.alloc` é memória fora do heap do V8, então quem tem de
            // barrar é o cgroup (ou o pico de RSS), e não o
            // `--max-old-space-size` que o `run_command` já passa.
            self::ITEM_MLE => [$arquivo, "const todos = [];\nfor (let i = 0; i < 4096; i++) todos.push(Buffer.alloc(8 * 1024 * 1024, i % 251));\nconsole.log(8);\n"],
            self::ITEM_SAIDA => [$arquivo, "console.log(8);\nprocess.exit(3);\n"],
            self::ITEM_PONTO => [$arquivo, "console.log((3.14159).toFixed(2));\n"],
            self::ITEM_ENTRADA => [$arquivo, "const linhas = require('fs').readFileSync(0, 'utf8').trim().split('\\n');\nlet s = 0;\nfor (const l of linhas) s += Number(l);\nconsole.log(s);\n"],
            self::ITEM_RECURSAO => [$arquivo, "function f(n) { return n === 0 ? 0 : n + f(n - 1); }\nconsole.log(f(10000));\n"],
            self::ITEM_PARTIDA => [$arquivo, "console.log(8);\n"],
            self::ITEM_STDERR => [$arquivo, "const d = require('fs').readFileSync(0, 'utf8').trim().split(/\\s+/).map(Number);\nprocess.stderr.write('depuracao: li ' + d[0] + ' e ' + d[1] + '\\n');\nconsole.log(d[0] + d[1]);\n"],
        ];
    }

    /**
     * TypeScript -- `npx tsc --strict` compila para `solution.js` e o
     * `run_command` executa esse `.js`.
     *
     * `declare` em vez de `@types/node`: os tipos do Node não estão
     * instalados globalmente, e um `import` os exigiria. Isso é o mesmo que
     * MultiLanguageJudgingTest já faz.
     *
     * O que NÃO se declara é o `console`: ele já vem de `lib.dom.d.ts`, e
     * declará-lo de novo é ERRO DE COMPILAÇÃO
     * (`TS2451: Cannot redeclare block-scoped variable 'console'`). Medido
     * nesta auditoria, e o sintoma foi um controle positivo que deu `CE`.
     *
     * O caso de RE precisa de `const o: any = null`: com `--strict`, um
     * `null.valor` literal é ERRO DE COMPILAÇÃO em TypeScript, e o que se
     * quer medir aqui é o erro de EXECUÇÃO.
     */
    private static function programasTypeScript(): array
    {
        $arquivo = 'solution.ts';
        $decl = "declare function require(name: string): any;\ndeclare const process: any;\ndeclare const Buffer: any;\n";

        return [
            self::ITEM_RE => [$arquivo, $decl."const o: any = null;\nconsole.log(o.valor);\n"],
            self::ITEM_CE => [$arquivo, $decl."function f( {\n  return 1;\n}\nconsole.log(f());\n"],
            self::ITEM_TLE => [$arquivo, $decl."let x = 0;\nfor (;;) x++;\n"],
            self::ITEM_MLE => [$arquivo, $decl."const todos: any[] = [];\nfor (let i = 0; i < 4096; i++) todos.push(Buffer.alloc(8 * 1024 * 1024, i % 251));\nconsole.log(8);\n"],
            self::ITEM_SAIDA => [$arquivo, $decl."console.log(8);\nprocess.exit(3);\n"],
            self::ITEM_PONTO => [$arquivo, $decl."console.log((3.14159).toFixed(2));\n"],
            self::ITEM_ENTRADA => [$arquivo, $decl."const linhas: string[] = require('fs').readFileSync(0, 'utf8').trim().split('\\n');\nlet s = 0;\nfor (const l of linhas) s += Number(l);\nconsole.log(s);\n"],
            self::ITEM_RECURSAO => [$arquivo, $decl."function f(n: number): number { return n === 0 ? 0 : n + f(n - 1); }\nconsole.log(f(10000));\n"],
            self::ITEM_PARTIDA => [$arquivo, $decl."console.log(8);\n"],
            self::ITEM_STDERR => [$arquivo, $decl."const d: number[] = require('fs').readFileSync(0, 'utf8').trim().split(/\\s+/).map(Number);\nprocess.stderr.write('depuracao: li ' + d[0] + ' e ' + d[1] + '\\n');\nconsole.log(d[0] + d[1]);\n"],
        ];
    }

    private static function programasKotlin(): array
    {
        $arquivo = 'solution.kt';

        return [
            self::ITEM_RE => [$arquivo, "fun main() {\n    val v = IntArray(1)\n    println(v[5])\n}\n"],
            self::ITEM_CE => [$arquivo, "fun main() {\n    isto nao e kotlin @@@\n}\n"],
            self::ITEM_TLE => [$arquivo, "fun main() {\n    var x = 0L\n    while (true) x++\n}\n"],
            self::ITEM_MLE => [$arquivo, "fun main() {\n    val todos = ArrayList<ByteArray>()\n    for (i in 0 until 4096) {\n        val b = ByteArray(8 * 1024 * 1024)\n        b.fill((i % 127).toByte())\n        todos.add(b)\n    }\n    println(8)\n}\n"],
            self::ITEM_SAIDA => [$arquivo, "fun main() {\n    println(8)\n    System.out.flush()\n    kotlin.system.exitProcess(3)\n}\n"],
            self::ITEM_PONTO => [$arquivo, "fun main() {\n    println(String.format(\"%.2f\", 3.14159))\n}\n"],
            self::ITEM_ENTRADA => [$arquivo, "fun main() {\n    var s = 0L\n    generateSequence(::readLine).forEach { s += it.trim().toLong() }\n    println(s)\n}\n"],
            self::ITEM_RECURSAO => [$arquivo, "fun f(n: Int): Long = if (n == 0) 0L else n + f(n - 1)\nfun main() {\n    println(f(10000))\n}\n"],
            self::ITEM_PARTIDA => [$arquivo, "fun main() {\n    println(8)\n}\n"],
            self::ITEM_STDERR => [$arquivo, "fun main() {\n    val (a, b) = readLine()!!.trim().split(\" \").map { it.toInt() }\n    System.err.println(\"depuracao: li \$a e \$b\")\n    println(a + b)\n}\n"],
        ];
    }

    private static function programasCSharp(): array
    {
        $arquivo = 'solution.cs';

        return [
            self::ITEM_RE => [$arquivo, "using System;\nclass Program {\n  static void Main() {\n    int[] v = new int[1];\n    Console.WriteLine(v[5]);\n  }\n}\n"],
            self::ITEM_CE => [$arquivo, "using System;\nclass Program {\n  static void Main() {\n    isto nao e csharp @@@\n  }\n}\n"],
            self::ITEM_TLE => [$arquivo, "class Program {\n  static long x = 0;\n  static void Main() {\n    while (true) x++;\n  }\n}\n"],
            self::ITEM_MLE => [$arquivo, "using System;\nusing System.Collections.Generic;\nclass Program {\n  static void Main() {\n    var todos = new List<byte[]>();\n    for (int i = 0; i < 4096; i++) {\n      var b = new byte[8 * 1024 * 1024];\n      for (int j = 0; j < b.Length; j += 4096) b[j] = (byte)(i % 251);\n      todos.Add(b);\n    }\n    Console.WriteLine(8);\n  }\n}\n"],
            self::ITEM_SAIDA => [$arquivo, "using System;\nclass Program {\n  static void Main() {\n    Console.WriteLine(8);\n    Console.Out.Flush();\n    Environment.Exit(3);\n  }\n}\n"],
            // `ToString(\"F2\")` usa a CurrentCulture -- o caminho sensível a
            // locale, que é o que se quer medir.
            self::ITEM_PONTO => [$arquivo, "using System;\nclass Program {\n  static void Main() {\n    Console.WriteLine(3.14159.ToString(\"F2\"));\n  }\n}\n"],
            self::ITEM_ENTRADA => [$arquivo, "using System;\nclass Program {\n  static void Main() {\n    long s = 0;\n    string l;\n    while ((l = Console.ReadLine()) != null) s += long.Parse(l);\n    Console.WriteLine(s);\n  }\n}\n"],
            self::ITEM_RECURSAO => [$arquivo, "using System;\nclass Program {\n  static long F(int n) { return n == 0 ? 0 : n + F(n - 1); }\n  static void Main() { Console.WriteLine(F(10000)); }\n}\n"],
            self::ITEM_PARTIDA => [$arquivo, "using System;\nclass Program {\n  static void Main() { Console.WriteLine(8); }\n}\n"],
            self::ITEM_STDERR => [$arquivo, "using System;\nclass Program {\n  static void Main() {\n    var p = Console.ReadLine().Split(' ');\n    int a = int.Parse(p[0]), b = int.Parse(p[1]);\n    Console.Error.WriteLine(\"depuracao: li \" + a + \" e \" + b);\n    Console.WriteLine(a + b);\n  }\n}\n"],
        ];
    }

    private static function programasRust(): array
    {
        $arquivo = 'solution.rs';

        return [
            self::ITEM_RE => [$arquivo, "fn main() {\n    let v = vec![1i64];\n    println!(\"{}\", v[5]);\n}\n"],
            self::ITEM_CE => [$arquivo, "fn main() {\n    isto nao e rust @@@\n}\n"],
            self::ITEM_TLE => [$arquivo, "fn main() {\n    let mut x: u64 = 0;\n    loop {\n        x = x.wrapping_add(1);\n        if x == u64::MAX { println!(\"{}\", x); }\n    }\n}\n"],
            self::ITEM_MLE => [$arquivo, "fn main() {\n    let mut todos: Vec<Vec<u8>> = Vec::new();\n    for i in 0..4096u32 {\n        todos.push(vec![(i % 251) as u8; 8 * 1024 * 1024]);\n    }\n    println!(\"8 {}\", todos.len());\n}\n"],
            self::ITEM_SAIDA => [$arquivo, "fn main() {\n    println!(\"8\");\n    std::process::exit(3);\n}\n"],
            self::ITEM_PONTO => [$arquivo, "fn main() {\n    println!(\"{:.2}\", 3.14159f64);\n}\n"],
            self::ITEM_ENTRADA => [$arquivo, "use std::io::Read;\nfn main() {\n    let mut s = String::new();\n    std::io::stdin().read_to_string(&mut s).unwrap();\n    let total: i64 = s.split_whitespace().map(|x| x.parse::<i64>().unwrap()).sum();\n    println!(\"{}\", total);\n}\n"],
            self::ITEM_RECURSAO => [$arquivo, "fn f(n: i64) -> i64 { if n == 0 { 0 } else { n + f(n - 1) } }\nfn main() {\n    println!(\"{}\", f(10000));\n}\n"],
            self::ITEM_PARTIDA => [$arquivo, "fn main() {\n    println!(\"8\");\n}\n"],
            self::ITEM_STDERR => [$arquivo, "use std::io::Read;\nfn main() {\n    let mut s = String::new();\n    std::io::stdin().read_to_string(&mut s).unwrap();\n    let v: Vec<i64> = s.split_whitespace().map(|x| x.parse().unwrap()).collect();\n    eprintln!(\"depuracao: li {} e {}\", v[0], v[1]);\n    println!(\"{}\", v[0] + v[1]);\n}\n"],
        ];
    }

    private static function programasGo(): array
    {
        $arquivo = 'solution.go';

        return [
            self::ITEM_RE => [$arquivo, "package main\n\nimport \"fmt\"\n\nfunc main() {\n\tv := []int{1}\n\ti := 5\n\tfmt.Println(v[i])\n}\n"],
            self::ITEM_CE => [$arquivo, "package main\n\nfunc main() {\n\tisto nao e go @@@\n}\n"],
            self::ITEM_TLE => [$arquivo, "package main\n\nfunc main() {\n\tx := 0\n\tfor {\n\t\tx++\n\t}\n}\n"],
            self::ITEM_MLE => [$arquivo, "package main\n\nimport \"fmt\"\n\nfunc main() {\n\tvar todos [][]byte\n\tfor i := 0; i < 4096; i++ {\n\t\tb := make([]byte, 8*1024*1024)\n\t\tfor j := 0; j < len(b); j += 4096 {\n\t\t\tb[j] = byte(i)\n\t\t}\n\t\ttodos = append(todos, b)\n\t}\n\tfmt.Println(8, len(todos))\n}\n"],
            self::ITEM_SAIDA => [$arquivo, "package main\n\nimport (\n\t\"fmt\"\n\t\"os\"\n)\n\nfunc main() {\n\tfmt.Println(8)\n\tos.Exit(3)\n}\n"],
            self::ITEM_PONTO => [$arquivo, "package main\n\nimport \"fmt\"\n\nfunc main() {\n\tfmt.Printf(\"%.2f\\n\", 3.14159)\n}\n"],
            self::ITEM_ENTRADA => [$arquivo, "package main\n\nimport (\n\t\"bufio\"\n\t\"fmt\"\n\t\"os\"\n\t\"strconv\"\n\t\"strings\"\n)\n\nfunc main() {\n\tsc := bufio.NewScanner(os.Stdin)\n\tvar s int64\n\tfor sc.Scan() {\n\t\tt := strings.TrimSpace(sc.Text())\n\t\tif t == \"\" {\n\t\t\tcontinue\n\t\t}\n\t\tn, _ := strconv.ParseInt(t, 10, 64)\n\t\ts += n\n\t}\n\tfmt.Println(s)\n}\n"],
            self::ITEM_RECURSAO => [$arquivo, "package main\n\nimport \"fmt\"\n\nfunc f(n int) int64 {\n\tif n == 0 {\n\t\treturn 0\n\t}\n\treturn int64(n) + f(n-1)\n}\n\nfunc main() {\n\tfmt.Println(f(10000))\n}\n"],
            self::ITEM_PARTIDA => [$arquivo, "package main\n\nimport \"fmt\"\n\nfunc main() {\n\tfmt.Println(8)\n}\n"],
            self::ITEM_STDERR => [$arquivo, "package main\n\nimport (\n\t\"fmt\"\n\t\"os\"\n)\n\nfunc main() {\n\tvar a, b int\n\tfmt.Scan(&a, &b)\n\tfmt.Fprintf(os.Stderr, \"depuracao: li %d e %d\\n\", a, b)\n\tfmt.Println(a + b)\n}\n"],
        ];
    }

    private static function programasPhp(): array
    {
        $arquivo = 'solution.php';

        return [
            self::ITEM_RE => [$arquivo, "<?php\nfscanf(STDIN, \"%d %d\", \$a, \$b);\nthrow new RuntimeException('estouro em tempo de execucao');\n"],
            self::ITEM_CE => [$arquivo, "<?php\nfunction f( {\n    return 1;\n}\necho f();\n"],
            self::ITEM_TLE => [$arquivo, "<?php\n\$x = 0;\nwhile (true) {\n    \$x++;\n}\n"],
            self::ITEM_MLE => [$arquivo, "<?php\n\$todos = [];\nfor (\$i = 0; \$i < 4096; \$i++) {\n    \$todos[] = str_repeat(chr(\$i % 251), 8 * 1024 * 1024);\n}\necho 8, PHP_EOL;\n"],
            self::ITEM_SAIDA => [$arquivo, "<?php\necho 8, PHP_EOL;\nexit(3);\n"],
            self::ITEM_PONTO => [$arquivo, "<?php\nprintf(\"%.2f\\n\", 3.14159);\n"],
            self::ITEM_ENTRADA => [$arquivo, "<?php\n\$s = 0;\nwhile ((\$l = fgets(STDIN)) !== false) {\n    \$s += (int) \$l;\n}\necho \$s, PHP_EOL;\n"],
            self::ITEM_RECURSAO => [$arquivo, "<?php\nfunction f(\$n) { return \$n === 0 ? 0 : \$n + f(\$n - 1); }\necho f(10000), PHP_EOL;\n"],
            self::ITEM_PARTIDA => [$arquivo, "<?php\necho 8, PHP_EOL;\n"],
            self::ITEM_STDERR => [$arquivo, "<?php\nfscanf(STDIN, \"%d %d\", \$a, \$b);\nfwrite(STDERR, \"depuracao: li \$a e \$b\\n\");\necho \$a + \$b, PHP_EOL;\n"],
        ];
    }

    private static function programasRuby(): array
    {
        $arquivo = 'solution.rb';

        return [
            self::ITEM_RE => [$arquivo, "a, b = gets.split.map(&:to_i)\nraise \"estouro em tempo de execucao: #{a + b}\"\n"],
            self::ITEM_CE => [$arquivo, "def f(\n  1\nend\nputs f\n"],
            self::ITEM_TLE => [$arquivo, "x = 0\nloop { x += 1 }\n"],
            self::ITEM_MLE => [$arquivo, "todos = []\n4096.times { |i| todos << (i % 251).chr * (8 * 1024 * 1024) }\nputs 8\n"],
            self::ITEM_SAIDA => [$arquivo, "puts 8\n\$stdout.flush\nexit 3\n"],
            self::ITEM_PONTO => [$arquivo, "puts format('%.2f', 3.14159)\n"],
            self::ITEM_ENTRADA => [$arquivo, "s = 0\nSTDIN.each_line { |l| s += l.to_i }\nputs s\n"],
            self::ITEM_RECURSAO => [$arquivo, "def f(n)\n  n == 0 ? 0 : n + f(n - 1)\nend\nputs f(10000)\n"],
            self::ITEM_PARTIDA => [$arquivo, "puts 8\n"],
            self::ITEM_STDERR => [$arquivo, "a, b = gets.split.map(&:to_i)\n\$stderr.puts \"depuracao: li #{a} e #{b}\"\nputs a + b\n"],
        ];
    }

    private static function programasPerl(): array
    {
        $arquivo = 'solution.pl';

        return [
            self::ITEM_RE => [$arquivo, "my \$a = 0;\nmy \$b = 0;\nprint 1 / \$a;\n"],
            self::ITEM_CE => [$arquivo, "sub f( {\n    return 1;\n}\nprint f(), \"\\n\";\n"],
            self::ITEM_TLE => [$arquivo, "my \$x = 0;\nwhile (1) { \$x++; }\n"],
            self::ITEM_MLE => [$arquivo, "my @todos;\nfor my \$i (1 .. 4096) {\n    push @todos, ('x' x (8 * 1024 * 1024));\n}\nprint \"8\\n\";\n"],
            self::ITEM_SAIDA => [$arquivo, "print \"8\\n\";\nexit 3;\n"],
            self::ITEM_PONTO => [$arquivo, "printf(\"%.2f\\n\", 3.14159);\n"],
            self::ITEM_ENTRADA => [$arquivo, "my \$s = 0;\nwhile (my \$l = <STDIN>) { \$s += \$l; }\nprint \"\$s\\n\";\n"],
            self::ITEM_RECURSAO => [$arquivo, "sub f { my \$n = shift; return 0 if \$n == 0; return \$n + f(\$n - 1); }\nprint f(10000), \"\\n\";\n"],
            self::ITEM_PARTIDA => [$arquivo, "print \"8\\n\";\n"],
            self::ITEM_STDERR => [$arquivo, "my @p = split ' ', <STDIN>;\nprint STDERR \"depuracao: li \$p[0] e \$p[1]\\n\";\nprint \$p[0] + \$p[1], \"\\n\";\n"],
        ];
    }

    private static function programasBash(): array
    {
        $arquivo = 'solution.sh';

        return [
            // 127 -- "command not found" é o erro de execução do shell.
            self::ITEM_RE => [$arquivo, "read a b\nnao_existe_este_comando_no_juiz \$a \$b\n"],
            self::ITEM_CE => [$arquivo, "read a b\nif then\n  echo \$((a + b))\nfi\n"],
            self::ITEM_TLE => [$arquivo, "while :; do :; done\n"],
            self::ITEM_MLE => [$arquivo, "x=0123456789\nwhile :; do\n  x=\"\$x\$x\"\ndone\necho 8\n"],
            self::ITEM_SAIDA => [$arquivo, "echo 8\nexit 3\n"],
            // O `printf` do bash passa pela libc, então é sensível a locale
            // -- é o caminho que interessa medir.
            self::ITEM_PONTO => [$arquivo, "printf '%.2f\\n' 3.14159\n"],
            self::ITEM_ENTRADA => [$arquivo, "s=0\nwhile read x; do s=\$((s + x)); done\necho \$s\n"],
            self::ITEM_RECURSAO => [$arquivo, "s=0\nf() {\n  local n=\$1\n  if [ \"\$n\" -eq 0 ]; then return; fi\n  s=\$((s + n))\n  f \$((n - 1))\n}\nf 10000\necho \$s\n"],
            self::ITEM_PARTIDA => [$arquivo, "echo 8\n"],
            self::ITEM_STDERR => [$arquivo, "read a b\necho \"depuracao: li \$a e \$b\" >&2\necho \$((a + b))\n"],
        ];
    }

    /**
     * sed -- só os três itens que a linguagem permite provar.
     *
     * O caso de CE é o achado desta auditoria: o `compile_command` do
     * catálogo é `sed -n "q" {source}`, e isso NÃO analisa o script. O
     * `{source}` ali é a ENTRADA do sed, não o programa: `q` é o script.
     * Qualquer arquivo passa. Medido -- `sed -n "q" bad.sed` devolve 0 com
     * `bad.sed` contendo `Z`, que o `sed -f` recusa.
     */
    private static function programasSed(): array
    {
        $arquivo = 'solution.sed';

        return [
            self::ITEM_CE => [$arquivo, "Z\n"],
            self::ITEM_TLE => [$arquivo, ":a\nba\n"],
            self::ITEM_PARTIDA => [$arquivo, "s/.*/8/\n"],
        ];
    }

    private static function programasPascal(): array
    {
        $arquivo = 'solution.pas';

        return [
            self::ITEM_RE => [$arquivo, "program Solution;\nvar p: ^longint;\nbegin\n  p := nil;\n  p^ := 8;\n  writeln(p^);\nend.\n"],
            self::ITEM_CE => [$arquivo, "program Solution;\nbegin\n  isto nao e pascal @@@\nend.\n"],
            self::ITEM_TLE => [$arquivo, "program Solution;\nvar x: int64;\nbegin\n  x := 0;\n  while true do x := x + 1;\n  writeln(x);\nend.\n"],
            self::ITEM_MLE => [$arquivo, "program Solution;\nvar i: longint; p: pbyte;\nbegin\n  for i := 1 to 64 do\n  begin\n    getmem(p, 32 * 1024 * 1024);\n    fillchar(p^, 32 * 1024 * 1024, i);\n  end;\n  writeln(8);\nend.\n"],
            self::ITEM_SAIDA => [$arquivo, "program Solution;\nbegin\n  writeln(8);\n  flush(output);\n  halt(3);\nend.\n"],
            self::ITEM_PONTO => [$arquivo, "program Solution;\nbegin\n  writeln(3.14159:0:2);\nend.\n"],
            self::ITEM_ENTRADA => [$arquivo, "program Solution;\nvar x: longint; s: int64;\nbegin\n  s := 0;\n  while not eof do\n  begin\n    readln(x);\n    s := s + x;\n  end;\n  writeln(s);\nend.\n"],
            self::ITEM_RECURSAO => [$arquivo, "program Solution;\nfunction f(n: longint): int64;\nbegin\n  if n = 0 then f := 0 else f := n + f(n - 1);\nend;\nbegin\n  writeln(f(10000));\nend.\n"],
            self::ITEM_PARTIDA => [$arquivo, "program Solution;\nbegin\n  writeln(8);\nend.\n"],
            self::ITEM_STDERR => [$arquivo, "program Solution;\nvar a, b: longint;\nbegin\n  readln(a, b);\n  writeln(stderr, 'depuracao: li ', a, ' e ', b);\n  writeln(a + b);\nend.\n"],
        ];
    }

    /** Scratch -- os construtores de `.sb3` que Tests\Support\ScratchProject já tem. */
    private static function programasScratch(): array
    {
        $arquivo = 'solution.sb3';

        return [
            self::ITEM_CE => [$arquivo, file_get_contents(ScratchProject::notAProject())],
            self::ITEM_TLE => [$arquivo, file_get_contents(ScratchProject::loopsForever())],
            self::ITEM_PARTIDA => [$arquivo, file_get_contents(ScratchProject::sumOfTwoTokens())],
        ];
    }

    /**
     * Portugol Studio -- a entrada vai um valor por linha, porque o console
     * lê com `Scanner.nextLine()`, UM por `leia` (medido na #269).
     */
    private static function programasPortugol(): array
    {
        $arquivo = 'solution.por';

        return [
            self::ITEM_RE => [$arquivo, "programa {\n  funcao inicio() {\n    inteiro a, b\n    leia(a)\n    leia(b)\n    escreva((a + b) / (b - b), \"\\n\")\n  }\n}\n"],
            self::ITEM_CE => [$arquivo, "programa {\n  funcao inicio() {\n    isto nao e portugol @@@\n  }\n}\n"],
            self::ITEM_TLE => [$arquivo, "programa {\n  funcao inicio() {\n    inteiro x = 0\n    enquanto (verdadeiro) {\n      x = x + 1\n    }\n  }\n}\n"],
            self::ITEM_PONTO => [$arquivo, "programa {\n  funcao inicio() {\n    real x = 3.14\n    escreva(x, \"\\n\")\n  }\n}\n"],
            self::ITEM_ENTRADA => [$arquivo, "programa {\n  funcao inicio() {\n    inteiro i, x\n    inteiro s = 0\n    para (i = 0; i < 100000; i++) {\n      leia(x)\n      s = s + x\n    }\n    escreva(s, \"\\n\")\n  }\n}\n"],
            self::ITEM_RECURSAO => [$arquivo, "programa {\n  funcao inteiro f(inteiro n) {\n    se (n == 0) {\n      retorne 0\n    }\n    retorne n + f(n - 1)\n  }\n  funcao inicio() {\n    escreva(f(10000), \"\\n\")\n  }\n}\n"],
            self::ITEM_PARTIDA => [$arquivo, "programa {\n  funcao inicio() {\n    escreva(8, \"\\n\")\n  }\n}\n"],
        ];
    }

    /**
     * Lua 5.4 -- `luac5.4 -p` analisa sem executar, e `lua5.4` interpreta a
     * MESMA fonte.
     *
     * Os dois itens que costumam quebrar num interpretador passam aqui, e
     * por motivo medido: o `LUAI_MAXSTACK` padrão é de 10^6 quadros, e o
     * subtipo INTEIRO do 5.4 é de 64 bits -- foi o 5.3 que o introduziu, e
     * antes dele todo número era `double` e 5000050000 sairia como
     * `5.00005e+09`, que é WA silencioso.
     */
    private static function programasLua(): array
    {
        $arquivo = 'solution.lua';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
local a, b = io.read("n", "n")
local t = nil
print(t.campo + a + b)

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
local a, b = io.read("n", "n")
isto nao e lua @@@
print(a + b)

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
local x = 0
while true do
  x = x + 1
end
print(x)

FONTE],
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
local blocos = {}
local soma = 0
for i = 1, 512 do
  blocos[i] = string.rep(string.char(65 + i % 26), 8 * 1024 * 1024)
  soma = soma + blocos[i]:byte(8 * 1024 * 1024)
end
print(soma ~= 0 and 8 or 9)

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
io.write("8\n")
io.stdout:flush()
os.exit(3)

FONTE],
            self::ITEM_PONTO => [$arquivo, 'print(string.format("%.2f", 3.14159))
'],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
local s = 0
for linha in io.lines() do
  s = s + tonumber(linha)
end
print(s)

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
local function f(n)
  if n == 0 then return 0 end
  return n + f(n - 1)
end
print(f(10000))

FONTE],
            self::ITEM_PARTIDA => [$arquivo, 'print("8")
'],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
local a, b = io.read("n", "n")
io.stderr:write("depuracao: li " .. a .. " e " .. b .. "\n")
print(a + b)

FONTE],
        ];
    }

    /**
     * AWK (gawk 5.3) -- `gawk --lint -o/dev/null -f {source}` é compilação
     * de verdade, e isso foi MEDIDO e não suposto: o `-o`
     * (`--pretty-print`) faz o gawk analisar, despejar o programa
     * reformatado no destino e SAIR sem executar. Prova: o programa de
     * partida é `BEGIN { print "8" }` e o `8` NÃO aparece na saída da
     * compilação; e o programa do item de erro de execução divide por zero
     * e mesmo assim compila com 0.
     *
     * MEDIÇÃO QUE MUDOU O PROGRAMA (item de erro de execução): divisão por
     * zero LITERAL é pega pelo `--lint` e viraria CE, não RE -- ou seja, o
     * teste passaria medindo a etapa errada. O zero vem de `$1 - $1`, que
     * só existe em tempo de execução.
     */
    private static function programasAwk(): array
    {
        $arquivo = 'solution.awk';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
{
  z = $1 - $1
  print ($1 + $2) / z
}

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
BEGIN {
  isto nao e awk @@@
  print 8
}

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
BEGIN {
  x = 0
  while (1) x = x + 1
  print x
}

FONTE],
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
BEGIN {
  b = "0123456789abcdef"
  while (length(b) < 8388608) b = b b
  soma = 0
  for (i = 0; i < 96; i++) {
    a[i] = b substr(b, 1, i + 1)
    soma += length(a[i])
  }
  if (soma > 0) print 8; else print 9
}

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
BEGIN {
  print "8"
  fflush()
  exit 3
}

FONTE],
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
BEGIN {
  printf "%.2f\n", 3.14159
}

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
{ s += $1 }
END { print s }

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
function f(n) {
  if (n == 0) return 0
  return n + f(n - 1)
}
BEGIN {
  print f(10000)
}

FONTE],
            self::ITEM_PARTIDA => [$arquivo, 'BEGIN { print "8" }
'],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
{
  print "depuracao: li " $1 " e " $2 > "/dev/stderr"
  print $1 + $2
}

FONTE],
        ];
    }

    /**
     * Tcl -- duas coisas medidas aqui, e nenhuma tem conserto do lado do
     * programa.
     *
     * 1. `tcl-check` (docker/judge/bin/tcl-check) só pergunta ao `info
     *    complete` se o script TERMINA no meio de um comando. MEDIDO: um
     *    script COMPLETO e sem sentido nenhum (`isto nao e tcl @@@`) passa
     *    pela compilação com 0 e só morre na execução (`invalid command
     *    name "isto"`). Por isso o programa de CE abaixo é um `if` sem a
     *    chave de fecho: é o ÚNICO defeito que essa etapa sabe ver.
     * 2. Consequência: o diagnóstico é a frase fixa do `tcl-check`, sem
     *    linha nenhuma -- `info complete` responde sim/não. A agulha do Tcl
     *    é `null`, e isso está declarado e justificado.
     *
     * E o item de recursão é DEFEITO medido: `interp recursionlimit` vale
     * 1000 nesta imagem. Ver defeitos().
     */
    private static function programasTcl(): array
    {
        $arquivo = 'solution.tcl';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
gets stdin linha
lassign [split $linha] a b
set z [expr {$a - $a}]
puts [expr {($a + $b) / $z}]

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
gets stdin linha
lassign [split $linha] a b
if {$a > 0} {
    puts [expr {$a + $b}]

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
set x 0
while {1} {
    incr x
}
puts $x

FONTE],
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
set soma 0
set blocos {}
for {set i 0} {$i < 512} {incr i} {
    set bloco [string repeat "abcdefgh$i" 1048576]
    lappend blocos $bloco
    incr soma [string length $bloco]
}
if {$soma > 0} { puts 8 } else { puts 9 }

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
puts 8
flush stdout
exit 3

FONTE],
            self::ITEM_PONTO => [$arquivo, 'puts [format %.2f 3.14159]
'],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
set s 0
while {[gets stdin linha] >= 0} {
    incr s $linha
}
puts $s

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
proc f {n} {
    if {$n == 0} { return 0 }
    return [expr {$n + [f [expr {$n - 1}]]}]
}
puts [f 10000]

FONTE],
            self::ITEM_PARTIDA => [$arquivo, 'puts 8
'],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
gets stdin linha
lassign [split $linha] a b
puts stderr "depuracao: li $a e $b"
puts [expr {$a + $b}]

FONTE],
        ];
    }

    /**
     * R 4.6 -- `Rscript --vanilla`.
     *
     * MEDIÇÃO QUE MUDOU O PROGRAMA (item de entrada grande): a soma tem de
     * sair de `as.numeric` e ser impressa com `sprintf("%.0f")`. Duas
     * armadilhas medidas, as duas SILENCIOSAS: `as.integer` transborda em
     * 32 bits e devolve `NA` antes de chegar a 5000050000, e `cat(s)`
     * imprime `5.00005e+09`, que é o padrão do R acima de 1e5 sob
     * `options(scipen=0)`. Qualquer uma das duas viraria WA sem uma linha
     * de explicação.
     *
     * E o item de recursão é DEFEITO medido, da família da #327. Ver
     * defeitos().
     */
    private static function programasR(): array
    {
        $arquivo = 'solution.r';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
linha <- readLines(file("stdin"), n = 1)
v <- as.integer(strsplit(linha, " ")[[1]])
stop("erro de execucao proposital")
cat(v[1] + v[2], "\n", sep = "")

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
a <- 3
b <- 5
isto nao e r @@@
cat(a + b, "\n", sep = "")

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
x <- 0
while (TRUE) {
  x <- x + 1
}
cat("8\n")

FONTE],
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
blocos <- list()
for (i in 1:16) {
  blocos[[i]] <- numeric(32 * 1024 * 1024)
  blocos[[i]][1] <- i
}
s <- 0
for (i in 1:16) s <- s + blocos[[i]][1]
cat(if (s > 0) "8" else "9", "\n", sep = "")

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
cat("8\n")
flush(stdout())
quit(status = 3)

FONTE],
            self::ITEM_PONTO => [$arquivo, 'cat(sprintf("%.2f\\n", 3.14159))
'],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
con <- file("stdin", "r")
s <- 0
repeat {
  l <- readLines(con, n = 1)
  if (length(l) == 0) break
  s <- s + as.numeric(l)
}
cat(sprintf("%.0f\n", s))

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
f <- function(n) if (n == 0) 0 else n + f(n - 1)
cat(f(10000), "\n", sep = "")

FONTE],
            self::ITEM_PARTIDA => [$arquivo, 'cat("8\\n")
'],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
linha <- readLines(file("stdin"), n = 1)
v <- as.integer(strsplit(linha, " ")[[1]])
cat("depuracao: li ", v[1], " e ", v[2], "\n", sep = "", file = stderr())
cat(v[1] + v[2], "\n", sep = "")

FONTE],
        ];
    }

    /**
     * Clojure 1.12 -- `clojure-check` e `clojure-run`, os dois invocadores
     * de `docker/judge/bin/`.
     *
     * O que `clojure-check` É define o programa de CE: ele é uma checagem
     * de LEITOR (`read` sobre todas as formas do arquivo) e não avalia
     * nada, por desenho -- `compile` executaria o código de nível superior
     * e consumiria a entrada do problema. MEDIDO: um identificador
     * inexistente PASSA na compilação. O único erro que essa etapa pega é
     * forma não fechada, e é isso que o programa abaixo usa.
     *
     * NÃO-ACHADO que quase virou achado, registrado porque quem repetir a
     * medição vai tropeçar nele: sob `docker run --memory=256m` o item de
     * memória morre com `OutOfMemoryError` e código 1, porque a JVM enxerga
     * o cgroup do CONTÊINER e escolhe um heap de 121 MB -- pareceria a
     * #324. NÃO é o que acontece no juiz: o `bwrap` do sandbox não monta
     * `/sys`, a JVM não acha o cgroup (medido: `maxMemory` de 1986 MB) e
     * quem mata é o `memory.max` do cgroup do run. Conforme. Quem refizer
     * isto: não use `--memory` no `docker run`.
     *
     * FLUTUAÇÃO SOB CARGA, registrada porque quem vir o vermelho precisa
     * saber: numa execução da suíte inteira feita com CINCO contêineres de
     * medição concorrentes, o item de recursão de `clj` veio `RE` com
     * `Execution error (StackOverflowError) at user/f (solution.clj:2)`.
     * Sozinho ele dá `AC` -- medido três vezes seguidas, três AC --, e o
     * controle positivo deu `AC` na MESMA execução em que o item falhou.
     * Não é defeito da linguagem, e por isso `clj` está CONFORME aqui e não
     * em defeitos(): fixar `RE` deixaria a suíte vermelha na máquina
     * ociosa, que é o contrário do que um teste deve fazer.
     *
     * Também não foi possível reproduzir isolando os ingredientes do
     * sandbox -- medido um a um, `bwrap --unshare-all`, `cgroup
     * memory.max=256M` e `ulimit -u 256` dão `50005000` e saída 0. É
     * dependência de CARGA, que é a forma da #329.
     */
    private static function programasClojure(): array
    {
        $arquivo = 'solution.clj';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
(let [partes (.split (read-line) " ")
      a (Long/parseLong (aget partes 0))
      b (Long/parseLong (aget partes 1))]
  (throw (Exception. "erro de execucao proposital"))
  (println (+ a b)))

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
(def a 3)
(def b 5)
(println (+ a b)

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
(loop [x 0]
  (recur (inc x)))
(println 8)

FONTE],
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
(def blocos (object-array 128))
(dotimes [i 128]
  (aset blocos i (byte-array (* 32 1024 1024) (byte (inc (mod i 100))))))
(let [s (reduce + (map #(aget % 0) (seq blocos)))]
  (println (if (pos? s) 8 9)))

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
(println 8)
(flush)
(System/exit 3)

FONTE],
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
(printf "%.2f%n" 3.14159)
(flush)

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
(loop [s 0]
  (if-let [l (read-line)]
    (recur (+ s (Long/parseLong l)))
    (println s)))

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
(defn f [n]
  (if (zero? n) 0 (+ n (f (dec n)))))
(println (f 10000))

FONTE],
            self::ITEM_PARTIDA => [$arquivo, '(println 8)
'],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
(let [partes (.split (read-line) " ")
      a (Long/parseLong (aget partes 0))
      b (Long/parseLong (aget partes 1))]
  (binding [*out* *err*]
    (println (str "depuracao: li " a " e " b))
    (flush))
  (println (+ a b)))

FONTE],
        ];
    }

    /**
     * Erlang/OTP 27 -- `erlc solution.erl` e
     * `erl ... -s solution main -s init stop`. O módulo TEM de se chamar
     * `solution` e exportar `main/0`: é o `{classname}` que o catálogo
     * substitui, e ele vem do nome do arquivo.
     *
     * HIPÓTESE DERRUBADA pela medição (item de erro de execução): a
     * suspeita era a da #301 -- que uma exceção em `main/0` saísse com
     * código 0, porque o `-s init stop` seguinte ainda rodaria, e erro de
     * execução virasse WA. NÃO acontece: o `-s` roda durante o BOOT, e uma
     * exceção ali termina o runtime antes do `init stop` ("Runtime
     * terminating during boot"), com código 1. Controle positivo na mesma
     * medição: o item de partida sai 0 com `8`.
     *
     * Ressalva medida no item de ponto decimal: a BEAM NÃO tem caminho
     * sensível a locale para formatar float -- `io:format ~f` é implementado
     * em Erlang puro. Medido com `LC_ALL=pt_BR.UTF-8`: continua `3.14`. O
     * item prova que a formatação está certa, não que ela resiste a locale.
     */
    private static function programasErlang(): array
    {
        $arquivo = 'solution.erl';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
-module(solution).
-export([main/0]).

main() ->
    {ok, [A, B]} = io:fread("", "~d ~d"),
    X = A div (B - B),
    io:format("~p~n", [X]).

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
-module(solution).
-export([main/0]).
isto nao e erlang @@@
main() ->
    io:format("8~n").

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
-module(solution).
-export([main/0]).

main() ->
    laco(0).

laco(X) ->
    laco(X + 1).

FONTE],
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
-module(solution).
-export([main/0]).

main() ->
    Blocos = [binary:copy(<<0>>, 32 * 1024 * 1024) || _ <- lists:seq(1, 128)],
    Soma = lists:sum([byte_size(B) || B <- Blocos]),
    case Soma > 0 of
        true -> io:format("8~n");
        false -> io:format("9~n")
    end.

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
-module(solution).
-export([main/0]).

main() ->
    io:format("8~n"),
    erlang:halt(3).

FONTE],
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
-module(solution).
-export([main/0]).

main() ->
    io:format("~.2f~n", [3.14159]).

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
-module(solution).
-export([main/0]).

main() ->
    soma(0).

soma(S) ->
    case io:get_line("") of
        eof -> io:format("~p~n", [S]);
        L -> soma(S + list_to_integer(string:trim(L)))
    end.

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
-module(solution).
-export([main/0]).

f(0) -> 0;
f(N) -> N + f(N - 1).

main() ->
    io:format("~p~n", [f(10000)]).

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
-module(solution).
-export([main/0]).

main() ->
    io:format("8~n").

FONTE],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
-module(solution).
-export([main/0]).

main() ->
    {ok, [A, B]} = io:fread("", "~d ~d"),
    io:format(standard_error, "depuracao: li ~p e ~p~n", [A, B]),
    io:format("~p~n", [A + B]).

FONTE],
        ];
    }

    /**
     * Elixir 1.19 -- a compilação é `Code.string_to_quoted!/1` (só analisa
     * sintaxe, sem avaliar) e a execução é `elixir --erl '+JMsingle true'`.
     *
     * MEDIÇÃO QUE MUDOU O PROGRAMA DE CE, e que é um achado por si só: a
     * primeira versão era `isto nao e elixir @@@` na linha 3, o mesmo lixo
     * das outras famílias. Isso é sintaxe VÁLIDA em Elixir -- lê como
     * chamada de função -- e a compilação devolveu 0. Medido: o programa
     * passou pela compilação e só morreu na execução, com
     * `** (CompileError)` e código 1. Ou seja, um programa que não compila
     * receberia RE em vez de CE. Não é defeito do invocador (resolver nomes
     * exigiria avaliar o módulo, que é o que a #305 proibiu), mas é o
     * LIMITE dele, e fica escrito. A versão final fecha parênteses a mais
     * na mesma linha 3, que é erro de leitor e a etapa pega.
     *
     * SEGUNDO ACHADO, no diagnóstico: o erro sai como `nofile:3:23`, e não
     * `solution.ex:3:23` -- o `compile_command` faz `File.read!` e entrega
     * uma STRING ao analisador, que perde o nome do arquivo. É por isso que
     * a agulha desta família é `nofile:3`: ela documenta um diagnóstico
     * ruim. `Code.string_to_quoted!(File.read!("{source}"), file:
     * "{source}")` resolveria, e é mudança de catálogo.
     */
    private static function programasElixir(): array
    {
        $arquivo = 'solution.ex';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
[a, b] = IO.read(:line) |> String.split() |> Enum.map(&String.to_integer/1)
raise "erro de execucao proposital"
IO.puts(a + b)

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
a = 3
b = 5
isto nao e elixir @@@ ))
IO.puts(a + b)

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
defmodule Solution do
  def laco(x), do: laco(x + 1)
end

Solution.laco(0)
IO.puts(8)

FONTE],
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
blocos = for _ <- 1..128, do: :binary.copy(<<0>>, 32 * 1024 * 1024)
soma = blocos |> Enum.map(&byte_size/1) |> Enum.sum()
IO.puts(if soma > 0, do: 8, else: 9)

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
IO.puts(8)
System.halt(3)

FONTE],
            self::ITEM_PONTO => [$arquivo, ':io.format("~.2f~n", [3.14159])
'],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
defmodule Solution do
  def soma(s) do
    case IO.read(:line) do
      :eof -> s
      l -> soma(s + String.to_integer(String.trim(l)))
    end
  end
end

IO.puts(Solution.soma(0))

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
defmodule Solution do
  def f(0), do: 0
  def f(n), do: n + f(n - 1)
end

IO.puts(Solution.f(10000))

FONTE],
            self::ITEM_PARTIDA => [$arquivo, 'IO.puts(8)
'],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
[a, b] = IO.read(:line) |> String.split() |> Enum.map(&String.to_integer/1)
IO.puts(:stderr, "depuracao: li #{a} e #{b}")
IO.puts(a + b)

FONTE],
        ];
    }

    private static function programasLisp(): array
    {
        $arquivo = 'solution.lisp';

        return [
            // Divisão por zero: as duas implementações sinalizam
            // DIVISION-BY-ZERO e morrem com 1. MEDIDO: o `clisp -c` AVISA
            // na compilação ("Run time error expected: /: division by
            // zero") e mesmo assim sai com 0 -- o erro continua sendo de
            // execução nas duas, que é o que este item precisa.
            self::ITEM_RE => [$arquivo, <<<'FONTE'
(let ((a (read)) (b (read)))
  (declare (ignorable a b))
  (format t "~a~%" (/ a 0)))

FONTE],
            // MEDIÇÃO QUE MUDOU O PROGRAMA: a primeira versão punha lixo
            // sintaticamente legível na linha 3 (`(isto nao e lisp @@@)`).
            // Isso reprova no SBCL -- `compile-file` devolve failure-p por
            // causa das WARNINGs de variável indefinida -- e NÃO reprova no
            // CLISP, que só avisa e sai com 0. Um parêntese fechando a
            // mais é erro de LEITURA, e erro de leitura é erro nas duas.
            self::ITEM_CE => [$arquivo, <<<'FONTE'
(defun soma (a b)
  (+ a b))
(format t "~a~%" (soma 3 5)))

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
(let ((x 0))
  (loop (setf x (mod (+ x 1) 1000)))
  (format t "~a~%" x))

FONTE],
            // MEDIÇÃO QUE MUDOU O PROGRAMA: blocos de 64 MiB não servem.
            // O CLISP 2.49 ABORTA (SIGABRT, sinal 6) com 72 MB de pico ao
            // pedir um `(unsigned-byte 8)` de 64 Mi elementos, e um pico
            // ABAIXO do limite do problema viraria RE, não MLE. Medido
            // também: em 16 Mi elementos o CLISP aloca e `length` devolve
            // 0. Em 4 MiB os dois se comportam -- SBCL esgota o heap em
            // ~1,0 GB de pico e o CLISP chega a ~4,2 GB.
            //
            // O `(aref (first lixo) 0)` no fim é o que torna a alocação
            // OBSERVÁVEL, pelo mesmo motivo do MLE de C: um compilador
            // pode descartar memória que ninguém lê.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
(let ((lixo nil))
  (dotimes (i 1024)
    (push (make-array (* 4 1024 1024) :element-type '(unsigned-byte 8)
                      :initial-element 1)
          lixo))
  (format t "~a~%" (+ 7 (aref (first lixo) 0))))

FONTE],
            // O único ponto em que as duas implementações divergem na
            // FONTE. `sb-ext:exit` não existe no CLISP e `ext:exit` não
            // existe no SBCL; a condicional de leitor resolve antes da
            // compilação. Medido: as duas saem com 3.
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
(format t "8~%")
(finish-output)
#+sbcl (sb-ext:exit :code 3)
#+clisp (ext:exit 3)

FONTE],
            // `~,2f` do FORMAT é o caminho da linguagem, e é ele que um
            // competidor escreveria.
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
(format t "~,2f~%" 3.14159)

FONTE],
            // `read` é a E/S ingênua do Common Lisp: lê um objeto Lisp por
            // vez. 10^5 inteiros nas duas implementações sem ajuste.
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
(let ((s 0))
  (loop for x = (read *standard-input* nil nil)
        while x
        do (setf s (+ s x)))
  (format t "~a~%" s))

FONTE],
            // `(+ n (f (- n 1)))` não é recursão de cauda: a soma acontece
            // DEPOIS da chamada. SBCL chega a 10000; CLISP 2.49 NÃO --
            // ver o defeito registrado para `lisp_clisp`.
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
(defun f (n)
  (if (= n 0) 0 (+ n (f (- n 1)))))
(format t "~a~%" (f 10000))

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
(format t "8~%")

FONTE],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
(let ((a (read)) (b (read)))
  (format *error-output* "depuracao: li ~a e ~a~%" a b)
  (format t "~a~%" (+ a b)))

FONTE],
        ];
    }

    /**
     * Scheme (Guile 3.0).
     *
     * A etapa de compilação é `guild compile {source}`. MEDIDO, porque a
     * #305 mostrou que "tem um binário" não é "tem uma etapa": o `guild
     * compile` SAI COM 1 em fonte inválida, aponta arquivo:linha:coluna, e
     * NÃO executa o corpo do módulo -- a compilação do programa que só
     * imprime `8` não imprimiu nada, e a do programa que lê 10^5 inteiros
     * não consumiu a entrada. É uma etapa de compilação de verdade.
     */
    private static function programasScheme(): array
    {
        $arquivo = 'solution.scm';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
(let ((a (read)) (b (read)))
  (display (car '()))
  (display (+ a b))
  (newline))

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
(define (soma a b)
  (+ a b))
(display (soma 3 5))))

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
(let laco ((x 0))
  (laco (modulo (+ x 1) 1000)))

FONTE],
            // MEDIÇÃO QUE MUDOU O PROGRAMA, DUAS VEZES.
            //
            // 1. `make-bytevector` NÃO está ligado no módulo padrão do
            //    Guile 3.0 (mora em `(rnrs bytevectors)`). O `guild
            //    compile` só AVISA ("possibly unbound variable") e sai com
            //    0 -- o programa compilava e morria na execução com
            //    "Unbound variable", o que daria RE e não MLE.
            // 2. Com `make-vector`, a primeira versão alocava 64 blocos de
            //    64 MB e chegava a 29 MB de pico: o compilador do Guile
            //    DESCARTA a alocação porque ninguém lê os vetores. Ler um
            //    elemento no fim (`vector-ref`) prende a alocação, e o
            //    pico medido passa para ~4,2 GB. É o mesmo defeito que o
            //    clang mostrou no MLE de C.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
(let laco ((i 0) (lixo '()))
  (if (>= i 64)
      (begin (display (+ 7 (vector-ref (car lixo) 0))) (newline))
      (laco (+ i 1) (cons (make-vector (* 8 1024 1024) 1) lixo))))

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
(display 8)
(newline)
(force-output)
(exit 3)

FONTE],
            // `~,2f` do `(ice-9 format)` -- o `format` do núcleo do Guile
            // (`simple-format`) não tem diretiva de casas decimais.
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
(use-modules (ice-9 format))
(format #t "~,2f~%" 3.14159)

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
(let laco ((s 0))
  (let ((x (read)))
    (if (eof-object? x)
        (begin (display s) (newline))
        (laco (+ s x)))))

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
(define (f n)
  (if (= n 0) 0 (+ n (f (- n 1)))))
(display (f 10000))
(newline)

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
(display 8)
(newline)

FONTE],
            // MEDIÇÃO QUE MUDOU O PROGRAMA: `(display x)` sem porta escreve
            // na SAÍDA PADRÃO. A primeira versão mandava a depuração para
            // stdout e o item passaria a medir o oposto do que promete.
            // Cada `display` leva `(current-error-port)` explicitamente.
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
(let* ((a (read)) (b (read)))
  (display "depuracao: li " (current-error-port))
  (display a (current-error-port))
  (display " e " (current-error-port))
  (display b (current-error-port))
  (newline (current-error-port))
  (display (+ a b))
  (newline))

FONTE],
        ];
    }

    /**
     * Racket 9.2.
     *
     * Toda fonte começa com `#lang racket`: sem isso o `racket-check`
     * (docker/judge/bin/, ver a justificativa da #305 no catálogo) não tem
     * como ler o módulo, e o erro seria de leitura e não do programa.
     *
     * O `racket-check` EXPANDE o módulo sem rodar o corpo, então ele pega
     * erro de sintaxe E identificador não ligado sem consumir a entrada.
     */
    private static function programasRacket(): array
    {
        $arquivo = 'solution.rkt';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
#lang racket
(define a (read))
(define b (read))
(displayln (car '()))
(displayln (+ a b))

FONTE],
            // Identificador não ligado na linha 3. O `racket-check` recusa
            // na EXPANSÃO, antes de qualquer execução -- é o que uma etapa
            // de compilação tem de fazer numa linguagem sem etapa de
            // compilação empacotada nesta imagem.
            self::ITEM_CE => [$arquivo, <<<'FONTE'
#lang racket
(define (soma a b)
  (+ a naoExisteEstaVariavel))
(displayln (soma 3 5))

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
#lang racket
(let laco ([x 0])
  (laco (modulo (+ x 1) 1000)))

FONTE],
            // O `bytes-ref` no fim tem o mesmo papel que no MLE de C e no
            // do Guile: sem ler a memória de volta, um compilador pode
            // descartar a alocação. Medido: ~4,4 GB de pico, e o programa
            // SOBREVIVE (imprime 8) -- o MLE tem de vir do juiz.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
#lang racket
(let laco ([i 0] [lixo '()])
  (if (>= i 64)
      (displayln (+ 7 (bytes-ref (car lixo) 0)))
      (laco (add1 i) (cons (make-bytes (* 64 1024 1024) 1) lixo))))

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
#lang racket
(displayln 8)
(flush-output)
(exit 3)

FONTE],
            // `real->decimal-string` é o caminho de formatação de casas
            // decimais do Racket: o `printf` do Racket não tem `%.2f`.
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
#lang racket
(displayln (real->decimal-string 3.14159 2))

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
#lang racket
(let laco ([s 0])
  (define x (read))
  (if (eof-object? x)
      (displayln s)
      (laco (+ s x))))

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
#lang racket
(define (f n)
  (if (= n 0) 0 (+ n (f (- n 1)))))
(displayln (f 10000))

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
#lang racket
(displayln 8)

FONTE],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
#lang racket
(define a (read))
(define b (read))
(fprintf (current-error-port) "depuracao: li ~a e ~a\n" a b)
(displayln (+ a b))

FONTE],
        ];
    }

    /**
     * Zig 0.16 -- a linguagem de E/S mais instável do catálogo.
     *
     * MEDIÇÃO QUE MUDOU TODOS OS PROGRAMAS: nesta versão `std.fs.File` e
     * `std.io.getStdOut()` não existem mais, e `std.posix` exporta `read`
     * mas não `write`. O único caminho de escrita que compila é
     * `std.Io.File.stdout().writeStreamingAll(io, bytes)` com um
     * `std.Io.Threaded` inicializado à mão -- é o mesmo padrão que
     * MultiLanguageJudgingTest já usa, e nenhuma outra API foi inventada
     * aqui.
     *
     * `writeStreamingAll` vai direto ao descritor, sem buffer de usuário:
     * por isso o item de código de saída não precisa de flush explícito.
     *
     * A compilação é a mais cara do grupo -- 4,1 s de parede para o
     * programa de entrada grande, contra 0,3 s do LDC.
     */
    private static function programasZig(): array
    {
        $arquivo = 'solution.zig';

        return [
            // MEDIÇÃO QUE MUDOU O PROGRAMA: a primeira versão usava
            // `var v = [_]i64{a}` e NÃO COMPILAVA -- `error: local variable
            // is never mutated`. Um erro de compilação aqui daria CE onde o
            // item exige RE, e o teste passaria pelo motivo errado.
            // `zig build-exe` sem `-O` é build Debug: o índice fora de
            // faixa vira panic (SIGABRT, 134), e não leitura de lixo.
            self::ITEM_RE => [$arquivo, <<<'FONTE'
const std = @import("std");
pub fn main() !void {
    var buf: [256]u8 = undefined;
    const n = try std.posix.read(0, &buf);
    var it = std.mem.tokenizeAny(u8, buf[0..n], " \t\r\n");
    const a = try std.fmt.parseInt(i64, it.next().?, 10);
    const b = try std.fmt.parseInt(i64, it.next().?, 10);
    const v = [_]i64{a};
    const idx: usize = @intCast(b + 10);
    var saida: [64]u8 = undefined;
    const s = try std.fmt.bufPrint(&saida, "{d}\n", .{v[idx]});
    var io: std.Io.Threaded = .init_single_threaded;
    defer io.deinit();
    try std.Io.File.stdout().writeStreamingAll(io.io(), s);
}

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
const std = @import("std");
pub fn main() !void {
    isto nao e zig @@@
}

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
const std = @import("std");
pub fn main() !void {
    var x: i64 = 0;
    const p: *volatile i64 = &x;
    while (true) p.* = p.* +% 1;
}

FONTE],
            // `page_allocator` é mmap direto. O `@memset` torna os 2 GiB
            // observáveis e a soma final os lê de volta, pelo mesmo motivo
            // registrado em programasC(): sem leitura de volta o otimizador
            // pode descartar a alocação inteira. Medido: 2.098.124 KiB de
            // pico de RSS, oito vezes o limite de 256 MB.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
const std = @import("std");
var blocos: [64][]u8 = undefined;
pub fn main() !void {
    const bloco: usize = 32 * 1024 * 1024;
    const aloc = std.heap.page_allocator;
    var soma: u64 = 0;
    for (&blocos, 0..) |*b, i| {
        b.* = aloc.alloc(u8, bloco) catch std.process.exit(7);
        @memset(b.*, @as(u8, @intCast(i + 1)));
    }
    for (blocos) |b| soma += b[bloco - 1];
    var io: std.Io.Threaded = .init_single_threaded;
    defer io.deinit();
    try std.Io.File.stdout().writeStreamingAll(io.io(), if (soma != 0) "8\n" else "9\n");
}

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
const std = @import("std");
pub fn main() !void {
    var io: std.Io.Threaded = .init_single_threaded;
    defer io.deinit();
    try std.Io.File.stdout().writeStreamingAll(io.io(), "8\n");
    io.deinit();
    std.process.exit(3);
}

FONTE],
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
const std = @import("std");
pub fn main() !void {
    var saida: [64]u8 = undefined;
    const s = try std.fmt.bufPrint(&saida, "{d:.2}\n", .{@as(f64, 3.14159)});
    var io: std.Io.Threaded = .init_single_threaded;
    defer io.deinit();
    try std.Io.File.stdout().writeStreamingAll(io.io(), s);
}

FONTE],
            // MEDIÇÃO QUE MUDOU O PROGRAMA, e o achado mais caro deste
            // grupo: a versão com `takeDelimiterExclusive('\n')` ENTRA EM
            // LAÇO INFINITO. Aquela função faz `peek` e depois
            // `toss(result.len)` -- ela NÃO consome o delimitador. Depois da
            // primeira linha a posição fica em cima do `\n`, a chamada
            // seguinte devolve fatia vazia, `toss(0)` não anda, e o programa
            // gira para sempre. Medido com TRÊS linhas de entrada: rc=124.
            // `takeDelimiter` devolve `?[]u8`, consome o delimitador e
            // devolve `null` no fim -- é a função correta.
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
const std = @import("std");
pub fn main() !void {
    var io: std.Io.Threaded = .init_single_threaded;
    defer io.deinit();
    var buf: [4096]u8 = undefined;
    var leitor = std.Io.File.stdin().readerStreaming(io.io(), &buf);
    var soma: i64 = 0;
    while (try leitor.interface.takeDelimiter('\n')) |linha| {
        const t = std.mem.trim(u8, linha, " \t\r");
        if (t.len == 0) continue;
        soma += try std.fmt.parseInt(i64, t, 10);
    }
    var saida: [64]u8 = undefined;
    const s = try std.fmt.bufPrint(&saida, "{d}\n", .{soma});
    try std.Io.File.stdout().writeStreamingAll(io.io(), s);
}

FONTE],
            // O `pad` volátil tem o mesmo papel que em programasC(): impedir
            // que a recursão vire laço, para que o que se meça seja a PILHA.
            // `zig build-exe` sem `-O` já é Debug, mas o volátil mantém o
            // programa honesto se o catálogo ganhar `-OReleaseFast`.
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
const std = @import("std");
fn f(n: i64) i64 {
    var pad: [64]u8 = undefined;
    const p: *volatile u8 = &pad[0];
    p.* = @truncate(@as(u64, @bitCast(n)));
    if (n == 0) return 0;
    const antes: i64 = p.*;
    const r = n + f(n - 1);
    const depois: i64 = p.*;
    return r + depois - antes;
}
pub fn main() !void {
    var saida: [64]u8 = undefined;
    const s = try std.fmt.bufPrint(&saida, "{d}\n", .{f(10000)});
    var io: std.Io.Threaded = .init_single_threaded;
    defer io.deinit();
    try std.Io.File.stdout().writeStreamingAll(io.io(), s);
}

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
const std = @import("std");
pub fn main() !void {
    var io: std.Io.Threaded = .init_single_threaded;
    defer io.deinit();
    try std.Io.File.stdout().writeStreamingAll(io.io(), "8\n");
}

FONTE],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
const std = @import("std");
pub fn main() !void {
    var buf: [256]u8 = undefined;
    const n = try std.posix.read(0, &buf);
    var it = std.mem.tokenizeAny(u8, buf[0..n], " \t\r\n");
    const a = try std.fmt.parseInt(i64, it.next().?, 10);
    const b = try std.fmt.parseInt(i64, it.next().?, 10);
    var io: std.Io.Threaded = .init_single_threaded;
    defer io.deinit();
    var dep: [128]u8 = undefined;
    const d = try std.fmt.bufPrint(&dep, "depuracao: li {d} e {d}\n", .{ a, b });
    try std.Io.File.stderr().writeStreamingAll(io.io(), d);
    var saida: [64]u8 = undefined;
    const s = try std.fmt.bufPrint(&saida, "{d}\n", .{a + b});
    try std.Io.File.stdout().writeStreamingAll(io.io(), s);
}

FONTE],
        ];
    }

    /**
     * Nim 2.2.
     *
     * O comando do catálogo é `nim c -o:{output} {source}` -- SEM
     * `-d:release`. O compilador anuncia isso em voz alta na saída
     * (`mm: orc; threads: on; opt: none (DEBUG BUILD)`), e essa escolha tem
     * uma consequência que o item de recursão mede: ver o comentário lá.
     *
     * `nim c` escreve cache em `$HOME/.cache/nim`; o ambiente do juiz
     * precisa de um HOME gravável, e tem.
     */
    private static function programasNim(): array
    {
        $arquivo = 'solution.nim';

        return [
            // Índice de seq fora de faixa -- `IndexDefect`, exceção não
            // tratada, saída 1. Divisão inteira por zero NÃO serviria aqui
            // pelo mesmo motivo registrado em programasC(): o `sdiv` do
            // ARM64 não gera exceção, e o backend C de Nim não a checa.
            self::ITEM_RE => [$arquivo, <<<'FONTE'
import std/strutils
let p = stdin.readLine().splitWhitespace()
let a = parseInt(p[0])
let b = parseInt(p[1])
let v = @[a]
echo v[b + 10]

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
import std/strutils
let p = stdin.readLine().splitWhitespace()
isto nao e nim @@@
echo parseInt(p[0])

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
var x {.volatile.}: int64 = 0
while true:
  x = x + 1

FONTE],
            // `newSeq[byte]` já zera (é `alloc0`), mas a escrita explícita a
            // cada página deixa a alocação observável de forma que nenhum
            // nível de otimização possa descartar -- e a soma final lê de
            // volta. Medido: 2.145.172 KiB de pico de RSS.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
var blocos: array[64, seq[byte]]
let bloco = 32 * 1024 * 1024
for i in 0 .. 63:
  blocos[i] = newSeq[byte](bloco)
  var j = 0
  while j < bloco:
    blocos[i][j] = byte(i + 1)
    j += 4096
var soma = 0
for i in 0 .. 63:
  soma += int(blocos[i][bloco - 4096])
echo (if soma != 0: 8 else: 9)

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
echo 8
flushFile(stdout)
quit(3)

FONTE],
            // `formatFloat` de std/strutils desce para `sprintf("%.*f")` da
            // libc -- é o caminho sensível a locale da linguagem, e é por
            // ele que o item passa.
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
import std/strutils
echo formatFloat(3.14159, ffDecimal, 2)

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
import std/strutils
var s: int64 = 0
var linha = ""
while stdin.readLine(linha):
  if linha.len > 0:
    s += parseInt(linha)
echo s

FONTE],
            // ACHADO MEDIDO, e o programa NÃO foi mudado para escondê-lo:
            // em Nim este item NÃO pode passar com o comando do catálogo.
            // `nim c` sem `-d:release` é build de depuração, e o build de
            // depuração de Nim tem um TETO DE PROFUNDIDADE DE CHAMADA de
            // 2000 quadros, imposto pelo próprio compilador e independente
            // do tamanho da pilha:
            //
            //   Error: call depth limit reached in a debug build (2000
            //   function calls). You can change it with
            //   -d:nimCallDepthLimit=<int>
            //
            // f(10000) morre com saída 1 nesse erro. Não é estouro de
            // pilha: o MESMO programa compilado com `nim c -d:release`
            // imprime 50005000 e sai com 0 (medido). Ou seja, o que reprova
            // Nim neste item é o comando do catálogo, não a linguagem, e o
            // conserto é uma palavra em app/Models/Language.php.
            //
            // Mantemos f(10000) -- igual a todas as outras famílias --
            // porque baixar a profundidade só para Nim tornaria o item
            // incomparável e esconderia exatamente o que ele existe para
            // achar.
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
proc f(n: int): int64 =
  var pad {.volatile.}: array[64, byte]
  pad[0] = byte(n and 0xff)
  if n == 0:
    return 0
  let antes = int64(pad[0])
  let r = int64(n) + f(n - 1)
  let depois = int64(pad[0])
  return r + depois - antes
echo f(10000)

FONTE],
            self::ITEM_PARTIDA => [$arquivo, "echo 8\n"],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
import std/strutils
let p = stdin.readLine().splitWhitespace()
let a = parseInt(p[0])
let b = parseInt(p[1])
stderr.writeLine("depuracao: li " & $a & " e " & $b)
echo a + b

FONTE],
        ];
    }

    /**
     * Crystal 1.20.
     *
     * `crystal build` é a compilação mais lenta do grupo depois de Zig --
     * 1,7 s de parede para o programa de entrada grande nesta imagem. Quem
     * ajustar o teto de tempo de COMPILAÇÃO precisa contar com isso.
     *
     * `crystal build` sem `--release` deixa o LLVM em -O0, então nenhum dos
     * programas depende de otimização estar ou não ligada.
     */
    private static function programasCrystal(): array
    {
        $arquivo = 'solution.cr';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
a, b = gets.not_nil!.split.map(&.to_i)
v = [a]
puts v[b + 10]

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
a, b = gets.not_nil!.split.map(&.to_i)
x = a + b
isto nao e crystal @@@
puts x

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
x = 0_i64
while true
  x &+= 1
end
puts x

FONTE],
            // `Bytes.new(n)` zera a região, e a escrita por página mais a
            // leitura de volta no fim mantêm os 2 GiB observáveis. Medido:
            // 2.139.600 KiB de pico de RSS.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
bloco = 32 * 1024 * 1024
blocos = [] of Bytes
64.times do |i|
  b = Bytes.new(bloco)
  j = 0
  while j < bloco
    b[j] = (i + 1).to_u8
    j += 4096
  end
  blocos << b
end
soma = 0_i64
blocos.each { |b| soma += b[bloco - 4096] }
puts(soma != 0 ? 8 : 9)

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
puts 8
STDOUT.flush
exit 3

FONTE],
            // `printf` de Crystal formata `%f` via `LibC.snprintf` -- é o
            // caminho sensível a locale, ao contrário de `Float#to_s`, que
            // usa o algoritmo próprio de Crystal.
            self::ITEM_PONTO => [$arquivo, "printf(\"%.2f\\n\", 3.14159)\n"],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
s = 0_i64
while linha = gets
  next if linha.empty?
  s += linha.to_i64
end
puts s

FONTE],
            // `uninitialized UInt8[64]` é o `volatile char pad[64]` de
            // programasC(): ocupa quadro de pilha e é lido depois da
            // chamada recursiva, então a recursão não pode virar laço.
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
def f(n : Int32) : Int64
  pad = uninitialized UInt8[64]
  pad[0] = (n & 0xff).to_u8
  return 0_i64 if n == 0
  antes = pad[0].to_i64
  r = n.to_i64 + f(n - 1)
  depois = pad[0].to_i64
  r + depois - antes
end
puts f(10000)

FONTE],
            self::ITEM_PARTIDA => [$arquivo, "puts 8\n"],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
a, b = gets.not_nil!.split.map(&.to_i)
STDERR.puts "depuracao: li #{a} e #{b}"
puts a + b

FONTE],
        ];
    }

    /**
     * D, compilada com LDC 1.42 (`ldc2 -of={output} {source}`).
     *
     * Conferido no harness: `-of=solution` produz mesmo o binário
     * `./solution` que o comando de execução do catálogo espera.
     *
     * `ldc2` sem `-O` é -O0, então nenhum programa aqui depende de o
     * otimizador estar desligado -- mas o item de recursão usa
     * `core.volatile` mesmo assim, porque D não tem mais a palavra-chave
     * `volatile` e um catálogo futuro com `-O2` precisaria disso.
     */
    private static function programasD(): array
    {
        $arquivo = 'solution.d';

        return [
            // Escrita em ponteiro nulo -- SIGSEGV (139). Mesma escolha e
            // mesmo motivo de programasC(): divisão por zero em aarch64 não
            // gera exceção.
            self::ITEM_RE => [$arquivo, <<<'FONTE'
import std.stdio;
void main() {
    int a, b;
    readf(" %d %d", &a, &b);
    int* p = null;
    *p = a + b;
    writeln(a + b);
}

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
import std.stdio;
void main() {
    isto nao e d @@@
}

FONTE],
            // `shared` impede que o LDC prove que `x` é morto e apague o
            // laço, sem precisar de `core.volatile` no caminho quente.
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
import std.stdio;
shared long x = 0;
void main() {
    while (true) { x = x + 1; }
}

FONTE],
            // Deliberadamente `malloc` + `memset` da libc, e não o GC de D:
            // o coletor de D poderia devolver páginas no meio do caminho e
            // o pico de RSS ficaria abaixo do que o item precisa provar.
            // Medido: 2.099.000 KiB de pico de RSS.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
import std.stdio;
import core.stdc.stdlib : malloc, exit;
import core.stdc.string : memset;
__gshared char*[64] blocos;
void main() {
    enum size_t bloco = 32UL * 1024UL * 1024UL;
    long soma = 0;
    foreach (i; 0 .. 64) {
        blocos[i] = cast(char*) malloc(bloco);
        if (blocos[i] is null) exit(7);
        memset(blocos[i], cast(int)(i + 1), bloco);
    }
    foreach (i; 0 .. 64) soma += blocos[i][bloco - 1];
    writeln(soma != 0 ? 8 : 9);
}

FONTE],
            // `core.stdc.stdlib.exit` e não `return 3` de `main`: o runtime
            // de D encerra a `main` de usuário e traduz o retorno, e o que
            // o item precisa é do código de saída chegar inteiro ao juiz.
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
import std.stdio;
import core.stdc.stdlib : exit;
void main() {
    writeln(8);
    stdout.flush();
    exit(3);
}

FONTE],
            // Registro honesto: `writefln` usa `std.format`, que é
            // independente de locale POR PROJETO -- D não tem um caminho de
            // formatação de ponto flutuante sensível a locale na biblioteca
            // padrão (só `core.stdc.stdio.printf`, que é a libc, não D). O
            // que este item prova em D é o caminho IDIOMÁTICO, que é o que
            // uma equipe escreveria.
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
import std.stdio;
void main() {
    writefln("%.2f", 3.14159);
}

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
import std.stdio;
import std.conv : to;
import std.string : strip;
void main() {
    long s = 0;
    foreach (linha; stdin.byLine) {
        auto t = linha.strip;
        if (t.length == 0) continue;
        s += to!long(t);
    }
    writeln(s);
}

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
import std.stdio;
import core.volatile : volatileLoad, volatileStore;
long f(int n) {
    ubyte[64] pad;
    volatileStore(&pad[0], cast(ubyte)(n & 0xff));
    if (n == 0) return 0;
    long antes = volatileLoad(&pad[0]);
    long r = cast(long) n + f(n - 1);
    long depois = volatileLoad(&pad[0]);
    return r + depois - antes;
}
void main() {
    writeln(f(10000));
}

FONTE],
            self::ITEM_PARTIDA => [$arquivo, "import std.stdio;\nvoid main() {\n    writeln(8);\n}\n"],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
import std.stdio;
void main() {
    int a, b;
    readf(" %d %d", &a, &b);
    stderr.writefln("depuracao: li %d e %d", a, b);
    writeln(a + b);
}

FONTE],
        ];
    }

    /**
     * Haskell (GHC 9.10) -- `ghc -O2 -o {output} {source}`.
     *
     * O `ghc` deixa `solution.hi` e `solution.o` ao lado do executavel: o
     * diretorio do run PRECISA ser gravavel na etapa de compilacao, e nesta
     * imagem e (medido -- compile_rc=0 nos dez itens).
     *
     * MEDICAO QUE MUDOU O PROGRAMA (ITEM_CE): a primeira versao era
     * `isto nao e haskell @@@` sozinho na linha 3. O GHC nao reclamou da
     * linha 3 -- reclamou da linha 4, `parse error ... incorrect
     * indentation`, porque o `@@@` sem operando direito so fecha no fim do
     * bloco. Dar um operando (`()`) faz o erro voltar para onde o lixo
     * esta: medido `solution.hs:3:3 Variable not in scope: isto` e
     * `solution.hs:3:22 Variable not in scope: (@@@)`.
     */
    private static function programasHaskell(): array
    {
        $arquivo = 'solution.hs';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
main :: IO ()
main = do
  l <- getLine
  let [a, b] = map read (words l) :: [Integer]
  print (a `div` (b - b))

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
main :: IO ()
main = do
  isto nao e haskell @@@ ()
  return ()

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
import Data.IORef

main :: IO ()
main = do
  r <- newIORef (0 :: Int)
  let go = do
        modifyIORef' r (+ 1)
        v <- readIORef r
        if v < 0 then print v else go
  go

FONTE],
            // 64 blocos de 64 MiB = 4 GiB, alocados com `mallocBytes` e
            // TOCADOS com `fillBytes`, depois lidos de volta. O mesmo
            // cuidado do programasC(): memoria que ninguem observa pode
            // ser descartada. Medido, pico de RSS 4,00 GiB.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
import Data.Word (Word8)
import Foreign.Marshal.Alloc (mallocBytes)
import Foreign.Marshal.Utils (fillBytes)
import Foreign.Storable (peekByteOff)

main :: IO ()
main = do
  let bloco = 64 * 1024 * 1024 :: Int
  ps <- mapM (\i -> do
                p <- mallocBytes bloco
                fillBytes p (fromIntegral (i + 1) :: Word8) bloco
                return p)
             [0 .. 63 :: Int]
  vs <- mapM (\p -> peekByteOff p (bloco - 1) :: IO Word8) ps
  putStrLn (if sum (map fromIntegral vs :: [Int]) /= 0 then "8" else "9")

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
import System.Exit (ExitCode (ExitFailure), exitWith)
import System.IO (hFlush, stdout)

main :: IO ()
main = do
  putStrLn "8"
  hFlush stdout
  exitWith (ExitFailure 3)

FONTE],
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
import Text.Printf (printf)

main :: IO ()
main = printf "%.2f\n" (3.14159 :: Double)

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
main :: IO ()
main = do
  s <- getContents
  let xs = map read (lines s) :: [Integer]
  print (sum xs)

FONTE],
            // Nao e recursao de cauda (a soma acontece DEPOIS da chamada),
            // e o GHC nao transforma isso em laco. Medido 50005000 com
            // -O2. A pilha do GHC vive no heap, entao 10^4 niveis nao
            // chegam perto de nada.
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
f :: Int -> Int
f n = if n == 0 then 0 else n + f (n - 1)

main :: IO ()
main = print (f 10000)

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
main :: IO ()
main = putStrLn "8"

FONTE],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
import System.IO (hPutStrLn, stderr)

main :: IO ()
main = do
  l <- getLine
  let [a, b] = map read (words l) :: [Integer]
  hPutStrLn stderr ("depuracao: li " ++ show a ++ " e " ++ show b)
  print (a + b)

FONTE],
        ];
    }

    /**
     * OCaml 4.14 -- `ocamlopt -o {output} {source}`.
     *
     * O `ocamlopt` deixa `.cmi`/`.cmx`/`.o` ao lado do executavel, como o
     * `ghc`; mesma exigencia de diretorio gravavel, medida igual.
     *
     * MEDICAO QUE MUDOU O PROGRAMA (ITEM_CE): `isto nao e ocaml @@@` sem
     * operando direito da `Syntax error` na linha 4 (o fim do arquivo), e
     * nao na linha do lixo. Com o `1` no fim o arquivo passa a ser
     * sintaticamente valido e o erro vira `Unbound value @@@` medido em
     * `File "solution.ml", line 3`.
     *
     * O `int` nativo do OCaml tem 63 bits em maquina de 64 bits, entao
     * ITEM_ENTRADA nao precisa de `Int64` -- 5000050000 cabe, e foi o que
     * saiu na medicao.
     */
    private static function programasOCaml(): array
    {
        $arquivo = 'solution.ml';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
let () = Scanf.scanf " %d %d" (fun a b -> Printf.printf "%d\n" (a / (b - b)))

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
let () =
  print_string "ola";
  isto nao e ocaml @@@ 1

FONTE],
            // `while true do ... done` -- o ocamlopt nao remove o laco.
            // Medido run_rc=124 (morto pelo relogio).
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
let () =
  let x = ref 0 in
  while true do
    incr x
  done;
  Printf.printf "%d\n" !x

FONTE],
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
let () =
  let bloco = 64 * 1024 * 1024 in
  let v = Array.init 64 (fun i -> Bytes.make bloco (Char.chr ((i mod 255) + 1))) in
  let s = ref 0 in
  Array.iter (fun b -> s := !s + Char.code (Bytes.get b (bloco - 1))) v;
  print_string (if !s <> 0 then "8\n" else "9\n")

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
let () =
  print_string "8\n";
  flush stdout;
  exit 3

FONTE],
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
let () = Printf.printf "%.2f\n" 3.14159

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
let () =
  let s = ref 0 in
  (try
     while true do
       Scanf.scanf " %d" (fun x -> s := !s + x)
     done
   with End_of_file | Scanf.Scan_failure _ -> ());
  Printf.printf "%d\n" !s

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
let rec f n = if n = 0 then 0 else n + f (n - 1)

let () = Printf.printf "%d\n" (f 10000)

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
let () = print_string "8\n"

FONTE],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
let () =
  Scanf.scanf " %d %d" (fun a b ->
      Printf.eprintf "depuracao: li %d e %d\n" a b;
      Printf.printf "%d\n" (a + b))

FONTE],
        ];
    }

    /**
     * Fortran livre (GFortran 15) -- `gfortran -O2 -o {output} {source}`.
     *
     * ITEM_PONTO usa `F4.2` e nao `F0.2` por decisao de risco, nao por
     * medicao: os DOIS foram medidos e os dois imprimem exatamente
     * `3.14\n` (5 bytes, conferidos com `od -c`, sem espaco a esquerda).
     * `F4.2` fixa a largura em 4 e por isso nao depende de como uma versao
     * futura do gfortran escolhe a largura minima de `F0.w`.
     *
     * ITEM_RE: ponteiro `nullify`ado e depois escrito. Medido SIGSEGV
     * (run_rc=139). Nao serve dividir por zero: `a/z` inteiro em aarch64
     * nao levanta excecao (mesmo achado do programasC()).
     *
     * ITEM_TLE e ITEM_RECURSAO usam `volatile` de proposito -- sem ele o
     * que o teste mediria era a otimizacao do gcc, e nao o laco nem a
     * pilha.
     */
    private static function programasFortran(): array
    {
        $arquivo = 'solution.f90';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
program solucao
  implicit none
  integer, pointer :: p
  integer :: a, b
  read(*,*) a, b
  nullify(p)
  p = a + b
  write(*,'(I0)') p
end program solucao

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
program solucao
  implicit none
  isto nao e fortran @@@
end program solucao

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
program solucao
  implicit none
  integer, volatile :: x
  x = 0
  do
    x = x + 1
  end do
end program solucao

FONTE],
            // 64 blocos de 64 MiB num tipo derivado com componente
            // `allocatable`: a atribuicao de vetor inteiro (`bs(i)%d = ...`)
            // toca cada pagina, que e o que o cgroup conta. Medido, pico
            // de RSS 4,00 GiB. O `stop 7` no `stat` segue programasC(),
            // que devolve 7 quando o malloc falha.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
program solucao
  implicit none
  type bloco
    integer(kind=1), allocatable :: d(:)
  end type bloco
  type(bloco) :: bs(64)
  integer :: i, st
  integer(kind=8) :: n, s
  n = 64_8 * 1024_8 * 1024_8
  s = 0
  do i = 1, 64
    allocate(bs(i)%d(n), stat=st)
    if (st /= 0) stop 7
    bs(i)%d = int(mod(i, 120) + 1, 1)
  end do
  do i = 1, 64
    s = s + int(bs(i)%d(n), 8)
  end do
  if (s /= 0) then
    write(*,'(I0)') 8
  else
    write(*,'(I0)') 9
  end if
end program solucao

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
program solucao
  use iso_fortran_env, only: output_unit
  implicit none
  write(*,'(I0)') 8
  flush(output_unit)
  call exit(3)
end program solucao

FONTE],
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
program solucao
  implicit none
  write(*,'(F4.2)') 3.14159
end program solucao

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
program solucao
  implicit none
  integer :: x, io
  integer(kind=8) :: s
  s = 0
  do
    read(*,*,iostat=io) x
    if (io /= 0) exit
    s = s + x
  end do
  write(*,'(I0)') s
end program solucao

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
program solucao
  implicit none
  write(*,'(I0)') f(10000)
contains
  recursive function f(n) result(r)
    integer, intent(in) :: n
    integer(kind=8) :: r
    integer, volatile :: pad(16)
    pad(1) = n
    if (n == 0) then
      r = 0
    else
      r = n + f(n - 1) + int(pad(1) - n, 8)
    end if
  end function f
end program solucao

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
program solucao
  implicit none
  write(*,'(I0)') 8
end program solucao

FONTE],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
program solucao
  use iso_fortran_env, only: error_unit
  implicit none
  integer :: a, b
  read(*,*) a, b
  write(error_unit,'(A,I0,A,I0)') 'depuracao: li ', a, ' e ', b
  write(*,'(I0)') a + b
end program solucao

FONTE],
        ];
    }

    /**
     * Fortran 77 (GFortran 15) -- `gfortran -std=legacy -O2 -o {output}
     * {source}`. FORMATO FIXO: as seis colunas em branco no inicio de cada
     * linha nao sao estilo, sao a linguagem.
     *
     * MEDIDO, e era a duvida aberta: `RECURSIVE FUNCTION ... RESULT(...)`
     * E aceito com `-std=legacy` (compile_rc=0) e a recursao FUNCIONA --
     * `f(10000)` devolveu 50005000. F77 de verdade nao tem recursao; quem
     * da a construcao e a extensao do gfortran, e a entrada do catalogo e
     * o gfortran.
     *
     * MEDICAO QUE MUDOU O PROGRAMA (ITEM_MLE): a primeira versao era F77
     * puro -- um vetor estatico `INTEGER*1 B(1073741824,4)` em BSS. No
     * caminho COM cgroup ela funciona (pico de RSS 4,0 GiB). No caminho
     * SEM cgroup, onde entra o `ulimit -v`, ela morre de SIGSEGV com pico
     * de RSS de 0,7 MB -- o juiz decide MLE pelo pico de RSS, entao aquilo
     * viraria RE e nao MLE. F77 nao tem alocacao dinamica nenhuma para
     * oferecer, entao a versao entregue usa `TYPE` com componente
     * `ALLOCATABLE`, que o `-std=legacy` aceita em formato fixo (medido,
     * compile_rc=0) e que da 4,0 GiB com cgroup e 257 MB > 256 MB sob
     * `ulimit -v` -- MLE nos dois caminhos.
     *
     * ITEM_RE: escrita fora dos limites com indice `VOLATILE` para o -O2
     * nao propagar a constante. Medido SIGSEGV (run_rc=139). A alternativa
     * padrao -- um `READ` a mais, que bate no fim do arquivo -- tambem foi
     * medida (run_rc=2, "Fortran runtime error: End of file"), mas amarra
     * o item ao formato da entrada do problema.
     */
    private static function programasFortran77(): array
    {
        $arquivo = 'solution.f';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
      PROGRAM SOL
      INTEGER A, B, V(4), I
      VOLATILE I
      READ(*,*) A, B
      I = 250000000
      V(I) = A + B
      WRITE(*,'(I0)') V(I)
      END

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
      PROGRAM SOL
      INTEGER A
      ISTO NAO E FORTRAN @@@
      END

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
      PROGRAM SOL
      INTEGER X
      VOLATILE X
      X = 0
   10 X = X + 1
      GOTO 10
      END

FONTE],
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
      PROGRAM SOL
      TYPE BLOCO
        INTEGER*1, ALLOCATABLE :: D(:)
      END TYPE BLOCO
      TYPE(BLOCO) BS(64)
      INTEGER I, ST
      INTEGER*8 S, N
      N = 67108864
      S = 0
      DO 10 I = 1, 64
        ALLOCATE(BS(I)%D(N), STAT=ST)
        IF (ST .NE. 0) STOP 7
        BS(I)%D = 7
   10 CONTINUE
      DO 20 I = 1, 64
        S = S + BS(I)%D(N)
   20 CONTINUE
      IF (S .NE. 0) THEN
        WRITE(*,'(I0)') 8
      ELSE
        WRITE(*,'(I0)') 9
      END IF
      END

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
      PROGRAM SOL
      WRITE(*,'(I0)') 8
      CALL FLUSH(6)
      CALL EXIT(3)
      END

FONTE],
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
      PROGRAM SOL
      WRITE(*,'(F4.2)') 3.14159
      END

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
      PROGRAM SOL
      INTEGER X, IO
      INTEGER*8 S
      S = 0
   10 READ(*,*,IOSTAT=IO) X
      IF (IO .NE. 0) GOTO 20
      S = S + X
      GOTO 10
   20 WRITE(*,'(I0)') S
      END

FONTE],
            // `RECURSIVE ... RESULT(R)` -- sintaxe de F90 que o
            // `-std=legacy` aceita, medida aqui e nao suposta. O `PAD`
            // VOLATILE forca um quadro de pilha de verdade, pelo mesmo
            // motivo do `volatile char pad[64]` do programasC().
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
      PROGRAM SOL
      INTEGER*8 F
      EXTERNAL F
      WRITE(*,'(I0)') F(10000)
      END

      RECURSIVE FUNCTION F(N) RESULT(R)
      INTEGER N
      INTEGER*8 R
      INTEGER PAD(16)
      VOLATILE PAD
      PAD(1) = N
      IF (N .EQ. 0) THEN
        R = 0
      ELSE
        R = N + F(N - 1) + (PAD(1) - N)
      END IF
      END

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
      PROGRAM SOL
      WRITE(*,'(I0)') 8
      END

FONTE],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
      PROGRAM SOL
      INTEGER A, B
      READ(*,*) A, B
      WRITE(0,'(A,I0,A,I0)') 'depuracao: li ', A, ' e ', B
      WRITE(*,'(I0)') A + B
      END

FONTE],
        ];
    }

    /**
     * Ada (GNAT 15) -- `gnatmake -o {output} {source}`.
     *
     * A unidade TEM de se chamar `Solution`, porque o `gnatmake` exige que
     * o nome da unidade case com o do arquivo (`solution.adb`).
     *
     * ATENCAO ao ler um diagnostico de Ada: o `gnatmake` escreve as linhas
     * normais de progresso (`gcc -c solution.adb`, `gnatbind`, `gnatlink`)
     * em STDERR mesmo quando da tudo certo. compile_stderr nao vazio NAO
     * quer dizer erro de compilacao aqui -- quem diz e o codigo de saida
     * (medido: 0 quando compila, 4 no ITEM_CE).
     *
     * O `gnatmake` do catalogo nao passa `-O`, entao roda sem otimizacao:
     * o `pragma Volatile` do ITEM_RECURSAO e cinto e suspensorio, e nao
     * necessidade medida.
     *
     * `Long_Long_Integer'Image` prefixa um espaco em numero nao negativo,
     * o que daria WA por um byte. Por isso ITEM_ENTRADA e ITEM_RECURSAO
     * usam `Ada.Long_Long_Integer_Text_IO.Put (..., Width => 0)`.
     */
    private static function programasAda(): array
    {
        $arquivo = 'solution.adb';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
with Ada.Text_IO; use Ada.Text_IO;
with Ada.Integer_Text_IO; use Ada.Integer_Text_IO;
procedure Solution is
   A, B : Integer;
begin
   Get (A);
   Get (B);
   Put (A / (B - B), Width => 0);
   New_Line;
end Solution;

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
with Ada.Text_IO; use Ada.Text_IO;
procedure Solution is
   isto nao e ada @@@
begin
   null;
end Solution;

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
procedure Solution is
   X : Long_Long_Integer := 0;
   pragma Volatile (X);
begin
   loop
      X := X + 1;
   end loop;
end Solution;

FONTE],
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
with Ada.Text_IO; use Ada.Text_IO;
procedure Solution is
   type Byte is mod 256;
   type Bloco is array (1 .. 64 * 1024 * 1024) of Byte;
   type Acesso is access Bloco;
   type Vetor is array (1 .. 64) of Acesso;
   V : Vetor;
   S : Long_Long_Integer := 0;
   J : Integer;
begin
   for I in V'Range loop
      V (I) := new Bloco;
      J := Bloco'First;
      while J <= Bloco'Last loop
         V (I) (J) := Byte (I mod 256);
         J := J + 4096;
      end loop;
   end loop;
   for I in V'Range loop
      S := S + Long_Long_Integer (V (I) (Bloco'First));
   end loop;
   if S /= 0 then
      Put_Line ("8");
   else
      Put_Line ("9");
   end if;
end Solution;

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
with Ada.Text_IO; use Ada.Text_IO;
with Ada.Command_Line;
procedure Solution is
begin
   Put_Line ("8");
   Flush (Standard_Output);
   Ada.Command_Line.Set_Exit_Status (3);
end Solution;

FONTE],
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
with Ada.Text_IO; use Ada.Text_IO;
with Ada.Float_Text_IO;
procedure Solution is
begin
   Ada.Float_Text_IO.Put (3.14159, Fore => 1, Aft => 2, Exp => 0);
   New_Line;
end Solution;

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
with Ada.Text_IO; use Ada.Text_IO;
with Ada.Integer_Text_IO; use Ada.Integer_Text_IO;
with Ada.Long_Long_Integer_Text_IO;
procedure Solution is
   X : Integer;
   S : Long_Long_Integer := 0;
begin
   while not End_Of_File loop
      Get (X);
      S := S + Long_Long_Integer (X);
   end loop;
   Ada.Long_Long_Integer_Text_IO.Put (S, Width => 0);
   New_Line;
end Solution;

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
with Ada.Text_IO; use Ada.Text_IO;
with Ada.Long_Long_Integer_Text_IO;
procedure Solution is
   function F (N : Integer) return Long_Long_Integer is
      Pad : array (1 .. 16) of Integer;
      pragma Volatile (Pad);
   begin
      Pad (1) := N;
      if N = 0 then
         return 0;
      end if;
      return Long_Long_Integer (N) + F (N - 1) + Long_Long_Integer (Pad (1) - N);
   end F;
begin
   Ada.Long_Long_Integer_Text_IO.Put (F (10000), Width => 0);
   New_Line;
end Solution;

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
with Ada.Text_IO; use Ada.Text_IO;
procedure Solution is
begin
   Put_Line ("8");
end Solution;

FONTE],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
with Ada.Text_IO; use Ada.Text_IO;
with Ada.Integer_Text_IO; use Ada.Integer_Text_IO;
procedure Solution is
   A, B : Integer;
begin
   Get (A);
   Get (B);
   Put (Standard_Error, "depuracao: li ");
   Put (Standard_Error, A, Width => 0);
   Put (Standard_Error, " e ");
   Put (Standard_Error, B, Width => 0);
   New_Line (Standard_Error);
   Put (A + B, Width => 0);
   New_Line;
end Solution;

FONTE],
        ];
    }

    /**
     * Scala 3.3 LTS -- o arquivo TEM de se chamar `Main.scala` e declarar
     * `object Main`: o `run_command` do catálogo é `scala {classname}`, e o
     * AutoJudgeService troca `{classname}` pelo nome do arquivo sem
     * extensão. É o mesmo mecanismo do Java, pelo mesmo motivo.
     *
     * MEDIDO na imagem do juiz (aarch64), com os comandos exatos do
     * catálogo: a partida custa 0,19-0,21 s de parede e 0,28-0,35 s de CPU.
     * É um décimo do que "JVM mais o dist de Scala inteiro no classpath"
     * sugeriria, e é por isso que `scala` cabe no teto de partida padrão.
     *
     * Os dois itens que costumam quebrar numa linguagem de JVM passam:
     * recursão de 10^4 níveis devolve 50005000 (a pilha padrão dá conta), e
     * `f"...%.2f"` -- que é o caminho SENSÍVEL A LOCALE de propósito,
     * porque passa pelo java.util.Formatter -- imprime `3.14`, cinco bytes,
     * conferido com `od -c`.
     */
    private static function programasScala(): array
    {
        $arquivo = 'Main.scala';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
object Main {
  def main(args: Array[String]): Unit = {
    val t = scala.io.StdIn.readLine().trim.split("\\s+").map(_.toInt)
    val v = Array(1, 2, 3)
    println(v(t(0) + t(1)))
  }
}

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
object Main {
  def main(args: Array[String]): Unit = {
    isto nao e scala @@@
  }
}

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
object Main {
  @volatile var x: Long = 0
  def main(args: Array[String]): Unit = {
    while (true) { x += 1 }
  }
}

FONTE],
            // A mesma forma do MLE de Java, e pelo mesmo motivo: os blocos
            // ficam num ArrayBuffer vivo até o fim, senão o coletor os
            // descarta e a medição vira mentira. MEDIDO: pico de RSS de
            // 2 133 128 KB (2,03 GB) em 0,80 s de CPU -- oito vezes o
            // limite de 256 MB do problema.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
import scala.collection.mutable.ArrayBuffer
object Main {
  def main(args: Array[String]): Unit = {
    val todos = ArrayBuffer[Array[Byte]]()
    for (i <- 0 until 4096) {
      val bloco = new Array[Byte](8 * 1024 * 1024)
      java.util.Arrays.fill(bloco, (i + 1).toByte)
      todos += bloco
    }
    println("8")
  }
}

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
object Main {
  def main(args: Array[String]): Unit = {
    println("8")
    Console.out.flush()
    System.exit(3)
  }
}

FONTE],
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
object Main {
  def main(args: Array[String]): Unit = {
    println(f"${3.14159}%.2f")
  }
}

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
object Main {
  def main(args: Array[String]): Unit = {
    var s: Long = 0
    var linha = scala.io.StdIn.readLine()
    while (linha != null) {
      s += linha.trim.toLong
      linha = scala.io.StdIn.readLine()
    }
    println(s)
  }
}

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
object Main {
  def f(n: Int): Long = if (n == 0) 0L else n + f(n - 1)
  def main(args: Array[String]): Unit = {
    println(f(10000))
  }
}

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
object Main {
  def main(args: Array[String]): Unit = { println("8") }
}

FONTE],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
object Main {
  def main(args: Array[String]): Unit = {
    val t = scala.io.StdIn.readLine().trim.split("\\s+").map(_.toInt)
    System.err.println(s"depuracao: li ${t(0)} e ${t(1)}")
    println(t(0) + t(1))
  }
}

FONTE],
        ];
    }

    /**
     * Groovy 4 -- a família com o ACHADO deste lote.
     *
     * RECURSÃO: `f(10000)` morre com `java.lang.StackOverflowError`. Não é
     * a pilha da JVM sendo pequena -- a MESMA recursão em Scala, na MESMA
     * JVM, devolve 50005000. É o custo de quadro do despacho dinâmico de
     * Groovy: cada chamada de método de script empilha uma pilha de
     * quadros do runtime. O corte foi medido por bisseção, com o programa
     * de self::ITEM_RECURSAO e só o 10000 trocado:
     *
     *     800 níveis   AC, 320400
     *     900 níveis   StackOverflowError
     *
     * ou seja, Groovy reprova com folga de UMA ORDEM DE GRANDEZA abaixo dos
     * 10^4 níveis que uma busca em profundidade banal precisa. Entra na
     * família da #327 como a nona linguagem.
     *
     * MEDIDO também: a partida custa 0,58-0,64 s de parede mas 1,42-1,81 s
     * de CPU, porque o `groovy {source}` do catálogo COMPILA o script antes
     * de executá-lo -- e é esse número que obriga a linha em
     * self::LIMITE_TLE_POR_LINGUAGEM (com o limite padrão de 1 s, o laço
     * infinito seria morto pela partida e o teste passaria pelo motivo
     * errado).
     */
    private static function programasGroovy(): array
    {
        $arquivo = 'solution.groovy';

        return [
            // MEDIÇÃO QUE MUDOU O PROGRAMA: a primeira versão usava uma
            // lista de Groovy (`def v = [1, 2, 3]`), e `v[8]` NÃO lança --
            // devolve `null`. O erro de execução acabava vindo da conta
            // seguinte, como `NullPointerException: Cannot execute null+1`.
            // Continua sendo rc=1, mas a mensagem que a equipe receberia
            // apontaria para o lugar errado. Com `int[]` o erro é o
            // pretendido: `ArrayIndexOutOfBoundsException: Index 8 out of
            // bounds for length 3`.
            self::ITEM_RE => [$arquivo, <<<'FONTE'
def linha = System.in.newReader().readLine()
def p = linha.trim().split(/\s+/)
int[] v = [1, 2, 3]
println(v[p[0].toInteger() + p[1].toInteger()])

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
def linha = "3 5"
def p = linha.split(/\s+/)
isto nao e groovy @@@
println(p[0])

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
long x = 0
while (true) { x++ }

FONTE],
            // MEDIDO: pico de RSS de 2 171 672 KB (2,07 GB) em 2,10 s de
            // CPU. Os blocos ficam numa lista viva de propósito, pelo mesmo
            // motivo do MLE de C e de Java.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
def todos = []
for (int i = 0; i < 4096; i++) {
    byte[] bloco = new byte[8 * 1024 * 1024]
    java.util.Arrays.fill(bloco, (byte) (i + 1))
    todos.add(bloco)
}
println("8")

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
println("8")
System.out.flush()
System.exit(3)

FONTE],
            // `printf` do Groovy é o `String.format` da JVM, que é o
            // caminho sensível a locale -- de propósito, como em Java.
            self::ITEM_PONTO => [$arquivo, "printf(\"%.2f%n\", 3.14159)\n"],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
long s = 0
def leitor = System.in.newReader()
def linha = leitor.readLine()
while (linha != null) {
    s += linha.trim().toLong()
    linha = leitor.readLine()
}
println(s)

FONTE],
            // Ver o docblock: este programa NÃO passa. Está aqui porque é
            // o que um competidor escreveria, e porque medir o que ele faz
            // é o achado.
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
long f(int n) { n == 0 ? 0L : n + f(n - 1) }
println(f(10000))

FONTE],
            self::ITEM_PARTIDA => [$arquivo, "println(\"8\")\n"],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
def linha = System.in.newReader().readLine()
def p = linha.trim().split(/\s+/)
System.err.println("depuracao: li ${p[0]} e ${p[1]}")
println(p[0].toInteger() + p[1].toInteger())

FONTE],
        ];
    }

    /**
     * Dart 3.13 -- a família que passou em tudo, na primeira medição, sem
     * nenhum programa precisar mudar.
     *
     * Vale registrar o que NÃO aconteceu, porque a expectativa era outra:
     * `stdin.readLineSync()` chamado 10^5 vezes -- a E/S mais ingênua que a
     * linguagem oferece -- lê os 100000 valores em 0,26 s de parede; a
     * recursão de 10^4 níveis devolve 50005000; e `toStringAsFixed(2)`
     * imprime `3.14` em cinco bytes. A partida do executável nativo não é
     * medível com `time -f %e` nesta máquina: 0,00 s nas três repetições.
     */
    private static function programasDart(): array
    {
        $arquivo = 'solution.dart';

        return [
            self::ITEM_RE => [$arquivo, <<<'FONTE'
import 'dart:io';
void main() {
  var p = stdin.readLineSync()!.trim().split(RegExp(r'\s+'));
  var v = <int>[1, 2, 3];
  print(v[int.parse(p[0]) + int.parse(p[1])]);
}

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
import 'dart:io';
void main() {
  isto nao e dart @@@
  print(stdin.readLineSync());
}

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
void main() {
  var x = 0;
  while (true) {
    x++;
  }
}

FONTE],
            // O laço interno que escreve um byte a cada 4096 é o que torna
            // a alocação OBSERVÁVEL -- a mesma precaução que o MLE de C
            // documenta, e que aqui não foi opcional: um `Uint8List` nunca
            // tocado é memória que o sistema pode não materializar.
            // MEDIDO: pico de RSS de 4 199 132 KB (4,00 GB) em 0,64 s de
            // CPU, e sob `ulimit -v 327680` (o caminho sem cgroup) o
            // programa morre com `Exhausted heap space` e rc=255.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
import 'dart:typed_data';
void main() {
  var todos = <Uint8List>[];
  var soma = 0;
  for (var i = 0; i < 64; i++) {
    var bloco = Uint8List(64 * 1024 * 1024);
    for (var j = 0; j < bloco.length; j += 4096) {
      bloco[j] = (i + 1) & 0xff;
    }
    soma += bloco[0];
    todos.add(bloco);
  }
  print(soma != 0 && todos.length == 64 ? '8' : '9');
}

FONTE],
            // `exit(3)` de dart:io descarrega a saída antes de terminar --
            // medido, o `8` chega ao comparador e o código de saída é 3.
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
import 'dart:io';
void main() {
  print('8');
  exit(3);
}

FONTE],
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
void main() {
  print(3.14159.toStringAsFixed(2));
}

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
import 'dart:io';
void main() {
  var s = 0;
  String? linha;
  while ((linha = stdin.readLineSync()) != null) {
    s += int.parse(linha!.trim());
  }
  print(s);
}

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
int f(int n) => n == 0 ? 0 : n + f(n - 1);
void main() {
  print(f(10000));
}

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
void main() {
  print('8');
}

FONTE],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
import 'dart:io';
void main() {
  var p = stdin.readLineSync()!.trim().split(RegExp(r'\s+'));
  var a = int.parse(p[0]);
  var b = int.parse(p[1]);
  stderr.writeln('depuracao: li $a e $b');
  print(a + b);
}

FONTE],
        ];
    }

    /**
     * SWI-Prolog 10 -- `main/0` não é escolha do fixture, é contrato do
     * catálogo: o `run_command` é `-g "main,halt"`.
     *
     * CONFERIDO, porque era a suspeita que motivava a medição: a etapa de
     * COMPILAÇÃO (`swipl --on-error=status -g "halt" -l {source}`) NÃO
     * executa `main` e NÃO consome a entrada. Medido com um programa cujo
     * `main` lê stdin e imprime uma marca, com `3 5` ligado na entrada dos
     * DOIS comandos: a compilação sai 0 sem imprimir nada e a execução
     * imprime `MAIN RODOU, LI 3 5`. Se fosse o contrário seria um defeito
     * da família da #269 (compilação que executa o programa).
     */
    private static function programasPrologSwi(): array
    {
        $arquivo = 'solution.pl';

        return [
            // Divisão inteira por zero serve AQUI, ao contrário do que vale
            // para as linguagens compiladas nativas nesta máquina: o SWI
            // verifica em software e lança `evaluation_error(zero_divisor)`
            // -- medido, rc=2, com o diagnóstico em stderr.
            self::ITEM_RE => [$arquivo, <<<'FONTE'
main :-
    read_line_to_string(user_input, S),
    split_string(S, " ", "", [A, B]),
    number_string(X, A),
    number_string(Y, B),
    Z is (X + Y) // (Y - Y),
    write(Z), nl.

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
main :-
    write(8), nl.
isto nao e prolog @@@

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
main :-
    repeat,
    fail.

FONTE],
            // MEDIÇÃO QUE MUDOU O PROGRAMA, duas vezes, e é o caso mais
            // instrutivo deste lote:
            //
            //   1. `length(L, 100000000)` -- a forma óbvia -- falha em
            //      0,00 s com `Stack limit (1.0Gb) exceeded` e um pico de
            //      RSS de 5 940 KB. O SWI CALCULA que a lista não cabe e
            //      lança ANTES de alocar: o programa que devia medir
            //      memória não alocou memória nenhuma. Era exatamente o
            //      "verde sobre mecanismo que não pode funcionar".
            //   2. `numlist(1, 40000000, L)` aloca de verdade (pico de
            //      1 123 344 KB) mas ainda bate no limite de pilha de 1 GB
            //      do próprio SWI e sai com rc=2.
            //
            // A versão final usa `assertz`, cujas cláusulas vivem FORA das
            // pilhas de Prolog (são malloc do programa) e por isso não
            // respondem ao `stack_limit`. MEDIDO: 5 000 000 fatos, pico de
            // 1 170 640 KB (1,12 GB), 1,41 s de CPU, rc=0 e `8` impresso.
            // Sob `ulimit -v 327680` (caminho sem cgroup) morre com
            // rc=134.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
:- dynamic(fato/2).

gera(I, N) :- I > N, !.
gera(I, N) :-
    assertz(fato(I, I)),
    I1 is I + 1,
    gera(I1, N).

main :-
    gera(1, 5000000),
    fato(5000000, X),
    ( X > 0 -> write(8) ; write(9) ),
    nl.

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
main :-
    write(8), nl,
    flush_output,
    halt(3).

FONTE],
            // `~2f` do format/2, e não `write/1` de um float: medido com
            // `od -c`, saem exatamente `3 . 1 4 \n`, cinco bytes, sem o
            // espaço à esquerda que a formatação de campo costuma deixar.
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
main :-
    format("~2f~n", [3.14159]).

FONTE],
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
main :-
    soma(0, S),
    write(S), nl.

soma(Ac, S) :-
    read_line_to_string(user_input, L),
    (   L == end_of_file
    ->  S = Ac
    ;   number_string(N, L),
        Ac1 is Ac + N,
        soma(Ac1, S)
    ).

FONTE],
            // Recursão NÃO de cauda de propósito (a soma acontece depois
            // da chamada), e o SWI dá conta: 50005000 em 0,02 s.
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
f(0, 0) :- !.
f(N, R) :-
    N1 is N - 1,
    f(N1, R1),
    R is N + R1.

main :-
    f(10000, R),
    write(R), nl.

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
main :-
    write(8), nl.

FONTE],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
main :-
    read_line_to_string(user_input, S),
    split_string(S, " ", "", [A, B]),
    number_string(X, A),
    number_string(Y, B),
    format(user_error, "depuracao: li ~w e ~w~n", [X, Y]),
    Z is X + Y,
    write(Z), nl.

FONTE],
        ];
    }

    /**
     * GNU Prolog 1.5 -- `:- initialization(main).` é o que faz o binário
     * compilado executar alguma coisa; sem a diretiva ele carrega e
     * termina.
     *
     * DOIS ACHADOS, e os dois são de limite da implementação, não de
     * programa mal escrito.
     *
     * 1. ERRO DE EXECUÇÃO NÃO EXISTE COMO CÓDIGO DE SAÍDA. Um erro não
     *    capturado dentro do goal de `initialization` faz o GNU Prolog
     *    imprimir
     *
     *        system_error(cannot_catch_throw(error(evaluation_error(
     *            zero_divisor),(is)/2)))
     *
     *    NA SAÍDA PADRÃO (conferido com `od -c` sobre o stdout separado do
     *    stderr), escrever `warning: solution.pro:6: user directive failed`
     *    em stderr, e SAIR COM 0. Do lado do juiz o efeito é duplo: o
     *    veredito não pode ser RE (o processo terminou bem) e a saída
     *    comparada foi contaminada pelo texto do erro. É a família do
     *    #301 -- erro de execução virando resposta errada silenciosa.
     *
     * 2. MEMÓRIA NÃO CHEGA A 256 MB. Ver o `NAO_SE_APLICA` de
     *    self::capacidades(): as pilhas do GNU Prolog são fixas, e toda
     *    alocação passa por elas.
     */
    private static function programasPrologGnu(): array
    {
        $arquivo = 'solution.pro';

        return [
            // O programa é o mesmo que em SWI (divisão inteira por zero,
            // verificada em software pelas duas implementações). O que
            // muda é o desfecho, e é ele que o teste fixa: rc=0. Ver o
            // achado 1 no docblock.
            self::ITEM_RE => [$arquivo, <<<'FONTE'
main :-
    read_integer(A),
    read_integer(B),
    C is (A + B) // (B - B),
    write(C), nl.
:- initialization(main).

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
main :-
    write(8), nl.
isto nao e prolog @@@
:- initialization(main).

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
main :-
    repeat,
    fail.
:- initialization(main).

FONTE],
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
main :-
    write(8), nl,
    halt(3).
:- initialization(main).

FONTE],
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
main :-
    format("~2f~n", [3.14159]).
:- initialization(main).

FONTE],
            // `read_integer/1` 100000 vezes, com contagem fixa porque o
            // `read_integer` do GNU Prolog não tem um `end_of_file` para
            // testar como o `read_line_to_string` do SWI tem. MEDIDO:
            // 5000050000 em 0,01 s -- o inteiro etiquetado de 64 bits do
            // gprolog dá conta do valor que não cabe em 32 bits.
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
soma(0, Ac, Ac) :- !.
soma(N, Ac, S) :-
    read_integer(X),
    Ac1 is Ac + X,
    N1 is N - 1,
    soma(N1, Ac1, S).

main :-
    soma(100000, 0, S),
    write(S), nl.
:- initialization(main).

FONTE],
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
f(0, 0) :- !.
f(N, R) :-
    N1 is N - 1,
    f(N1, R1),
    R is N + R1.

main :-
    f(10000, R),
    write(R), nl.
:- initialization(main).

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
main :-
    write(8), nl.
:- initialization(main).

FONTE],
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
main :-
    read_integer(A),
    read_integer(B),
    format(user_error, "depuracao: li ~w e ~w~n", [A, B]),
    C is A + B,
    write(C), nl.
:- initialization(main).

FONTE],
        ];
    }

    /**
     * COBOL (GnuCOBOL 3.2), formato fixo -- as colunas importam: sequência
     * em 1-6, indicador em 7, Área A em 8-11, Área B a partir de 12.
     *
     * A armadilha que MultiLanguageJudgingTest já paga vale aqui também:
     * `DISPLAY` de um `PIC S9(9)` imprime `+000000008`, e não `8`. Todo
     * item que precisa de número na saída passa por um campo editado e
     * `FUNCTION TRIM`.
     */
    private static function programasCobol(): array
    {
        $arquivo = 'solution.cob';

        return [
            // MEDIÇÃO QUE MUDOU O PROGRAMA: divisão por zero NÃO serve
            // aqui, e por um motivo diferente do de C. Não é o `sdiv` do
            // ARM: é o GnuCOBOL, que sem `ON SIZE ERROR` deixa o resultado
            // de `COMPUTE R = (A + B) / (B - B)` INALTERADO e segue. Medido:
            // compila, roda, imprime `0` e sai com 0 -- uma submissão assim
            // receberia WA e nunca RE.
            //
            // Também foi medido `CALL "NAOEXISTE"`, que dá
            // `libcob: error: module 'NAOEXISTE' not found` e rc=1. A
            // versão final usa a desreferência de ponteiro nulo porque o
            // sinal é inequívoco: SIGSEGV, rc=139.
            self::ITEM_RE => [$arquivo, <<<'FONTE'
       IDENTIFICATION DIVISION.
       PROGRAM-ID. SOMA.
       DATA DIVISION.
       WORKING-STORAGE SECTION.
       01 LINHA PIC X(80).
       01 A     PIC S9(9).
       01 B     PIC S9(9).
       01 P     USAGE POINTER.
       LINKAGE SECTION.
       01 ALVO  PIC S9(9).
       PROCEDURE DIVISION.
           ACCEPT LINHA FROM CONSOLE
           UNSTRING LINHA DELIMITED BY ALL SPACES INTO A B
           SET P TO NULL
           SET ADDRESS OF ALVO TO P
           COMPUTE ALVO = A + B
           DISPLAY ALVO
           STOP RUN.

FONTE],
            self::ITEM_CE => [$arquivo, <<<'FONTE'
       IDENTIFICATION DIVISION.
       PROGRAM-ID. SOMA.
       ISTO NAO E COBOL @@@
       DATA DIVISION.
       WORKING-STORAGE SECTION.
       01 R PIC S9(9).
       PROCEDURE DIVISION.
           MOVE 8 TO R
           DISPLAY R
           STOP RUN.

FONTE],
            self::ITEM_TLE => [$arquivo, <<<'FONTE'
       IDENTIFICATION DIVISION.
       PROGRAM-ID. SOMA.
       DATA DIVISION.
       WORKING-STORAGE SECTION.
       01 X PIC S9(18).
       PROCEDURE DIVISION.
           MOVE 0 TO X
           PERFORM UNTIL 1 = 2
               COMPUTE X = X + 1
           END-PERFORM
           STOP RUN.

FONTE],
            // MEDIÇÃO QUE MUDOU O PROGRAMA, duas vezes, e as duas falhas
            // foram de COMPILAÇÃO -- em COBOL a memória é declarada, não
            // pedida, então errar o tamanho não dá MLE, dá CE:
            //
            //   1. `01 GRANDE.` com `05 BLOCO OCCURS 64 TIMES PIC
            //      X(67108864)` (4 GB num item só):
            //      `error: 'GRANDE' cannot be larger than 2147483646
            //      bytes`.
            //   2. Quatro itens 01 de 1 GB cada, para ficar sob o teto de
            //      cada um: o `cobc` gera o C, e o LINK falha --
            //      `relocation truncated to fit:
            //      R_AARCH64_ADR_PREL_PG_HI21 against '.bss'`. 4 GB de BSS
            //      estático não cabem no alcance do `adrp` do aarch64.
            //
            // A versão final aloca DINAMICAMENTE com `ALLOCATE ...
            // CHARACTERS`, que não passa pelo BSS. MEDIDO: pico de RSS de
            // 4 196 992 KB (4,00 GB) em 0,64 s de CPU, rc=0, `8` impresso.
            // O `MOVE ALL "A"` é o que torna a alocação observável, e a
            // segunda passada relê um byte de cada bloco.
            self::ITEM_MLE => [$arquivo, <<<'FONTE'
       IDENTIFICATION DIVISION.
       PROGRAM-ID. SOMA.
       DATA DIVISION.
       WORKING-STORAGE SECTION.
       01 I PIC S9(9).
       01 S PIC S9(9).
       01 PONTEIROS.
          05 P OCCURS 64 TIMES USAGE POINTER.
       LINKAGE SECTION.
       01 BUF PIC X(67108864).
       PROCEDURE DIVISION.
           MOVE 0 TO S
           PERFORM VARYING I FROM 1 BY 1 UNTIL I > 64
               ALLOCATE 67108864 CHARACTERS RETURNING P(I)
               SET ADDRESS OF BUF TO P(I)
               MOVE ALL "A" TO BUF
           END-PERFORM
           PERFORM VARYING I FROM 1 BY 1 UNTIL I > 64
               SET ADDRESS OF BUF TO P(I)
               IF BUF(67108864:1) = "A"
                   ADD 1 TO S
               END-IF
           END-PERFORM
           IF S = 64
               DISPLAY "8"
           ELSE
               DISPLAY "9"
           END-IF
           STOP RUN.

FONTE],
            // `MOVE 3 TO RETURN-CODE` é o `exit(3)` de COBOL, e o
            // `DISPLAY` anterior já foi descarregado: medido, rc=3 com `8`
            // na saída.
            self::ITEM_SAIDA => [$arquivo, <<<'FONTE'
       IDENTIFICATION DIVISION.
       PROGRAM-ID. SOMA.
       PROCEDURE DIVISION.
           DISPLAY "8"
           MOVE 3 TO RETURN-CODE
           STOP RUN.

FONTE],
            // O campo editado `PIC 9.99` é o caminho idiomático, e é o
            // sensível a configuração: o ponto vira vírgula se o programa
            // declarar `DECIMAL-POINT IS COMMA`. Conferido com `od -c`:
            // `3 . 1 4 \n`, cinco bytes, sem espaço à esquerda -- que é
            // justamente o que um `PIC -(9)9.99` teria deixado.
            self::ITEM_PONTO => [$arquivo, <<<'FONTE'
       IDENTIFICATION DIVISION.
       PROGRAM-ID. SOMA.
       DATA DIVISION.
       WORKING-STORAGE SECTION.
       01 X     PIC 9V9(5) VALUE 3.14159.
       01 SAIDA PIC 9.99.
       PROCEDURE DIVISION.
           COMPUTE SAIDA ROUNDED = X
           DISPLAY SAIDA
           STOP RUN.

FONTE],
            // `PIC S9(18)` não é enfeite: 1+2+...+100000 = 5000050000 não
            // cabe num `PIC S9(9)`. Medido: 5000050000 em 0,03 s, com
            // `ACCEPT ... FROM CONSOLE` -- a mesma E/S ingênua que o a+b
            // do MultiLanguageJudgingTest usa.
            self::ITEM_ENTRADA => [$arquivo, <<<'FONTE'
       IDENTIFICATION DIVISION.
       PROGRAM-ID. SOMA.
       DATA DIVISION.
       WORKING-STORAGE SECTION.
       01 LINHA PIC X(20).
       01 I     PIC S9(9).
       01 N     PIC S9(18).
       01 S     PIC S9(18).
       01 SAIDA PIC -(18)9.
       PROCEDURE DIVISION.
           MOVE 0 TO S
           PERFORM VARYING I FROM 1 BY 1 UNTIL I > 100000
               ACCEPT LINHA FROM CONSOLE
               COMPUTE N = FUNCTION NUMVAL(LINHA)
               COMPUTE S = S + N
           END-PERFORM
           MOVE S TO SAIDA
           DISPLAY FUNCTION TRIM(SAIDA)
           STOP RUN.

FONTE],
            // MEDIÇÃO QUE MUDOU O PROGRAMA: recursão em COBOL exige
            // `PROGRAM-ID. F RECURSIVE` com `LOCAL-STORAGE SECTION` (sem
            // ela cada nível sobrescreveria o anterior e a resposta seria
            // outra). A primeira versão punha `F` ANINHADO dentro de
            // `SOMA`, e o `cobc` recusou:
            // `error: LOCAL-STORAGE not allowed in nested programs`.
            // Mover `END PROGRAM SOMA.` para antes de `F` faz dos dois
            // programas IRMÃOS no mesmo arquivo -- o `cobc -x` compila os
            // dois e o primeiro é o ponto de entrada. Medido: 50005000.
            self::ITEM_RECURSAO => [$arquivo, <<<'FONTE'
       IDENTIFICATION DIVISION.
       PROGRAM-ID. SOMA.
       DATA DIVISION.
       WORKING-STORAGE SECTION.
       01 N     PIC S9(9) VALUE 10000.
       01 R     PIC S9(18).
       01 SAIDA PIC -(18)9.
       PROCEDURE DIVISION.
           CALL "F" USING N R
           MOVE R TO SAIDA
           DISPLAY FUNCTION TRIM(SAIDA)
           STOP RUN.
       END PROGRAM SOMA.

       IDENTIFICATION DIVISION.
       PROGRAM-ID. F RECURSIVE.
       DATA DIVISION.
       LOCAL-STORAGE SECTION.
       01 N1 PIC S9(9).
       01 R1 PIC S9(18).
       LINKAGE SECTION.
       01 N  PIC S9(9).
       01 R  PIC S9(18).
       PROCEDURE DIVISION USING N R.
           IF N = 0
               MOVE 0 TO R
           ELSE
               COMPUTE N1 = N - 1
               CALL "F" USING N1 R1
               COMPUTE R = N + R1
           END-IF
           EXIT PROGRAM.
       END PROGRAM F.

FONTE],
            self::ITEM_PARTIDA => [$arquivo, <<<'FONTE'
       IDENTIFICATION DIVISION.
       PROGRAM-ID. SOMA.
       PROCEDURE DIVISION.
           DISPLAY "8"
           STOP RUN.

FONTE],
            // `UPON SYSERR` é o stderr de COBOL. Os dois valores passam
            // por campos editados com `FUNCTION TRIM` pelo mesmo motivo da
            // saída padrão: `DISPLAY A` sobre `PIC S9(9)` escreveria
            // `+000000003`.
            self::ITEM_STDERR => [$arquivo, <<<'FONTE'
       IDENTIFICATION DIVISION.
       PROGRAM-ID. SOMA.
       DATA DIVISION.
       WORKING-STORAGE SECTION.
       01 LINHA PIC X(80).
       01 A     PIC S9(9).
       01 B     PIC S9(9).
       01 R     PIC S9(9).
       01 EA    PIC -(9)9.
       01 EB    PIC -(9)9.
       01 SAIDA PIC -(9)9.
       PROCEDURE DIVISION.
           ACCEPT LINHA FROM CONSOLE
           UNSTRING LINHA DELIMITED BY ALL SPACES INTO A B
           MOVE A TO EA
           MOVE B TO EB
           DISPLAY "depuracao: li " FUNCTION TRIM(EA) " e "
               FUNCTION TRIM(EB) UPON SYSERR
           COMPUTE R = A + B
           MOVE R TO SAIDA
           DISPLAY FUNCTION TRIM(SAIDA)
           STOP RUN.

FONTE],
        ];
    }

    // ==================================================================
    // A PROVA DE CADA ITEM -- entrada, saída esperada e limites
    // ==================================================================

    /**
     * @return array{entrada: string, saida: string, tempo: int, memoria: int}
     */
    private static function provaDoItem(string $item, string $extension): array
    {
        // Portugol lê um valor por linha; todo o resto lê "3 5" numa linha.
        $aMaisB = $extension === 'portugol_studio' ? "3\n5\n" : "3 5\n";

        // Limite generoso onde o item NÃO é sobre tempo: um TLE acidental
        // aqui não seria achado, seria ruído.
        $folgado = self::LIMITE_DE_TEMPO_POR_LINGUAGEM[$extension] ?? 5;

        switch ($item) {
            case self::ITEM_TLE:
                return [
                    'entrada' => $aMaisB,
                    'saida' => "8\n",
                    'tempo' => self::LIMITE_TLE_POR_LINGUAGEM[$extension] ?? 1,
                    'memoria' => 256,
                ];

            case self::ITEM_PONTO:
                return ['entrada' => "3 5\n", 'saida' => "3.14\n", 'tempo' => $folgado, 'memoria' => 256];

            case self::ITEM_ENTRADA:
                // MEDIÇÃO QUE MUDOU A PROVA: o `inteiro` do Portugol Studio
                // é de 32 bits, e 1+2+...+100000 = 5000050000 não cabe nele
                // -- medido, o programa devolve 705082704, que é a soma
                // módulo 2^32. A primeira leitura disso foi "o Portugol não
                // dá conta de 10^5 leituras"; a medição derrubou a hipótese:
                // ele lê os 100000 valores em 1,38 s e a conta é que
                // transborda. Então a prova dele soma 10^5 UNS, que cabe em
                // 32 bits e mede a mesma coisa -- o custo de ler 10^5
                // valores com E/S ingênua.
                if ($extension === 'portugol_studio') {
                    return [
                        'entrada' => str_repeat("1\n", 100000),
                        'saida' => "100000\n",
                        'tempo' => max(5, $folgado),
                        'memoria' => 256,
                    ];
                }

                return [
                    'entrada' => self::cemMilInteiros(),
                    // 1 + 2 + ... + 100000.
                    'saida' => "5000050000\n",
                    // Cinco segundos de CPU: o objetivo é DESCOBRIR quem não
                    // dá conta de E/S ingênua, e não reprovar quem cabe num
                    // limite apertado. Quem estoura isto estoura qualquer
                    // limite de prova.
                    'tempo' => max(5, $folgado),
                    'memoria' => 256,
                ];

            case self::ITEM_RECURSAO:
                // 1 + 2 + ... + 10000.
                return ['entrada' => $aMaisB, 'saida' => "50005000\n", 'tempo' => $folgado, 'memoria' => 256];

            case self::ITEM_MLE:
                // Tempo folgado de propósito: a alocação tem de morrer por
                // MEMÓRIA, e um TLE aqui mediria a coisa errada.
                return ['entrada' => $aMaisB, 'saida' => "8\n", 'tempo' => max(10, $folgado), 'memoria' => 256];

            case self::ITEM_PARTIDA:
                return ['entrada' => $aMaisB, 'saida' => "8\n", 'tempo' => max(30, $folgado), 'memoria' => 256];

            default:
                return ['entrada' => $aMaisB, 'saida' => "8\n", 'tempo' => $folgado, 'memoria' => 256];
        }
    }

    private static function cemMilInteiros(): string
    {
        $linhas = [];
        for ($i = 1; $i <= 100000; $i++) {
            $linhas[] = $i;
        }

        return implode("\n", $linhas)."\n";
    }

    // ==================================================================
    // OS GUARDAS DA PRÓPRIA TABELA
    // ==================================================================

    /**
     * A rede de regressão do catálogo: uma linguagem nova em `is_active`
     * sem linha na tabela de capacidades faz ESTE teste falhar, em vez de a
     * suíte ficar verde porque o provedor de dados a pulou em silêncio. É a
     * mesma lição que MultiLanguageJudgingTest aprendeu.
     */
    public function test_toda_linguagem_ativa_tem_linha_na_tabela_de_capacidades()
    {
        $ativas = collect(Language::getDefaultLanguages())->where('is_active', true)->pluck('extension');
        $declaradas = array_keys(self::capacidades());

        $faltando = $ativas->diff($declaradas)->values()->all();

        $this->assertEmpty(
            $faltando,
            'linguagens is_active sem linha em LanguageConformanceTest::capacidades(): '.implode(', ', $faltando)
        );
    }

    /**
     * O guarda que impede "não se aplica" de virar o esconderijo das
     * medições difíceis: todo estado que não é CONFORME tem de vir com uma
     * justificativa escrita, e toda família declarada tem de ter programa
     * para todo item que declarou CONFORME.
     */
    public function test_toda_excecao_declarada_tem_justificativa_e_todo_conforme_tem_programa()
    {
        $programas = self::programas();
        $justificativas = self::justificativas();
        $problemas = [];

        $defeitos = self::defeitos();
        $limites = self::limites();

        foreach (self::capacidades() as $extension => $linha) {
            foreach ($linha['itens'] as $item => $estado) {
                if ($estado === self::CONFORME || $estado === self::DEFEITO_CONHECIDO || $estado === self::LIMITE_DA_LINGUAGEM) {
                    if (! isset($programas[$linha['familia']][$item])) {
                        $problemas[] = "{$extension}/{$item}: declarado '{$estado}' e sem programa na familia '{$linha['familia']}'";
                    }
                }

                if ($estado === self::CONFORME) {
                    continue;
                }

                if ($estado === self::LIMITE_DA_LINGUAGEM) {
                    $registro = $limites[$extension][$item] ?? null;

                    foreach (['veredito', 'porque', 'onde'] as $campo) {
                        if (($registro[$campo] ?? '') === '') {
                            $problemas[] = "{$extension}/{$item}: LIMITE_DA_LINGUAGEM sem '{$campo}' em limites()";
                        }
                    }

                    // Um limite cujo veredito medido é AC não é limite: é a
                    // linguagem dando conta, e a linha devia ser CONFORME.
                    if (($registro['veredito'] ?? null) === 'AC') {
                        $problemas[] = "{$extension}/{$item}: LIMITE_DA_LINGUAGEM cujo veredito medido e AC";
                    }

                    continue;
                }

                if ($estado === self::DEFEITO_CONHECIDO) {
                    $registro = $defeitos[$extension][$item] ?? null;

                    foreach (['veredito', 'correto', 'issue', 'nota'] as $campo) {
                        if (($registro[$campo] ?? '') === '') {
                            $problemas[] = "{$extension}/{$item}: DEFEITO_CONHECIDO sem '{$campo}' em defeitos()";
                        }
                    }

                    // Um "defeito" cujo veredito medido é o correto não é
                    // defeito -- é uma linha esquecida na tabela depois de
                    // alguém consertar o problema.
                    if (($registro['veredito'] ?? null) === ($registro['correto'] ?? false)) {
                        $problemas[] = "{$extension}/{$item}: DEFEITO_CONHECIDO cujo veredito medido ja e o correto";
                    }

                    continue;
                }

                if (($justificativas[$extension][$item] ?? '') === '') {
                    $problemas[] = "{$extension}/{$item}: estado '{$estado}' sem justificativa escrita";
                }
            }
        }

        // E o inverso, nos dois registros: um comportamento fixado que
        // ninguém declarou na tabela nunca seria medido.
        foreach ($defeitos as $extension => $itens) {
            foreach ($itens as $item => $registro) {
                if ((self::capacidades()[$extension]['itens'][$item] ?? null) !== self::DEFEITO_CONHECIDO) {
                    $problemas[] = "{$extension}/{$item}: esta em defeitos() e nao esta marcado DEFEITO_CONHECIDO em capacidades()";
                }
            }
        }

        foreach ($limites as $extension => $itens) {
            foreach ($itens as $item => $registro) {
                if ((self::capacidades()[$extension]['itens'][$item] ?? null) !== self::LIMITE_DA_LINGUAGEM) {
                    $problemas[] = "{$extension}/{$item}: esta em limites() e nao esta marcado LIMITE_DA_LINGUAGEM em capacidades()";
                }
            }
        }

        $this->assertSame([], $problemas, implode("\n", $problemas));
    }

    // ==================================================================
    // ITEM 1 -- ERRO DE EXECUÇÃO VIRA RE, E NÃO WA
    // ==================================================================

    /**
     * O item mais importante desta suíte, porque é o único em que já se
     * sabia de um defeito: #301, Portugol Studio, erro de execução vira WA
     * silencioso.
     *
     * O QUE PROVA: que um programa que morre em tempo de execução recebe
     * `RE`, e que o MESMO caminho de julgamento dá `AC` para o programa
     * correto da mesma linguagem -- o controle positivo, sem o qual um juiz
     * que reprovasse tudo passaria neste teste.
     *
     * O QUE NÃO PROVA: que o `RE` distingue as CAUSAS (segfault, exceção,
     * código de saída). Isso é o item de código de saída, separado.
     */
    #[DataProvider('casosDeErroDeExecucao')]
    public function test_erro_de_execucao_vira_erro_de_execucao_e_nao_resposta_errada(string $extension)
    {
        // Controle positivo primeiro: se ele não der AC, o resto do teste
        // não tem o que concluir.
        $controle = $this->julgarItem($extension, self::ITEM_CONTROLE);

        // O controle positivo também pode ser atingido pelo backstop de
        // parede; ali o pulo vale pelo mesmo motivo.
        $this->pularSeForBackstopDeParede($extension, self::ITEM_CONTROLE, $controle);

        $this->assertTrue(
            (bool) $controle->answer?->is_accepted,
            "CONTROLE POSITIVO FALHOU para {$extension}: o programa CORRETO recebeu "
            ."'{$controle->answer?->short_name}'. Sem isso, um 'RE' abaixo nao provaria nada.\n"
            ."stdout: {$controle->auto_judge_stdout}\nstderr: {$controle->auto_judge_stderr}"
        );

        $run = $this->julgarItem($extension, self::ITEM_RE);

        $this->exigirVeredito(
            $extension,
            self::ITEM_RE,
            'RE',
            $run,
            'erro de execucao nao virou RE. Um WA aqui e o defeito da #301 em outra linguagem: '
            .'a equipe le "resposta errada" sobre um programa que nem chegou ao fim.'
        );
    }

    // ==================================================================
    // ITEM 2 -- ERRO DE COMPILAÇÃO VIRA CE, COM A LINHA
    // ==================================================================

    /**
     * O QUE PROVA: que um programa sintaticamente inválido recebe `CE`, e
     * que o diagnóstico que chega à equipe não é vazio.
     *
     * O QUE NÃO PROVA: o formato da mensagem. Cada compilador escreve a
     * linha à sua maneira (`solution.c:3:5:`, `Linha: 3`, `(3,5)`), e exigir
     * um formato comum seria exigir que todas as ferramentas de terceiro
     * concordassem. O que se exige é que a linha 3 -- onde o lixo está em
     * todas as fontes desta suíte -- apareça em algum lugar do diagnóstico.
     */
    #[DataProvider('casosDeErroDeCompilacao')]
    public function test_erro_de_compilacao_vira_erro_de_compilacao_e_nao_resposta_errada(string $extension)
    {
        $run = $this->julgarItem($extension, self::ITEM_CE);

        $this->exigirVeredito($extension, self::ITEM_CE, 'CE', $run, 'programa invalido nao virou CE');

        // Onde o veredito é o do defeito, não há diagnóstico de compilação
        // para exigir -- a compilação nem chegou a recusar o programa.
        if (isset(self::defeitos()[$extension][self::ITEM_CE])) {
            return;
        }

        $diagnostico = trim((string) $run->auto_judge_stderr."\n".(string) $run->auto_judge_stdout);

        $this->assertNotSame(
            '',
            $diagnostico,
            "o CE de {$extension} saiu sem uma linha de diagnostico: 'Compilation Error' sozinho "
            .'e a coisa menos util que um juiz pode dizer a uma equipe'
        );

        $agulha = self::AGULHA_POR_EXTENSAO[$extension]
            ?? self::AGULHA_DA_LINHA_DO_ERRO[self::capacidades()[$extension]['familia']]
            ?? null;

        if ($agulha === null) {
            // Declarado e justificado: a ferramenta não tem linha de fonte
            // para apontar. O diagnóstico não vazio acima é tudo o que há
            // para exigir.
            return;
        }

        $this->assertStringContainsString(
            $agulha,
            $diagnostico,
            "o CE de {$extension} nao disse ONDE consertar: '{$agulha}' nao aparece no diagnostico.\n"
            ."diagnostico: {$diagnostico}"
        );
    }

    // ==================================================================
    // ITEM 3 -- TEMPO EXCEDIDO VIRA TLE
    // ==================================================================

    /**
     * O QUE PROVA: que um laço infinito é morto e recebe `TLE`, e não fica
     * pendurado na fila nem vira `RE` genérico.
     *
     * O QUE NÃO PROVA: precisão do limite. O `ulimit -t` conta CPU, não
     * parede; um programa que dorme não é morto por ele. Isso é outro
     * assunto (o backstop de `Process::timeout`), e não este item.
     */
    #[DataProvider('casosDeTempoExcedido')]
    public function test_laco_infinito_vira_tempo_excedido(string $extension)
    {
        $run = $this->julgarItem($extension, self::ITEM_TLE);

        $this->exigirVeredito($extension, self::ITEM_TLE, 'TLE', $run, 'laco infinito nao virou TLE');
    }

    // ==================================================================
    // ITEM 4 -- MEMÓRIA EXCEDIDA VIRA MLE
    // ==================================================================

    /**
     * O QUE PROVA: que uma alocação muito acima do limite do problema é
     * barrada e recebe `MLE`.
     *
     * O QUE NÃO PROVA -- e isto importa: com cgroup v2 delegado quem decide
     * é o `memory.max`; SEM ele (contêiner não privilegiado, host em cgroup
     * v1) a decisão vem do pico de RSS lido pelo `time -f %M`, e um runtime
     * que falha a alocação de forma limpa antes de tocar a memória sai por
     * `RE` em vez de `MLE`. Ver config/autojudge.php. Rode a suíte com
     * `docker run --privileged` para medir o caminho que a produção usa.
     */
    #[DataProvider('casosDeMemoriaExcedida')]
    public function test_alocacao_sem_limite_vira_memoria_excedida(string $extension)
    {
        $run = $this->julgarItem($extension, self::ITEM_MLE);

        $this->exigirVeredito(
            $extension,
            self::ITEM_MLE,
            'MLE',
            $run,
            'alocacao sem limite nao virou MLE. Sem cgroup delegado (docker run --privileged, e sem '
            .'--entrypoint, para o docker/judge/entrypoint.sh poder delegar) este caminho muda -- '
            .'confira se o ambiente tem um.'
        );
    }

    // ==================================================================
    // ITEM 5 -- CÓDIGO DE SAÍDA DIFERENTE DE ZERO VIRA RE
    // ==================================================================

    /**
     * O QUE PROVA: que a saída CORRETA impressa antes de um `exit(3)` não
     * compra um `AC`. É o caso que separa "comparar texto" de "julgar um
     * programa": o texto bate, e mesmo assim o programa falhou.
     *
     * O QUE NÃO PROVA: nada sobre o valor 3 em si -- qualquer código
     * diferente de zero serve.
     */
    #[DataProvider('casosDeCodigoDeSaida')]
    public function test_codigo_de_saida_diferente_de_zero_vira_erro_de_execucao_mesmo_com_a_saida_certa(string $extension)
    {
        $run = $this->julgarItem($extension, self::ITEM_SAIDA);

        $this->exigirVeredito(
            $extension,
            self::ITEM_SAIDA,
            'RE',
            $run,
            'saida correta seguida de exit(3) nao virou RE'
        );
    }

    // ==================================================================
    // ITEM 6 -- PONTO DECIMAL
    // ==================================================================

    /**
     * O QUE PROVA: que o caminho de formatação SENSÍVEL A LOCALE de cada
     * linguagem (`printf("%.2f")`, `String.format`, `ToString("F2")`)
     * produz `3.14` dentro do sandbox. Locale é causa clássica de WA em
     * massa: uma prova brasileira com `pt_BR.UTF-8` no ambiente faria metade
     * das linguagens imprimir `3,14` e reprovar soluções certas.
     *
     * O QUE NÃO PROVA: que o resultado seja estável se alguém definir `LANG`
     * na imagem. O `bwrap` roda com `--clearenv`, e é isso que segura o
     * resultado hoje -- não uma escolha explícita de locale. Um teste sobre
     * isso teria de injetar `LANG`, o que o sandbox não permite por
     * submissão.
     */
    #[DataProvider('casosDePontoDecimal')]
    public function test_ponto_decimal_e_ponto_e_nao_virgula(string $extension)
    {
        $run = $this->julgarItem($extension, self::ITEM_PONTO);

        $this->exigirVeredito(
            $extension,
            self::ITEM_PONTO,
            'AC',
            $run,
            'nao imprimiu 3.14. Se a saida abaixo tiver virgula, o locale do ambiente vazou para '
            .'dentro do sandbox.'
        );
    }

    // ==================================================================
    // ITEM 7 -- ENTRADA GRANDE
    // ==================================================================

    /**
     * O QUE PROVA: que 10^5 inteiros lidos com a E/S que um competidor
     * escreve sem pensar cabem em cinco segundos de CPU, e que a medição de
     * tempo chega ao banco.
     *
     * O QUE NÃO PROVA: que caberiam no limite de UM segundo que um problema
     * padrão aplica. A intenção aqui é medir e registrar o custo por
     * linguagem -- o número vai para storage/logs/conformidade-linguagens.jsonl
     * e é o que o organizador precisa para calibrar limite de problema.
     */
    #[DataProvider('casosDeEntradaGrande')]
    public function test_entrada_de_cem_mil_inteiros_com_es_ingenua(string $extension)
    {
        $run = $this->julgarItem($extension, self::ITEM_ENTRADA);

        $this->exigirVeredito(
            $extension,
            self::ITEM_ENTRADA,
            'AC',
            $run,
            "nao deu conta de 10^5 inteiros com E/S ingenua em {$run->measured_wall_ms} ms de parede"
        );
    }

    // ==================================================================
    // ITEM 8 -- RECURSÃO PROFUNDA
    // ==================================================================

    /**
     * O QUE PROVA: que 10^4 níveis de recursão -- profundidade banal num
     * problema de grafo -- não estouram a pilha.
     *
     * O QUE NÃO PROVA: onde exatamente cada linguagem estoura. Medir o
     * limite exato exigiria uma busca binária por linguagem, e o que
     * interessa a quem monta uma prova é o SIM/NÃO em 10^4.
     */
    #[DataProvider('casosDeRecursao')]
    public function test_recursao_de_dez_mil_niveis(string $extension)
    {
        $run = $this->julgarItem($extension, self::ITEM_RECURSAO);

        $this->exigirVeredito(
            $extension,
            self::ITEM_RECURSAO,
            'AC',
            $run,
            'nao suportou 10^4 niveis de recursao'
        );
    }

    // ==================================================================
    // ITEM 9 -- TEMPO DE PARTIDA
    // ==================================================================

    /**
     * O QUE PROVA: que o custo fixo de partida de cada linguagem é medido e
     * gravado (`measured_wall_ms`), e que está dentro da ordem de grandeza
     * que self::TETO_DE_PARTIDA_MS declara a partir da medição.
     *
     * O QUE NÃO PROVA: o número exato numa máquina qualquer. O teto tem uma
     * ordem de grandeza de folga de propósito -- o que ele pega é a
     * regressão de CATEGORIA (uma linguagem que passe a precisar de
     * segundos onde precisava de dezenas de milissegundos), e não a
     * variação de carga da máquina de quem roda a suíte.
     */
    #[DataProvider('casosDePartida')]
    public function test_tempo_de_partida_da_linguagem(string $extension)
    {
        $run = $this->julgarItem($extension, self::ITEM_PARTIDA);

        $this->exigirVeredito($extension, self::ITEM_PARTIDA, 'AC', $run, 'o programa minimo nao deu AC');

        // Sem medição gravada não há o que comparar -- e um teto que nunca
        // é aplicado é o "verde sobre mecanismo que nao funciona".
        $this->assertNotNull(
            $run->measured_wall_ms,
            "nenhum tempo foi gravado para {$extension}: o `time -f` da medicao nao rodou"
        );

        $teto = self::TETO_DE_PARTIDA_MS[$extension] ?? self::TETO_DE_PARTIDA_MS['default'];

        $this->assertLessThanOrEqual(
            $teto,
            $run->measured_wall_ms,
            "{$extension} leva {$run->measured_wall_ms} ms so para iniciar (teto declarado: {$teto} ms). "
            .'Uma linguagem cuja partida nao cabe no limite de um problema precisa de linha em '
            .'problem_language_limits, como o Portugol Studio tem.'
        );
    }

    // ==================================================================
    // ITEM 10 -- stderr
    // ==================================================================

    /**
     * Issue #268 -- confirmação de que continua valendo.
     *
     * O QUE PROVA: que o que o programa escreve em stderr NÃO entra na saída
     * comparada (senão o veredito seria WA) e ainda assim CHEGA ao juiz, em
     * `auto_judge_stderr`.
     *
     * O QUE NÃO PROVA: ordem entre stdout e stderr, que é indefinida.
     */
    #[DataProvider('casosDeStderr')]
    public function test_stderr_nao_contamina_a_saida_e_sobrevive_ao_aceite(string $extension)
    {
        $run = $this->julgarItem($extension, self::ITEM_STDERR);

        $this->exigirVeredito(
            $extension,
            self::ITEM_STDERR,
            'AC',
            $run,
            'stderr entrou na saida comparada'
        );

        $this->assertStringContainsString(
            'depuracao: li 3 e 5',
            (string) $run->auto_judge_stderr,
            "o stderr do programa em {$extension} sumiu em vez de ir para o diagnostico"
        );
    }

    // ==================================================================
    // ITEM 11 -- O CAMINHO SEM CGROUP DELEGADO
    // ==================================================================

    /**
     * O QUE PROVA: que o `a+b` correto de cada linguagem continua dando
     * `AC` quando não existe cgroup v2 delegado -- o caminho que
     * config/autojudge.php promete sustentar num contêiner não
     * privilegiado, num host em cgroup v1 ou na máquina de quem
     * desenvolve. Lá o `ulimit -v` da tabela `memory_grace_mb` entra no
     * lugar do `memory.max`, e uma linguagem esquecida naquela tabela não
     * inicia.
     *
     * Este item existe porque ele ACHOU um defeito: `java25` entrou no
     * catálogo (#305) sem entrar em `memory_grace_mb`, e nesse caminho
     * toda submissão Java 25 recebe `RE` com stdout e stderr vazios. O
     * `java21` ao lado, que está na tabela, dá `AC` na mesma execução --
     * esse é o controle positivo, e é o que torna a medição conclusiva.
     *
     * O QUE NÃO PROVA: que o limite de memória FUNCIONE nesse caminho --
     * isso é o item de MLE, e ele é medido com o cgroup. Aqui só se
     * pergunta se a linguagem ainda roda.
     */
    #[DataProvider('casosSemCgroup')]
    public function test_a_linguagem_roda_sem_cgroup_delegado(string $extension)
    {
        $run = $this->julgarItem($extension, self::ITEM_SEM_CGROUP);

        $this->exigirVeredito(
            $extension,
            self::ITEM_SEM_CGROUP,
            'AC',
            $run,
            'o programa correto nao roda sem cgroup delegado. O `ulimit -v` desta linguagem vem de '
            .'`memory_grace_mb` em config/autojudge.php, e um runtime que reserva mais espaco de '
            .'enderecamento do que a folga padrao nao chega a iniciar.'
        );
    }

    // ==================================================================
    // PROVEDORES DE DADOS
    // ==================================================================

    public static function casosDeErroDeExecucao(): array
    {
        return self::casosDe(self::ITEM_RE);
    }

    public static function casosDeErroDeCompilacao(): array
    {
        return self::casosDe(self::ITEM_CE);
    }

    public static function casosDeTempoExcedido(): array
    {
        return self::casosDe(self::ITEM_TLE);
    }

    public static function casosDeMemoriaExcedida(): array
    {
        return self::casosDe(self::ITEM_MLE);
    }

    public static function casosDeCodigoDeSaida(): array
    {
        return self::casosDe(self::ITEM_SAIDA);
    }

    public static function casosDePontoDecimal(): array
    {
        return self::casosDe(self::ITEM_PONTO);
    }

    public static function casosDeEntradaGrande(): array
    {
        return self::casosDe(self::ITEM_ENTRADA);
    }

    public static function casosDeRecursao(): array
    {
        return self::casosDe(self::ITEM_RECURSAO);
    }

    public static function casosDePartida(): array
    {
        return self::casosDe(self::ITEM_PARTIDA);
    }

    public static function casosDeStderr(): array
    {
        return self::casosDe(self::ITEM_STDERR);
    }

    public static function casosSemCgroup(): array
    {
        return self::casosDe(self::ITEM_SEM_CGROUP);
    }

    /**
     * Entram no provedor as linguagens que declaram CONFORME e as que
     * declaram DEFEITO_CONHECIDO -- as duas são MEDIDAS; o que muda é o
     * veredito exigido.
     *
     * Ficam de fora só NAO_SE_APLICA e NAO_MEDIDO, e o guarda
     * test_toda_excecao_declarada...() é o que impede isso de virar um
     * buraco silencioso.
     */
    private static function casosDe(string $item): array
    {
        $ativas = collect(Language::getDefaultLanguages())->where('is_active', true)->pluck('extension')->all();
        $casos = [];

        foreach (self::capacidades() as $extension => $linha) {
            if (! in_array($extension, $ativas, true)) {
                continue;
            }

            if (! in_array($linha['itens'][$item] ?? null, [self::CONFORME, self::DEFEITO_CONHECIDO, self::LIMITE_DA_LINGUAGEM], true)) {
                continue;
            }

            $casos[$extension] = [$extension];
        }

        return $casos;
    }

    /**
     * O único lugar em que um veredito é exigido.
     *
     * Onde a linguagem é CONFORME, exige o veredito certo. Onde há defeito
     * medido e registrado, exige o veredito ERRADO que o defeito produz
     * hoje -- e a mensagem de falha carrega qual seria o certo e onde o
     * defeito está anotado, para que nem o verde nem o vermelho deste teste
     * possam ser lidos errado.
     */
    private function exigirVeredito(string $extension, string $item, string $correto, Run $run, string $porque): void
    {
        $obtido = $run->answer?->short_name;
        $contexto = "\nstdout: {$run->auto_judge_stdout}\nstderr: {$run->auto_judge_stderr}";

        $this->pularSeForBackstopDeParede($extension, $item, $run);

        $limite = self::limites()[$extension][$item] ?? null;

        if ($limite !== null) {
            $this->assertSame(
                $limite['veredito'],
                $obtido,
                "{$extension}/{$item}: LIMITE DA LINGUAGEM, e não defeito do juiz.\n"
                ."O veredito fixado ('{$limite['veredito']}') é o correto: o juiz está certo, e quem não dá "
                ."conta é o runtime.\n"
                ."Medido: {$limite['porque']}\n"
                ."Documentado em: {$limite['onde']}\n"
                ."Se a falha acima diz que veio 'AC', a linguagem PASSOU A AGUENTAR -- troque a linha em "
                .'capacidades() para CONFORME, apague a entrada de limites() e tire o parágrafo do manual.'
                .$contexto
            );

            return;
        }

        $defeito = self::defeitos()[$extension][$item] ?? null;

        if ($defeito === null) {
            $this->assertSame($correto, $obtido, "{$extension}: {$porque}".$contexto);

            return;
        }

        $this->assertSame(
            $defeito['veredito'],
            $obtido,
            "{$extension}/{$item}: DEFEITO CONHECIDO ({$defeito['issue']}).\n"
            ."Este teste fixa o veredito errado de hoje ('{$defeito['veredito']}'); o correto seria "
            ."'{$defeito['correto']}'.\n"
            ."Medido: {$defeito['nota']}\n"
            ."Se a falha acima diz que veio '{$defeito['correto']}', o defeito FOI CONSERTADO: troque a "
            ."linha desta linguagem em capacidades() para CONFORME e feche {$defeito['issue']}."
            .$contexto
        );
    }

    /**
     * `CS` vindo do backstop de tempo de PAREDE não é medição de linguagem
     * -- é medição da máquina, e esta suíte não é sobre a máquina.
     *
     * POR QUE PULAR E NÃO REPROVAR. O `AutoJudgeService` tem dois backstops
     * de relógio, um na compilação e outro na execução, e quando eles
     * disparam o veredito é `CS` -- "a infraestrutura falhou". A própria
     * mensagem que o juiz grava diz, em voz alta, que "isto nao e um
     * veredito sobre o programa enviado". Tratar isso como falha da
     * linguagem seria afirmar sobre o `kotlinc` uma coisa que quem mediu foi
     * o relógio da máquina.
     *
     * MEDIDO AQUI, e é o que motivou este método: numa execução desta suíte
     * com outros contêineres disputando a mesma VM do Docker, `kt` e
     * `cpp_gpp13` receberam `CS` com "excedeu 30 s de tempo de parede na
     * etapa de compilacao". Sozinhos, os dois compilam em segundos. É a
     * #329 -- fechada pelo #350, que trocou a soma por multiplicação --
     * ainda alcançável quando a máquina está saturada.
     *
     * POR QUE ISTO NÃO É UM BURACO. O pulo é condicionado à ASSINATURA que o
     * próprio juiz escreve; qualquer outro `CS` continua reprovando. E um
     * teste pulado é contado e aparece no resumo do PHPUnit, ao contrário de
     * um `try/catch` que transformaria "não medimos" em "passou" -- que é o
     * modo de falha que o docblock desta classe já promete não cometer.
     */
    private function pularSeForBackstopDeParede(string $extension, string $item, Run $run): void
    {
        if ($run->answer?->short_name !== 'CS') {
            return;
        }

        $diagnostico = (string) $run->auto_judge_stderr;

        if (! str_contains($diagnostico, 'tempo de parede')) {
            return;
        }

        $this->markTestSkipped(
            "{$extension}/{$item}: o julgamento estourou um backstop de tempo de PAREDE do juiz e "
            .'recebeu CS. O proprio juiz registra que isto nao e um veredito sobre o programa; e a '
            .'maquina de julgamento que esta saturada (issue #329). Nada foi medido sobre a linguagem '
            ."aqui.\ndiagnostico: {$diagnostico}"
        );
    }

    // ==================================================================
    // O CORPO COMUM
    // ==================================================================

    /**
     * Monta uma prova de um caso só, envia o programa da família da
     * linguagem para o item pedido e julga de verdade -- nada mockado.
     */
    private function julgarItem(string $extension, string $item): Run
    {
        $linha = self::capacidades()[$extension];
        $programa = self::programas()[$linha['familia']][$item] ?? null;

        $this->assertNotNull($programa, "sem programa para {$extension} no item {$item}");

        [$arquivo, $fonte] = $programa;
        $prova = self::provaDoItem($item, $extension);

        // O item do caminho sem cgroup aponta `cgroup_root` para um
        // diretório que não existe, que é exatamente o que
        // CgroupMemoryLimiter::isAvailable() observa num contêiner não
        // privilegiado ou num host em cgroup v1. Com isso o
        // AutoJudgeService volta ao `ulimit -v` de `memory_grace_mb`, que é
        // o mecanismo sob medição.
        //
        // Vale porque `app(AutoJudgeService::class)` é resolvido DEPOIS
        // disto, em julgar(), e o limitador lê a configuração no construtor.
        if ($item === self::ITEM_SEM_CGROUP) {
            config(['autojudge.cgroup_root' => '/sys/fs/cgroup/sem-delegacao-nesta-suite']);
        }

        $run = $this->julgar($extension, $arquivo, $fonte, $prova);

        $this->registrarMedicao($extension, $item, $run);

        return $run;
    }

    /**
     * A medição vai para um arquivo, e não para a saída: phpunit.xml liga
     * `beStrictAboutOutputDuringTests`, e um `echo` aqui transformaria a
     * suíte inteira em "risky". O arquivo é o que alimenta a tabela
     * linguagem x item do relatório da auditoria.
     */
    private function registrarMedicao(string $extension, string $item, Run $run): void
    {
        $destino = storage_path('logs/conformidade-linguagens.jsonl');

        @file_put_contents($destino, json_encode([
            'linguagem' => $extension,
            'item' => $item,
            'veredito' => $run->answer?->short_name,
            'mensagem' => $run->auto_judge_result,
            'parede_ms' => $run->measured_wall_ms,
            'cpu_ms' => $run->measured_cpu_ms,
            'stdout' => mb_substr((string) $run->auto_judge_stdout, 0, 2000),
            'stderr' => mb_substr((string) $run->auto_judge_stderr, 0, 2000),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n", FILE_APPEND);
    }

    /**
     * @param  array{entrada: string, saida: string, tempo: int, memoria: int}  $prova
     */
    private function julgar(string $extension, string $arquivo, string $fonte, array $prova): Run
    {
        $contest = Contest::factory()->create([
            'is_active' => true,
            'start_time' => now()->subMinutes(5),
            'duration' => 300,
        ]);
        $site = Site::factory()->create(['contest_id' => $contest->id]);

        foreach (Language::getDefaultLanguages() as $lang) {
            Language::create(array_merge($lang, ['contest_id' => $contest->id]));
        }
        $language = Language::where('contest_id', $contest->id)->where('extension', $extension)->firstOrFail();

        // A tabela completa de vereditos, e não só os quatro que o teste de
        // AC precisava: sem a linha de `TLE` ou `MLE` o AutoJudgeService
        // grava `answer_id` nulo e o teste leria "sem veredito" onde na
        // verdade o juiz acertou.
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

        $problem = Problem::factory()->create([
            'contest_id' => $contest->id,
            'memory_limit' => $prova['memoria'],
        ]);

        // Issue #269 -- o limite por linguagem, usado no caso real que o
        // motivou: o Portugol Studio precisa de 2,72 s de CPU só para
        // iniciar (medido), e o limite padrão de um problema é 1 s.
        // Afrouxar o limite do PROBLEMA daria tempo extra a todas as
        // linguagens, que é exatamente o que este campo existe para evitar.
        ProblemLanguageLimit::create([
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'time_limit' => $prova['tempo'],
        ]);

        $inputRelative = "problems/{$contest->id}/{$problem->id}/input/1";
        $outputRelative = "problems/{$contest->id}/{$problem->id}/output/1";
        Storage::disk('local')->put($inputRelative, $prova['entrada']);
        Storage::disk('local')->put($outputRelative, $prova['saida']);

        ProblemTestCase::create([
            'problem_id' => $problem->id,
            'number' => 1,
            'input_file' => $inputRelative,
            'output_file' => $outputRelative,
            'input_hash' => hash('sha256', $prova['entrada']),
            'output_hash' => hash('sha256', $prova['saida']),
            'is_sample' => true,
        ]);

        // O sufixo existe porque um teste só pode julgar DUAS vezes: o item
        // de erro de execução roda o controle positivo antes do programa que
        // morre, e os dois envios vivem no mesmo banco.
        $sufixo = ++$this->envios;

        $team = User::create([
            'fullname' => 'Conformance Team',
            'username' => "conf_{$extension}_{$sufixo}",
            'email' => "conf-{$extension}-{$sufixo}@example.com",
            'password' => bcrypt('password'),
            'user_type' => 'team',
            'is_enabled' => true,
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ]);

        Bus::fake();

        $file = UploadedFile::fake()->createWithContent($arquivo, $fonte);

        $this->actingAs($team)
            ->post("/submit/{$problem->id}", ['language_id' => $language->id, 'source_file' => $file])
            ->assertRedirect();

        $run = Run::where('contest_id', $contest->id)->where('user_id', $team->user_id)->firstOrFail();

        app(AutoJudgeService::class)->judge($run->fresh());

        return $run->fresh();
    }
}
