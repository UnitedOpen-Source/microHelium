<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Language extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contest_id',
        'name',
        'extension',
        'compile_command',
        'run_command',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Contest, $this> */
    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    /** @return HasMany<Run, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * Get default languages with unique extensions
     * Each language has a unique extension identifier to avoid database conflicts
     */
    public static function getDefaultLanguages(): array
    {
        return [
            // C/C++ Languages
            ['name' => 'C (GCC 15)', 'extension' => 'c_gcc13', 'file_ext' => 'c', 'compile_command' => 'gcc -static -O2 -std=c17 -o {output} {source} -lm', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'C (Clang 22)', 'extension' => 'c_clang17', 'file_ext' => 'c', 'compile_command' => 'clang -static -O2 -std=c17 -o {output} {source} -lm', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'C99 (GCC 15)', 'extension' => 'c99_gcc', 'file_ext' => 'c', 'compile_command' => 'gcc -static -O2 -std=c99 -o {output} {source} -lm', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'C++ (G++ 15)', 'extension' => 'cpp_gpp13', 'file_ext' => 'cpp', 'compile_command' => 'g++ -static -O2 -std=c++20 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'C++14 (G++ 15)', 'extension' => 'cpp14_gpp', 'file_ext' => 'cpp', 'compile_command' => 'g++ -static -O2 -std=c++14 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'C++17 (G++ 15)', 'extension' => 'cpp17_gpp', 'file_ext' => 'cpp', 'compile_command' => 'g++ -static -O2 -std=c++17 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'C++ (Clang 22)', 'extension' => 'cpp_clang', 'file_ext' => 'cpp', 'compile_command' => 'clang++ -static -O2 -std=c++20 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],

            // Java -- issues #303 e #305: CAMINHO ABSOLUTO, e nao `javac`.
            //
            // Ate aqui as tres entradas de Java diziam `javac`/`java`, que
            // resolvem pelo PATH para o `default-jvm` -- ou seja, para uma
            // unica JVM. Medido antes da mudanca: ativar `java17` rodava
            // `javac 21.0.12`. O comentario do catalogo dizia que entradas
            // assim "would silently fail"; elas nao falhavam, rodavam a
            // VERSAO ERRADA em silencio, que e pior -- a equipe escolhe uma
            // versao, recebe outra, e nada registra a diferenca.
            //
            // O Alpine instala cada JDK no seu proprio prefixo
            // (`/usr/lib/jvm/java-NN-openjdk`), entao o caminho absoluto e o
            // que torna "manter a versao antiga ao lado da nova" verdadeiro.
            // Medido na imagem: java-21 -> javac 21.0.12, java-25 -> javac
            // 25.0.4.
            //
            // De quebra isso conserta o roteamento: `MachineCapabilities`
            // sonda o PRIMEIRO TOKEN do comando e ja trata caminho absoluto
            // diretamente, entao um host com apenas o JDK 21 deixa de
            // anunciar `java25` -- coisa que com `javac` era impossivel
            // distinguir.
            //
            // `java17` FOI LIGADA em 21/09/2026, e o que a destravou foi
            // a medicao que faltava, nao o pacote. Os tres Dockerfiles
            // passam a instalar `openjdk17-jdk=17.0.20_p8-r0`, e dentro de
            // uma imagem com os tres JDKs foi medido:
            //
            //   /usr/lib/jvm/java-17-openjdk/bin/javac -version -> 17.0.20
            //   /usr/lib/jvm/default-jvm -> java-25-openjdk  (NAO se moveu)
            //   kotlinc -version -> kotlinc-jvm 2.4.20 (JRE 25.0.4+7)
            //
            // A segunda linha era o risco real de ligar uma LTS MENOR: o
            // `apk` aponta o `default-jvm` para o MAIOR JDK instalado, entao
            // quem chama `java` pelo PATH (`kt`, Portugol Studio) continua
            // no 25. O custo medido por `apk add --simulate` sobre a imagem
            // que ja tem 21 e 25 e +259,0 MiB (os +326 MiB da #305 sao do
            // JDK 25 sobre uma base sem JVM nenhuma).
            //
            // E o caminho absoluto de cada uma e guardado por
            // tests/Unit/Judge/PromessaDeVersaoTest.php: apontar `java17`
            // para `java-21-openjdk` reprova na suite de todo PR, sem
            // precisar de JDK instalado.
            // Issue #386 -- `-Xss` explicito, porque o padrao da plataforma
            // nao cabe.
            //
            // O HotSpot fixa `ThreadStackSize` por plataforma
            // (`os_cpu/<so>_<arch>/globals_*.hpp`), e em **linux x86_64 o
            // valor e 1024 KB** -- metade do que aarch64 usa (2040/2048 KB).
            // Uma recursao de 10^4 niveis, o item que esta suite mede em
            // toda linguagem, pede POUCO MAIS que isso. Medido num conteiner
            // `linux/amd64`, com o `a+b` recursivo do proprio teste:
            //
            //     sem -Xss (1024k)   0/10 corretas, StackOverflowError
            //     -Xss1024k          0/5
            //     -Xss1280k          5/5
            //     -Xss1536k e acima  5/5
            //
            // A margem no padrao e ~zero, e e dai que vem a intermitencia: o
            // `java21` derrubou o CI do #371 e passou na re-execucao do MESMO
            // commit. Nossa medicao local nunca reproduziu porque e toda em
            // aarch64, que tem o dobro da folga -- a mesma assimetria que a
            // #339 registra.
            //
            // 8 MB e o `ulimit -s` do conteiner, ou seja a pilha que o SO da
            // a um programa NATIVO. O criterio e esse: uma recursao que o C++
            // aguenta nao deve reprovar so por estar em Java. Nao e numero
            // arbitrario nem margem inventada.
            //
            // Nao custa memoria real: `-Xss` reserva espaco de ENDERECAMENTO
            // por thread, e `addressSpaceLimitKbFor()` ja devolve null para a
            // JVM (grace explicitamente nula em config/autojudge.php), entao
            // nao ha `ulimit -v` para esbarrar. O veredito de memoria vem do
            // pico de RSS.
            ['name' => 'Java (OpenJDK 25 LTS)', 'extension' => 'java25', 'file_ext' => 'java', 'compile_command' => '/usr/lib/jvm/java-25-openjdk/bin/javac {source}', 'run_command' => '/usr/lib/jvm/java-25-openjdk/bin/java -Xss8m -Xmx{memory}m {classname}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'Java (OpenJDK 21 LTS)', 'extension' => 'java21', 'file_ext' => 'java', 'compile_command' => '/usr/lib/jvm/java-21-openjdk/bin/javac {source}', 'run_command' => '/usr/lib/jvm/java-21-openjdk/bin/java -Xss8m -Xmx{memory}m {classname}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'Java (OpenJDK 17 LTS)', 'extension' => 'java17', 'file_ext' => 'java', 'compile_command' => '/usr/lib/jvm/java-17-openjdk/bin/javac {source}', 'run_command' => '/usr/lib/jvm/java-17-openjdk/bin/java -Xss8m -Xmx{memory}m {classname}', 'is_active' => true, 'category' => 'compiled'],

            // Python
            // Issue #327 -- o `PYTHONPATH` poe
            // `resources/judge-runtime/python/sitecustomize.py` no caminho,
            // e o modulo `site` o importa na partida do processo do
            // competidor: `sys.setrecursionlimit(200000)` no lugar dos 1000
            // que sao o padrao do CPython. O comando continua sendo
            // `python3 {source}`, entao o traceback continua apontando para
            // o arquivo submetido. A medicao (e por que isto NAO troca o
            // `RecursionError` por um segfault) esta no cabecalho daquele
            // arquivo.
            ['name' => 'Python 3.14', 'extension' => 'py3', 'file_ext' => 'py', 'compile_command' => 'python3 -m py_compile {source}', 'run_command' => 'PYTHONPATH={judge_runtime}/python python3 {source}', 'is_active' => true, 'category' => 'interpreted'],
            // PyPy has no musl/Alpine build (upstream only ships glibc
            // binaries), so it's left inactive rather than silently failing
            // every submission; CPython 3.12 above covers the language.
            ['name' => 'Python 3 (PyPy)', 'extension' => 'pypy3', 'file_ext' => 'py', 'compile_command' => 'pypy3 -m py_compile {source}', 'run_command' => 'pypy3 {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Python 2.7', 'extension' => 'py2', 'file_ext' => 'py', 'compile_command' => 'python2 -m py_compile {source}', 'run_command' => 'python2 {source}', 'is_active' => false, 'category' => 'interpreted'],

            // JavaScript / Node.js -- a imagem instala UM unico Node (a LTS
            // corrente, pelo pacote `nodejs` do Alpine), entao so uma destas
            // entradas e real; as outras duas ficam no catalogo como a
            // promessa que a #305 quer cumprir, e nao como oferta.
            //
            // Issue #303 -- a razao escrita aqui antes estava ERRADA, e a
            // medicao a desmentiu. Dizia que seleciona-las hoje "would
            // silently fail". Nao falha: `node` resolve pelo PATH, entao
            // `js_node20` rodaria `node v24.18.1` -- a versao errada, em
            // silencio. Isso e pior do que falhar, e e exatamente o motivo
            // de as entradas de Java acima terem passado a caminho absoluto.
            //
            // Node NAO TEM a mesma saida, e o motivo e mais forte do que
            // "o Alpine nao publica um pacote por versao". Medido em
            // 21/09/2026 numa `php:8.3-cli-alpine` (Alpine 3.24.2, aarch64):
            //
            //   apk search -x nodejs nodejs-current
            //     -> nodejs-24.18.1-r0
            //        nodejs-current-26.5.1-r0     (nao ha nodejs20/nodejs22)
            //   apk info --provides nodejs-current
            //     -> nodejs
            //        cmd:node=26.5.1-r0
            //   apk add --simulate nodejs nodejs-current
            //     -> (6/6) Installing nodejs-current (26.5.1-r0)   [so um]
            //
            // O Alpine modela o Node como um unico PROVEDOR de `cmd:node`:
            // pedir os dois instala um, e o outro some sem erro. Ou seja, a
            // mesma falha silenciosa da #305, uma camada abaixo. Node lado a
            // lado e Lote D (artefato de fora do Alpine, fixado por versao e
            // por hash, como Kotlin/Scala/Dart ja sao) -- nao e `apk add`.
            //
            // Enquanto isso, o que impede a armadilha de ser armada de novo
            // e tests/Unit/Judge/PromessaDeVersaoTest.php: ligar `js_node20`
            // ou `js_node22` ao lado do `js_node24` sem antes dar a cada um
            // um comando que distinga a versao reprova na suite de todo PR.
            //
            // Issue #327 -- o `node` passa por
            // `resources/judge-runtime/node/run.sh`, que acrescenta um
            // `--stack-size` DERIVADO do `ulimit -s` do host. Uma recursao
            // de 10^4 niveis recebia `RE` aqui e passava em vinte outras
            // linguagens; fixar o numero no catalogo seria a armadilha que a
            // issue avisa (acima da pilha do sistema o V8 troca o
            // `RangeError` por SIGSEGV). A medicao do penhasco esta no
            // cabecalho do script.
            ['name' => 'JavaScript (Node 24 LTS)', 'extension' => 'js_node24', 'file_ext' => 'js', 'compile_command' => 'node --check {source}', 'run_command' => 'bash {judge_runtime}/node/run.sh {memory} {source}', 'is_active' => true, 'category' => 'interpreted'],
            ['name' => 'JavaScript (Node 22)', 'extension' => 'js_node22', 'file_ext' => 'js', 'compile_command' => 'node --check {source}', 'run_command' => 'bash {judge_runtime}/node/run.sh {memory} {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'JavaScript (Node 20 LTS)', 'extension' => 'js_node20', 'file_ext' => 'js', 'compile_command' => 'node --check {source}', 'run_command' => 'bash {judge_runtime}/node/run.sh {memory} {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'TypeScript (Node 24)', 'extension' => 'ts', 'file_ext' => 'ts', 'compile_command' => 'npx tsc --strict --module commonjs {source}', 'run_command' => 'bash {judge_runtime}/node/run.sh {memory} {executable}.js', 'is_active' => true, 'category' => 'compiled'],

            // Issue #268 -- Scratch, julgado como qualquer outra linguagem.
            //
            // O projeto do participante e um `.sb3` (um ZIP), e o
            // `scratch-run` o transforma num programa que le stdin e escreve
            // stdout pela convencao de blocos do VNOJ:
            //
            //     say [texto]                  escreve linha
            //     think [texto]                escreve sem quebra
            //     ask [read_token] and wait    le um token
            //     ask [outra coisa] and wait   le uma linha
            //
            // `--check` e a etapa de COMPILACAO: valida o projeto e sai 0 ou
            // 1. Existe de verdade para uma linguagem que nao compila, que e
            // o que este pipeline exige -- `compile_command` e obrigatorio em
            // LanguageController.
            //
            // `is_active` true pela regra que este catalogo ja segue: as
            // entradas inativas acima estao inativas porque "selecting them
            // today would silently fail" -- ou seja, INATIVO quer dizer
            // toolchain ausente. A do Scratch esta na imagem, entao marca-la
            // inativa mentiria sobre o motivo.
            //
            // E ligada ela ganha a verificacao que importa: o
            // MultiLanguageJudgingTest julga um `.sb3` de verdade dentro da
            // imagem do juiz, no job Judging do CI. Quem nao quiser Scratch
            // numa prova desativa a linguagem naquele contest.
            ['name' => 'Scratch', 'extension' => 'scratch', 'file_ext' => 'sb3', 'compile_command' => 'scratch-run --check {source}', 'run_command' => 'scratch-run {source}', 'is_active' => true, 'category' => 'interpreted'],

            // Issue #269, parte A -- Portugol Studio (UNIVALI), o
            // pseudocodigo em portugues usado para ensinar algoritmos no
            // Brasil. +200 mil usuarios em universidades e institutos.
            //
            // A ETAPA DE COMPILACAO NAO E O CONSOLE, e isso nao e estilo.
            // Medido, o console:
            //
            //   com -no-wait, programa invalido      -> codigo 0
            //   sem -no-wait, programa invalido      -> codigo 1
            //   sem -no-wait, programa valido que le -> codigo 1 (falso CE:
            //                                           stdin fechado)
            //   sem -no-wait, laco infinito          -> TRAVA
            //
            // Nao ha combinacao de flags que analise sem executar. Com
            // -no-wait o erro de sintaxe passaria da compilacao e viraria WA
            // -- dizendo a equipe que a resposta esta errada quando o
            // programa nem compilou. `portugol-studio-check` chama
            // `Portugol.compilarParaAnalise()`, que analisa e nao executa.
            //
            // A ETAPA DE EXECUCAO TAMBEM NAO E O CONSOLE -- issue #301.
            // Ela era, com `-no-wait`, e esse era o preco: o console sai com
            // 0 e sem uma linha de stderr quando o programa MORRE (divisao
            // por zero, indice fora do vetor, entrada malformada), entao
            // erro de execucao virava WA -- e o caso e pior que o da
            // compilacao, porque Portugol e a linguagem dos INICIANTES, para
            // quem "resposta errada, sem explicacao" no lugar de "erro de
            // execucao na linha 5" e o pior retorno possivel.
            //
            // O `-no-wait` nao podia sair (sem ele o console imprime
            // "Programa finalizado" e "Pressione ENTER para continuar" na
            // saida comparada, e um laco trava a fila), e a causa e upstream
            // -- UNIVALI-LITE/Portugol-Studio#1218, num `Console.java` que
            // nao e tocado desde 2019. Entao quem saiu foi o console:
            // `portugol-studio` agora e o `ExecutaPortugol`, que chama
            // `Portugol.compilarParaExecucao()` e tira o codigo de saida do
            // `ResultadoExecucao`.
            ['name' => 'Portugol Studio', 'extension' => 'portugol_studio', 'file_ext' => 'por', 'compile_command' => 'portugol-studio-check {source}', 'run_command' => 'portugol-studio {source}', 'is_active' => true, 'category' => 'interpreted'],

            // Issue #296, parte B -- G-Portugol (.gpt), o OUTRO dialeto de
            // portugol, compilado e com tipos declarados.
            //
            // A issue nasceu registrando TRES bloqueios medidos, e os tres
            // caiam juntos se o compilador nao construisse: o Alpine nao
            // empacota ANTLR, o build no Debian morria em "Token stream
            // error reading grammar(s)", e o unico binario publicado pelo
            // upstream e x86_64. Nenhum dos tres sobreviveu a medicao nova,
            // e o que os derrubou foi UMA variavel de ambiente:
            //
            //   o `runantlr` roda o ANTLR 2.7.7 na JVM, e o ANTLR 2.7.7 le
            //   as gramaticas no CHARSET PADRAO DA JVM. Num contêiner sem
            //   `LANG`, esse padrao e ASCII -- e `lexer.g` tem `algoritmo`
            //   com acento dentro. Dai o `0xFFFD` do diagnostico. Com
            //   `-Dfile.encoding=UTF-8` (ou qualquer `LANG` UTF-8) as seis
            //   gramaticas passam e o `make` vai ao fim.
            //
            // Com o build funcionando, o resto saiu junto: o runtime C++ do
            // ANTLR 2.7 compila em musl (so precisa de um `config.sub` deste
            // seculo, porque o de 2006 nao conhece `aarch64`), e dai o `gpt`
            // e construido NA PROPRIA IMAGEM, em aarch64 e em x86_64 -- o
            // que resolve tambem a #53, sem depender do binario oficial.
            //
            // O COMPILE_COMMAND E DE DUAS ETAPAS, E ISSO E OBRIGATORIO.
            // O `gpt` sabe gerar executavel sozinho (`-o`), e nao pode: o
            // backend dele emite NASM de 32 bits sem olhar a arquitetura.
            // Medido em aarch64 com nasm instalado -- SAI COM 0 e escreve um
            // `ELF 32-bit Intel i386` que estoura com SIGSEGV; e sem nasm,
            // que e o caso da imagem, morre com `nasm: not found`.
            // Compilacao que passa e binario que morre e todo AC virando RE,
            // e a segunda forma nao julgaria nada. O `gpt -t` traduz para
            // C, o `gcc` da imagem compila, e o alvo passa a ser o da
            // maquina. O resto esta em resources/judge-runtime/gportugol/.
            //
            // E o CE sai de graca, sem o contorno que o Portugol Studio
            // precisou: `src/main.cpp:271` e `return success ? EXIT_SUCCESS
            // : EXIT_FAILURE`, e medido, erro de sintaxe sai com 1 e escreve
            // `solution.gpt:4 - ...` em stderr. (O `gpt -i`, esse, tem o
            // defeito da familia da #301: MEDIDO, erro de sintaxe SAI COM 0.
            // Por isso a compilacao e o `-t`, e nao o interpretador.)
            ['name' => 'G-Portugol (1.2)', 'extension' => 'gportugol', 'file_ext' => 'gpt', 'compile_command' => 'bash {judge_runtime}/gportugol/compile.sh {source} {output}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],

            // JVM Languages
            ['name' => 'Kotlin (2.4)', 'extension' => 'kt', 'file_ext' => 'kt', 'compile_command' => 'kotlinc {source} -include-runtime -d {output}.jar', 'run_command' => 'java -Xss8m -jar {executable}.jar', 'is_active' => true, 'category' => 'compiled'],
            // Issue #305, Lote D -- Scala e Groovy, as duas linguagens do
            // lote que a imagem ja estava a um `unzip` de ter.
            //
            // Nenhuma das duas tem pacote no Alpine 3.24 (`apk search -x
            // scala3 groovy` nao devolve nada), mas nenhuma das duas precisa
            // de pacote: sao JVM puras, e a imagem ja carrega dois JDKs. O
            // que entra e um zip oficial descompactado em /opt, como o
            // Kotlin ja fazia -- com o sha256 conferido, que o Kotlin ainda
            // nao faz (#304).
            //
            // O `{classname}` de Scala e o mesmo mecanismo do Java: o
            // AutoJudgeService o substitui pelo nome do arquivo sem
            // extensao, entao `Main.scala` tem de declarar `object Main`.
            // Issue #386 -- `-J-Xss8m`: o lancador do Scala tira o `-J` e
            // repassa o resto a JVM (`addJava "${1:2}"` em `bin/scala`).
            //
            // Esta entrada era o CONTROLE da tabela de conformidade: o texto
            // do `clj` dizia que o `scala` "na MESMA JVM faz os 10^4 niveis",
            // e concluia dai que o caro era o quadro do Clojure e nao a
            // pilha. A conclusao estava errada, e o CI a desmentiu: em
            // 22/09/2026 o item de recursao do `scala` reprovou no job do
            // juiz, no mesmo teste que derrubou o `java21` no dia anterior.
            //
            // Medido com a distribuicao 3.3.8 desta imagem:
            //
            //     scala Main               (2048k, aarch64)  50005000
            //     scala -J-Xss8m Main                        50005000
            //     scala -J-Xss1024k Main   (padrao x86_64)   StackOverflowError
            //
            // O controle so parecia solido porque toda medicao nossa era em
            // aarch64, que tem o dobro da pilha padrao.
            ['name' => 'Scala 3 (3.3 LTS)', 'extension' => 'scala', 'file_ext' => 'scala', 'compile_command' => 'scalac {source}', 'run_command' => 'scala -J-Xss8m {classname}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'Groovy 4', 'extension' => 'groovy', 'file_ext' => 'groovy', 'compile_command' => 'groovyc {source}', 'run_command' => 'groovy {source}', 'is_active' => true, 'category' => 'interpreted'],
            // Issue #305, Lote C -- Clojure pelo JAR, e nao pela CLI.
            //
            // Os dois comandos anteriores nunca foram exercitados, e nenhum
            // dos dois funciona. Medido dentro do sandbox:
            //
            //   `clojure -M ... (compile 'main)` -> NullPointerException:
            //       `compile` exige `*compile-path*` num diretorio do
            //       classpath e um namespace chamado `main`, que uma
            //       submissao solta nao tem. E `compile` AVALIA o programa,
            //       o que uma etapa de compilacao nao pode fazer.
            //   `clojure {source}`               -> "Error building
            //       classpath ... org.clojure:clojure:jar:1.12.5": a CLI e o
            //       tools.deps, e resolve dependencia no Maven Central a
            //       cada partida. O sandbox nao tem rede, e troca o HOME por
            //       um diretorio novo, entao nenhum cache pre-aquecido
            //       sobrevive.
            //
            // Os dois invocadores da imagem (docker/judge/bin/) chamam o
            // `clojure.jar` que o proprio pacote do Alpine ja traz, que roda
            // offline. Nomes proprios, e nao `java -cp ...`, porque o
            // roteamento por capacidade olha o primeiro token do comando.
            ['name' => 'Clojure 1.12', 'extension' => 'clj', 'file_ext' => 'clj', 'compile_command' => 'clojure-check {source}', 'run_command' => 'clojure-run {source}', 'is_active' => true, 'category' => 'interpreted'],

            // .NET Languages
            // `dotnet build`/`dotnet run` need a project, not a bare .cs
            // file, so C# uses wrapper scripts that scaffold a throwaway
            // console project around the submitted source before building
            // it (see resources/judge-runtime/csharp/). {judge_runtime} is
            // resolved by AutoJudgeService, not here -- this array must stay
            // callable from contexts where the app isn't booted yet (e.g.
            // PHPUnit data providers), where base_path() isn't available.
            //
            // Issue #305 -- as DUAS LTS de .NET, lado a lado, e o primeiro
            // token deixando de ser `bash`.
            //
            // Os dois SDKs convivem sob um `dotnet` so
            // (`/usr/lib/dotnet/sdk/{8.0.131,10.0.303}`), e quem escolhe
            // entre eles e o `global.json` que o compile.sh escreve -- nao
            // ha caminho absoluto por versao como ha no Java. Isso deixava
            // as duas entradas com comandos identicos a menos de uma
            // variavel de ambiente, e `MachineCapabilities::executableOf()`
            // sonda o PRIMEIRO TOKEN: com `bash` nos dois, todo host com
            // bash anunciaria as duas e receberia trabalho que nao sabe
            // fazer.
            //
            // Dai os invocadores `csharp-net8`/`csharp-net10`
            // (docker/judge/bin/), pelo mesmo motivo que `scratch-run`,
            // `portugol-studio` e `clojure-run` tem nome proprio. Medido
            // numa imagem com os dois SDKs, removendo
            // /usr/local/bin/csharp-net10: a sonda passa a anunciar
            // `cs_dotnet` sem `cs_dotnet10`, que e a verdade daquele host.
            ['name' => 'C# (.NET 10 LTS)', 'extension' => 'cs_dotnet10', 'file_ext' => 'cs', 'compile_command' => 'csharp-net10 {judge_runtime}/csharp/compile.sh {source} {output}', 'run_command' => 'csharp-net10 {judge_runtime}/csharp/run.sh {executable} {memory}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'C# (.NET 8 LTS)', 'extension' => 'cs_dotnet', 'file_ext' => 'cs', 'compile_command' => 'csharp-net8 {judge_runtime}/csharp/compile.sh {source} {output}', 'run_command' => 'csharp-net8 {judge_runtime}/csharp/run.sh {executable} {memory}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'C# (Mono)', 'extension' => 'cs_mono', 'file_ext' => 'cs', 'compile_command' => 'mcs -out:{output}.exe {source}', 'run_command' => 'mono {executable}.exe', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'F# (.NET 8)', 'extension' => 'fs_dotnet', 'file_ext' => 'fs', 'compile_command' => 'dotnet build', 'run_command' => 'dotnet run', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Visual Basic (.NET 8)', 'extension' => 'vb', 'file_ext' => 'vb', 'compile_command' => 'dotnet build', 'run_command' => 'dotnet run', 'is_active' => false, 'category' => 'compiled'],

            // Systems Languages
            ['name' => 'Rust (1.96)', 'extension' => 'rs', 'file_ext' => 'rs', 'compile_command' => 'rustc -O -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'Go (1.26)', 'extension' => 'go', 'file_ext' => 'go', 'compile_command' => 'go build -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'D (DMD)', 'extension' => 'd_dmd', 'file_ext' => 'd', 'compile_command' => 'dmd -of={output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'D (LDC 1.42)', 'extension' => 'd_ldc', 'file_ext' => 'd', 'compile_command' => 'ldc2 -of={output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'Nim 2.2', 'extension' => 'nim', 'file_ext' => 'nim', 'compile_command' => 'nim c -o:{output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            // Issue #305 -- `-o` nao existe no `zig build-exe`. Medido:
            // "error: unrecognized parameter: '-o'". Quem nomeia o binario e
            // `-femit-bin=`; sem ele o Zig batiza a saida com o nome do
            // arquivo raiz, o que por acidente daria certo neste pipeline e
            // erraria em qualquer problema com script de compilacao proprio.
            ['name' => 'Zig 0.16', 'extension' => 'zig', 'file_ext' => 'zig', 'compile_command' => 'zig build-exe -femit-bin={output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],

            // Scripting Languages
            // Issue #324 -- o limite de memoria do PROBLEMA passa a chegar ao
            // PHP. Sem `-d memory_limit` valia o teto do proprio
            // interpretador -- 128 MB, medido nesta imagem --, entao uma
            // submissao PHP num problema de 256 MB morria na metade do
            // orcamento que o enunciado prometeu, e morria como `RE`.
            //
            // O `{memory}` e o mesmo que o `-Xmx{memory}m` do Java ao lado
            // usa, e a resposta aqui e a mesma que o repositorio ja deu para
            // a JVM: o runtime recebe o limite do problema e o VEREDITO sai
            // do juiz (cgroup, ou pico de RSS onde nao ha cgroup).
            //
            // Medido nesta imagem, o mesmo programa que aloca blocos de
            // 8 MB sem parar, com `/usr/bin/time -f %M`:
            //
            //     php hog.php                      rc=255  pico 148.460 KB
            //     php -d memory_limit=256M hog.php rc=255  pico 279.464 KB
            //
            // Com o limite de 256 MB do problema, 148 MB de pico nao passam
            // do teto e o juiz cai no ramo do codigo de saida -> `RE`; os
            // 279 MB passam, e o ramo do pico de RSS responde `MLE` antes.
            // A correcao de orcamento e a correcao de veredito sao a mesma
            // linha, e nao duas.
            //
            // Issue #356 -- `-d opcache.enable_cli=0` porque o php.ini da
            // APLICACAO chegava ao interpretador da SUBMISSAO.
            //
            // O Dockerfile da aplicacao instala `docker/php/opcache.ini` em
            // `/usr/local/etc/php/conf.d/`, e ali ele vale para TODA SAPI:
            // `opcache.enable_cli=1` com `opcache.memory_consumption=256`.
            // O sandbox monta `/usr` read-only (`autojudge.sandbox_paths`),
            // entao esse conf.d entra no bwrap junto com o binario, e o
            // `php` que roda o codigo da equipe mapeia 256 MB de memoria
            // compartilhada ANTES da primeira linha do programa.
            //
            // Onde nao ha cgroup v2 delegado -- que e o caminho da FILA,
            // porque `docker/php/entrypoint.sh` nao delega e o contêiner da
            // aplicacao serve HTTP --, `addressSpaceLimitKbFor()` aplica
            // `ulimit -v` de limite+64 MB. Num problema de 256 MB sao
            // 320 MB de espaco de enderecamento, dos quais o opcache ja
            // levou 256: sobram ~44 MB para a equipe.
            //
            // Medido na imagem da aplicacao (`Dockerfile`), com
            // `--privileged`, mesmo commit e mesma montagem:
            //
            //     antes  160 MB num problema de 256 MB -> RE
            //            "mmap() failed: [12] Out of memory"
            //     antes  programa que estoura           -> RE (pico 10 MB,
            //            morre no mmap antes de o pico de RSS decidir)
            //     depois 160 MB num problema de 256 MB -> AC
            //     depois programa que estoura           -> MLE
            //
            // O JIT nao entra na conta: `opcache.jit_buffer_size=256M` esta
            // no mesmo arquivo, mas a imagem tambem instala o pcov, que
            // sobrescreve `zend_execute_ex()`, e o PHP responde "JIT is
            // incompatible with third party extensions [...] JIT disabled"
            // e nao reserva o buffer. Era o que deixava um `a+b` passar e
            // so os dois casos de memoria reprovarem.
            //
            // Desligar aqui, e nao no arquivo `.ini`, porque a chave existe
            // para o `queue:work` da APLICACAO, que e um processo longo e
            // se beneficia dela. Quem nao pode herda-la e o processo de UMA
            // submissao. E vale para qualquer operador: um `php.ini` de
            // terceiro com opcache grande quebraria este juiz do mesmo
            // jeito, e o catalogo viaja com o sistema.
            ['name' => 'PHP 8.3', 'extension' => 'php', 'file_ext' => 'php', 'compile_command' => 'php -l {source}', 'run_command' => 'php -d opcache.enable_cli=0 -d memory_limit={memory}M {source}', 'is_active' => true, 'category' => 'interpreted'],
            ['name' => 'Ruby 3.4', 'extension' => 'rb', 'file_ext' => 'rb', 'compile_command' => 'ruby -c {source}', 'run_command' => 'ruby {source}', 'is_active' => true, 'category' => 'interpreted'],
            ['name' => 'Perl 5', 'extension' => 'perl', 'file_ext' => 'pl', 'compile_command' => 'perl -c {source}', 'run_command' => 'perl {source}', 'is_active' => true, 'category' => 'interpreted'],
            // Issue #305 -- `lua` e `luac` NAO existem no Alpine. O pacote
            // `lua5.4` instala `/usr/bin/lua5.4` e `/usr/bin/luac5.4`, e nao
            // ha link sem sufixo: os comandos anteriores dariam "command not
            // found" na primeira submissao. O nome ja dizia 5.4 e continua
            // verdadeiro (lua 5.4.8).
            ['name' => 'Lua 5.4', 'extension' => 'lua', 'file_ext' => 'lua', 'compile_command' => 'luac5.4 -p {source}', 'run_command' => 'lua5.4 {source}', 'is_active' => true, 'category' => 'interpreted'],
            ['name' => 'Bash', 'extension' => 'sh', 'file_ext' => 'sh', 'compile_command' => 'bash -n {source}', 'run_command' => 'bash {source}', 'is_active' => true, 'category' => 'interpreted'],
            // Issue #305 -- `-o/dev/null` e o que torna esta uma etapa de
            // COMPILACAO. O comando anterior (`gawk --lint -f {source}
            // /dev/null`) analisa o programa mas tambem o EXECUTA, com
            // /dev/null no lugar da entrada: um BEGIN que imprimisse alguma
            // coisa imprimiria ali. `-o` (--pretty-print) faz o gawk so
            // analisar e despejar o programa formatado, sem rodar -- medido
            // com um BEGIN que imprime, e ele nao imprimiu.
            ['name' => 'AWK (GAWK 5.3)', 'extension' => 'awk', 'file_ext' => 'awk', 'compile_command' => 'gawk --lint -o/dev/null -f {source}', 'run_command' => 'gawk -f {source}', 'is_active' => true, 'category' => 'interpreted'],
            // Issue #323 -- a etapa de compilacao do `sed` passa a ANALISAR o
            // script. O comando anterior era `sed -n "q" {source}`, em que
            // `{source}` nao e o programa: e a ENTRADA. O script era o `"q"`
            // ("le a primeira linha e sai"), o arquivo da equipe era lido
            // como dados e descartado, e nenhum script era recusado --
            // programa invalido virava `RE` sem diagnostico, em vez de `CE`
            // com a mensagem do parser. Mesma familia do `-o/dev/null` do
            // gawk logo acima e do que o Lote C corrigiu em cinco comandos
            // que "compilavam" executando.
            //
            // `-f {source}` poe o arquivo no lugar de script; `-n` cala a
            // impressao automatica e `< /dev/null` garante zero ciclos. As
            // duas metades, medidas nesta imagem (BusyBox sed):
            //
            //     script `Z`          -> rc=1, "sed: unsupported command Z"
            //     script `s/3 5/8/`   -> rc=0, stdout de comprimento 0
            //
            // O rc=0 COM stdout vazio e o que prova que a etapa analisa sem
            // executar. Ressalva medida: o BusyBox recusa comando e regex
            // invalidos no parse, mas aceita `b` para um rotulo inexistente
            // (rc=0) -- um `CE` de linguagem compilada pegaria mais.
            ['name' => 'Sed', 'extension' => 'sed', 'file_ext' => 'sed', 'compile_command' => 'sed -n -f {source} < /dev/null', 'run_command' => 'sed -f {source}', 'is_active' => true, 'category' => 'interpreted'],

            // Functional Languages
            ['name' => 'Haskell (GHC 9.10)', 'extension' => 'hs', 'file_ext' => 'hs', 'compile_command' => 'ghc -O2 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'OCaml 4.14', 'extension' => 'ml', 'file_ext' => 'ml', 'compile_command' => 'ocamlopt -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],

            // Issue #305 -- o run_command anterior nao podia funcionar para
            // ninguem: `-s main start` manda a BEAM chamar `main:start()`,
            // ou seja um modulo chamado LITERALMENTE `main`. O modulo de
            // Erlang tem de ter o nome do arquivo, e o arquivo aqui e o que
            // a equipe enviou -- `solution.erl` compila para o modulo
            // `solution`, e `main:start()` nao existe.
            //
            // `{classname}` e o basename do arquivo enviado, o mesmo
            // substituto que as entradas de Java usam, e `-pa .` poe o
            // diretorio do run no caminho de codigo para achar o .beam que
            // o `erlc` acabou de gerar. A convencao passa a ser `main/0`,
            // como em quase todo juiz que aceita Erlang.
            //
            // Sem `-noinput`, de proposito: medido, com ele o `io:fread`
            // fica bloqueado para sempre e o julgamento so termina no
            // estouro do tempo.
            //
            // `+JMsingle true` e o irmao do `DOTNET_EnableWriteXorExecute 0`
            // que o AutoJudgeService ja passa, e pela mesma razao. O JIT da
            // BEAM (BeamAsm, OTP 25+) mapeia a memoria executavel DUAS vezes
            // a partir de um memfd que ele estica para 64 MiB, e o
            // `ulimit -f` vale para esse ftruncate. O limite de arquivo da
            // etapa de execucao e `run_max_file_kb`, 32 MiB -- ou seja,
            // metade. Medido nesta imagem, com o a+b abaixo:
            //
            //     ulimit -f 24576 .. 57344  ->  rc=153 (SIGXFSZ), saida vazia
            //     ulimit -f 65536           ->  rc=0, imprime 8
            //     +JMsingle true, -f 1024   ->  rc=0, imprime 8
            //
            // Sem isto a BEAM nao SOBE, e toda submissao de Erlang viraria
            // "Runtime Error (output size limit exceeded)" -- um veredito
            // sobre o tamanho da saida num programa que nunca imprimiu nada.
            // `+JMsingle true` pede um mapeamento unico (rwx) em vez de
            // dois; e a saida documentada para container, e o custo e so o
            // endurecimento W^X do proprio JIT, que aqui ja esta dentro do
            // bwrap.
            //
            // A etapa de COMPILACAO nao precisa da flag e por isso nao a
            // tem: `compile_max_file_kb` e 256 MiB, acima dos 64 MiB que a
            // BEAM estica -- e `erlc` nao aceita `+flags` de qualquer forma.
            // Issue #339 -- DESATIVADAS ate o upstream publicar a correcao.
            //
            // A BEAM nao sobe de forma intermitente no juiz:
            // `sys_signal_stack.c:101:sys_sigaltstack(): Internal error:
            // Failed to set alternate signal stack`. A causa e estrutural e
            // nao e nossa: a ERTS dimensiona a pilha alternativa de sinal com
            // o `SIGSTKSZ` ESTATICO da musl (8192 em x86_64), e em CPU cujo
            // kernel reporta `AT_MINSIGSTKSZ` maior -- AVX-512, AMX -- o
            // `sigaltstack()` devolve ENOMEM e a ERTS aborta. Quem decide e a
            // CPU do host, o que explica a intermitencia sem apelar para
            // carga: um runner passa 20 partidas seguidas e o seguinte
            // derruba quatro testes.
            //
            // Medido: em aarch64 a BEAM subiu 150 vezes sem falha, e o binario
            // dali nem contem as strings do defeito (o arquivo so e compilado
            // em build BEAMASM). No x86_64 do CI ela ja derrubou quatro testes
            // em execucoes distintas -- inclusive bloqueando o PR da correcao
            // de seguranca da #311, que nao tem relacao nenhuma com ela.
            //
            // O que falta NAO e mais a correcao existir: e o Alpine
            // empacota-la. A condicao escrita aqui antes ("basta virar `true`
            // quando houver release com a correcao") ficou VELHA e por isso
            // ficou perigosa -- ela ja esta satisfeita, e reativar hoje
            // devolveria o vermelho intermitente. Reconferido em 20/09/2026,
            // pelo conteudo do arquivo em cada ref e pelo APKINDEX, e nao
            // pela versao anterior deste texto:
            //
            //     erlang/otp, erts/emulator/sys/unix/sys_signal_stack.c
            //       OTP-29.1 (16/09/2026)  sysconf(_SC_MINSIGSTKSZ)  TEM
            //       OTP-28.5.0.6           ss.ss_size = SIGSTKSZ     nao tem
            //       OTP-27.3.4.17          ss.ss_size = SIGSTKSZ     nao tem
            //       maint-28 / maint-27    o backport ainda nao saiu
            //
            //     APKINDEX, x86_64 -- reconferido em 21/09/2026
            //       community, v3.24 e edge:  erlang27 27.3.4.17-r0
            //                                 erlang28 28.5.0.6-r0
            //       edge/testing:             erlang29 29.0.6-r0
            //
            // CUIDADO com o nome: existe um `erlang29`, e ele NAO SERVE.
            // 29.0.6 e anterior a 29.1, que e a primeira release com a
            // correcao. Quem lesse "espere o erlang29 do Alpine" reativaria
            // para dentro do mesmo defeito. Quem decide e o PINO, nunca o
            // nome do pacote.
            //
            // A condicao correta para reativar e "o Alpine publicar um
            // erlang cujo PINO seja >= 29.1" -- um `erlang29` atualizado, ou
            // o backport para o `erlang28` que a equipe do OTP prometeu
            // ("hopefully within a month or so", 07/09/2026) e ainda nao
            // entregou. O criterio esta em
            // tests/Unit/Judge/ReativarABeamExigeOtpCorrigidaTest.php, que o
            // confere por comparacao de versao em vez de por leitura.
            //
            // Desativar e reversivel; deixar ligado nao e. Linguagem
            // intermitente e pior que linguagem ausente: ausente, a equipe nao
            // a escolhe; intermitente, ela submete e recebe veredito de erro
            // que nao e dela, com diagnostico que nao da para contestar. Os
            // comandos ficam prontos; o que guarda a decisao e
            // tests/Unit/Judge/CatalogoBeamDesativadaTest.php, que reprova na
            // suite rapida se estas duas linhas virarem `true` antes de o
            // bloqueio cair -- e diz, na mensagem, o que conferir no Alpine
            // antes de virar. Depois de virar, quem responde se a maquina
            // aguenta e o
            // `test_a_beam_sobe_vinte_vezes_seguidas_dentro_do_sandbox` (#345),
            // que so roda dentro da imagem do juiz.
            ['name' => 'Erlang/OTP 27', 'extension' => 'erl', 'file_ext' => 'erl', 'compile_command' => 'erlc {source}', 'run_command' => 'erl +JMsingle true -noshell -pa . -s {classname} main -s init stop', 'is_active' => false, 'category' => 'compiled'],

            // Issue #305 -- `elixirc` EXECUTA o codigo de nivel superior.
            // Medido: a "compilacao" do a+b morreu em
            // `:binary.split(:eof, ...)`, porque rodou o programa sem
            // entrada. Etapa de compilacao que executa o programa consome a
            // entrada e produz saida -- o defeito que a #269 achou no
            // console do Portugol Studio.
            //
            // `Code.string_to_quoted!/1` faz a analise sintatica e devolve a
            // arvore, sem avaliar nada.
            //
            // `--erl '+JMsingle true'` e a mesma flag da entrada de Erlang
            // acima (o Elixir e a mesma BEAM), repassada pelo `--erl`. Ver
            // la a medicao: sem ela a maquina virtual nao sobe sob o
            // `ulimit -f` da etapa de execucao. Medido aqui tambem, com o
            // a+b: `ulimit -f 1024` sem a flag -> rc=153 e saida vazia; com
            // a flag -> imprime 8.
            ['name' => 'Elixir 1.19', 'extension' => 'ex', 'file_ext' => 'ex', 'compile_command' => 'elixir -e \'Code.string_to_quoted!(File.read!("{source}"))\'', 'run_command' => 'elixir --erl \'+JMsingle true\' {source}', 'is_active' => false, 'category' => 'interpreted'],
            // Issue #305, Lote D -- Julia fica INATIVA, e o motivo e uma
            // medicao e nao uma suspeita.
            //
            // O indice oficial de binarios do projeto
            // (https://julialang-s3.julialang.org/bin/versions.json) lista,
            // para a ultima estavel, so `aarch64-linux-gnu` em ARM. Varrendo
            // TODAS as versoes do indice, o unico triplet musl que existe e
            // `x86_64-linux-musl`: nao ha, e nunca houve, binario
            // musl/aarch64. Sobraria compilar o Julia do fonte, que arrasta
            // LLVM -- fora de qualquer orcamento de imagem deste projeto.
            //
            // Mesma regra do PyPy acima: inativo quer dizer toolchain
            // ausente, e e mais honesto do que oferecer a linguagem e falhar
            // toda submissao.
            ['name' => 'Julia', 'extension' => 'jl', 'file_ext' => 'jl', 'compile_command' => 'julia --compile=min {source} 2>&1', 'run_command' => 'julia {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'R 4.6', 'extension' => 'r', 'file_ext' => 'r', 'compile_command' => 'Rscript --vanilla -e "parse(\'{source}\')"', 'run_command' => 'Rscript --vanilla {source}', 'is_active' => true, 'category' => 'interpreted'],

            // Lisp Family
            // Issue #305 -- `--load` CARREGA, ou seja executa. Medido: a
            // "compilacao" do a+b morreu com END-OF-FILE lendo a entrada
            // padrao -- prova direta de que a etapa de compilacao estava
            // consumindo a entrada do problema.
            //
            // `compile-file` compila sem avaliar o corpo. E o `when f` nao e
            // enfeite: medido, `compile-file` sai com codigo 0 mesmo depois
            // de "caught ERROR: READ error during COMPILE-FILE". Sem olhar o
            // terceiro valor de retorno (failure-p), um programa que nao
            // compila passaria da compilacao e viraria WA.
            ['name' => 'Common Lisp (SBCL 2.6)', 'extension' => 'lisp_sbcl', 'file_ext' => 'lisp', 'compile_command' => 'sbcl --noinform --non-interactive --eval \'(multiple-value-bind (o w f) (compile-file "{source}") (declare (ignore o w)) (when f (sb-ext:exit :code 1)))\'', 'run_command' => 'sbcl --script {source}', 'is_active' => true, 'category' => 'interpreted'],
            ['name' => 'Common Lisp (CLISP 2.49)', 'extension' => 'lisp_clisp', 'file_ext' => 'lisp', 'compile_command' => 'clisp -c {source}', 'run_command' => 'clisp {source}', 'is_active' => true, 'category' => 'interpreted'],
            ['name' => 'Scheme (Guile 3.0)', 'extension' => 'scm', 'file_ext' => 'scm', 'compile_command' => 'guild compile {source}', 'run_command' => 'guile {source}', 'is_active' => true, 'category' => 'interpreted'],
            // Issue #305 -- `raco make` nao existe nesta imagem: mora em
            // `compiler-lib`, que o Alpine nao empacota. Medido:
            // "/usr/bin/raco: Unrecognized command: make". O invocador
            // `racket-check` (docker/judge/bin/) expande o modulo, que pega
            // erro de sintaxe e identificador nao ligado sem rodar o corpo.
            ['name' => 'Racket 9.2', 'extension' => 'rkt', 'file_ext' => 'rkt', 'compile_command' => 'racket-check {source}', 'run_command' => 'racket {source}', 'is_active' => true, 'category' => 'interpreted'],

            // Pascal/Delphi
            ['name' => 'Pascal (FPC)', 'extension' => 'pas_fpc', 'file_ext' => 'pas', 'compile_command' => 'fpc -O2 -o{output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'Pascal (GPC)', 'extension' => 'pas_gpc', 'file_ext' => 'pas', 'compile_command' => 'gpc -O2 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],

            // Fortran
            // Issue #305 -- as duas entradas sao o MESMO gfortran, e o que
            // muda e o dialeto (`-std=legacy` aceita o formato fixo de
            // coluna do F77). O nome passa a dizer a versao do compilador ao
            // lado do padrao da linguagem: "77" e o padrao, "15" e o
            // gfortran -- e e o segundo que o ToolchainVersionsMatchCatalog
            // confere.
            ['name' => 'Fortran (GFortran 15)', 'extension' => 'f90', 'file_ext' => 'f90', 'compile_command' => 'gfortran -O2 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'Fortran 77 (GFortran 15)', 'extension' => 'f77', 'file_ext' => 'f', 'compile_command' => 'gfortran -std=legacy -O2 -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],

            // Assembly -- issue #305: o `nasm` do Alpine INSTALA nesta
            // maquina e mesmo assim as duas entradas ficam inativas, e isso
            // e o exemplo mais limpo de "binario presente nao e
            // capacidade" (#302).
            //
            // NASM e um montador de x86. Numa maquina aarch64 ele monta e o
            // `ld` recusa, medido:
            //
            //   ld: unknown architecture of input file `solution.o' is
            //       incompatible with aarch64 output
            //
            // E mesmo que ligasse, o binario x86 nao executaria aqui. Como o
            // catalogo e um so para todas as maquinas de julgamento, liga-lo
            // ofereceria a linguagem tambem onde ela nao pode rodar. Fica
            // para quando o catalogo souber falar de arquitetura.
            ['name' => 'Assembly x64 (NASM)', 'extension' => 'asm64', 'file_ext' => 'asm', 'compile_command' => 'nasm -f elf64 {source} -o {output}.o && ld {output}.o -o {output}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Assembly x86 (NASM)', 'extension' => 'asm32', 'file_ext' => 'asm', 'compile_command' => 'nasm -f elf32 {source} -o {output}.o && ld -m elf_i386 {output}.o -o {output}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],

            // Apple/Mobile
            // Issue #305, Lote D -- Swift fica INATIVA.
            //
            // O indice oficial (https://www.swift.org/api/v1/install/
            // releases.json) publica, para a 6.4.0, toolchain de Linux
            // apenas para distribuicoes glibc -- Ubuntu 22.04/24.04/26.04,
            // Debian 12/13, Fedora 41, Amazon Linux 2023, RHEL UBI 9/10.
            // Nenhuma imagem musl.
            //
            // O artefato musl que existe chama-se "Static SDK" e NAO serve
            // aqui: e um SDK de compilacao CRUZADA, que roda sobre uma
            // toolchain Swift glibc e produz binarios musl. O juiz precisa
            // do `swiftc` DENTRO da imagem, em tempo de submissao -- um SDK
            // cruzado nao poe compilador nenhum no Alpine.
            ['name' => 'Swift', 'extension' => 'swift', 'file_ext' => 'swift', 'compile_command' => 'swiftc -O -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Objective-C', 'extension' => 'objc', 'file_ext' => 'm', 'compile_command' => 'clang -framework Foundation -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],

            // Other Modern Languages
            // Issue #305, Lote D -- Dart, contra a expectativa.
            //
            // O SDK oficial e glibc, e a aposta era que ele repetisse o
            // `scratch-run`: binario ELF glibc que em musl responde `not
            // found`. Ele responde -- medido, rc=127 -- e ate aqui a
            // historia e a mesma. O que muda e o desfecho: com `gcompat`
            // instalado o `dart` roda. Foi medido o ciclo inteiro, nao so o
            // `--version`: `dart compile exe` produz um executavel nativo,
            // ele le stdin e imprime a soma, e um programa com erro de
            // sintaxe sai com codigo != 0 (ou seja, da CE e nao WA).
            //
            // Por que o `gcompat` salva o Dart e nao salvou o scratch-run:
            // la o binario era um empacotamento `pkg` de Node que morria em
            // `Error relocating: fcntl64`; o `dart` nao chama os simbolos
            // que faltam no shim.
            ['name' => 'Dart 3.13', 'extension' => 'dart', 'file_ext' => 'dart', 'compile_command' => 'dart compile exe {source} -o {output}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'Crystal 1.20', 'extension' => 'cr', 'file_ext' => 'cr', 'compile_command' => 'crystal build {source} -o {output}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'V', 'extension' => 'vlang', 'file_ext' => 'v', 'compile_command' => 'v -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],

            // Logic/Prolog
            // Issue #305, Lote D -- SWI-Prolog, construido do fonte.
            //
            // `--on-error=status` NAO e enfeite, e o que faz o erro de
            // sintaxe virar CE. Medido com o comando que estava escrito
            // aqui (`swipl -g "halt" -l {source}`): um arquivo que nao e
            // Prolog imprime `Syntax error` em stderr e SAI COM 0. Como o
            // CE vem do codigo de saida da compilacao, o programa passaria
            // da compilacao e viraria WA -- o mesmo defeito que a #269
            // corrigiu no Portugol Studio. Com a opcao, o invalido sai 1 e
            // o valido segue saindo 0.
            //
            // `--on-warning` fica de fora de proposito: em Prolog, aviso de
            // variavel singleton e rotina, e trata-lo como erro daria CE em
            // programa correto.
            ['name' => 'Prolog (SWI-Prolog 10)', 'extension' => 'prolog_swi', 'file_ext' => 'pl', 'compile_command' => 'swipl --on-error=status -g "halt" -l {source}', 'run_command' => 'swipl --on-error=status -g "main,halt" -l {source}', 'is_active' => true, 'category' => 'interpreted'],
            // Issue #305, Lote D -- GNU Prolog, e o `--no-top-level` que o
            // comando nao tinha.
            //
            // Medido com o comando anterior (`gplc {source}`): o binario
            // gerado roda o `main`, imprime a resposta CERTA e em seguida
            // despeja na SAIDA PADRAO o cabecalho do interpretador
            // interativo --
            //
            //     8
            //     GNU Prolog 1.5.0 (64 bits)
            //     Compiled ... with gcc
            //     Copyright (C) 1999-2026 Daniel Diaz
            //     | ?-
            //
            // -- e isso e o que ia para o comparador. Toda submissao correta
            // receberia WA, com a resposta certa na primeira linha. Com
            // `--no-top-level` a saida e exatamente `8\n` e o processo sai
            // com 0: medido com `od -c`.
            //
            // Issue #347 -- o `envolucro.pro` no fim da linha de compilacao
            // e o que faz erro de execucao virar RE em vez de WA. Medido:
            // uma divisao por zero dentro do goal de `:- initialization(main).`
            // NAO derruba o binario (o gprolog reclama e sai com 0) e ainda
            // despeja `system_error(cannot_catch_throw(...))` na SAIDA
            // PADRAO, que e o arquivo comparado. O envolucro chama `main`
            // por `catch/3`, manda o diagnostico para `user_error` e encerra
            // com `halt(1)`.
            //
            // A POSICAO e obrigatoria: o GNU Prolog executa as diretivas
            // `initialization/1` na ordem INVERSA da ligacao, entao o
            // envolucro so roda ANTES do `main` da equipe se vier POR
            // ULTIMO aqui. Invertido, medido, o `main` roda duas vezes.
            // O resto esta no proprio arquivo.
            ['name' => 'Prolog (GNU Prolog 1.5)', 'extension' => 'prolog_gnu', 'file_ext' => 'pro', 'compile_command' => 'gplc --no-top-level -o {output} {source} {judge_runtime}/gprolog/envolucro.pro', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],

            // Database/Query -- issue #305: o `sqlite3` cabe na imagem por
            // menos de 1 MiB e mesmo assim esta entrada fica inativa, por
            // duas medicoes.
            //
            // 1. O `run_command` abaixo nao pode funcionar como esta: o juiz
            //    acrescenta ` < {arquivo de entrada}` ao comando, e um
            //    segundo redirecionamento de entrada vence o primeiro. O
            //    sqlite receberia a ENTRADA DO PROBLEMA como programa e
            //    nunca leria o `.sql` enviado.
            // 2. Pior, nao existe etapa de compilacao honesta: o sqlite nao
            //    tem modo "so analise". `sqlite3 :memory: ".read {source}"`
            //    EXECUTA o script -- medido, sai 0 tendo rodado tudo. Uma
            //    compilacao que executa o programa consome a entrada e
            //    produz saida, que e o defeito que a #269 achou no console
            //    do Portugol Studio.
            //
            // Ha caminho (`-init {source}` com `.import /dev/stdin`, medido
            // imprimindo 8), mas ele exige decidir COMO um problema entrega
            // dados a uma submissao SQL -- decisao de formato de problema,
            // nao de toolchain. Fica para uma issue propria.
            ['name' => 'SQL (SQLite)', 'extension' => 'sql', 'file_ext' => 'sql', 'compile_command' => 'sqlite3 :memory: ".read {source}" 2>&1', 'run_command' => 'sqlite3 :memory: < {source}', 'is_active' => false, 'category' => 'interpreted'],

            // Esoteric Languages
            ['name' => 'Brainfuck', 'extension' => 'bf', 'file_ext' => 'bf', 'compile_command' => 'bf -c {source}', 'run_command' => 'bf {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Whitespace', 'extension' => 'ws', 'file_ext' => 'ws', 'compile_command' => 'wspace {source} 2>&1', 'run_command' => 'wspace {source}', 'is_active' => false, 'category' => 'interpreted'],

            // Scientific
            ['name' => 'Octave', 'extension' => 'octave', 'file_ext' => 'oct', 'compile_command' => 'octave --no-gui --silent --eval "source(\'{source}\')"', 'run_command' => 'octave --no-gui --silent {source}', 'is_active' => false, 'category' => 'interpreted'],

            // Text Processing
            ['name' => 'CoffeeScript', 'extension' => 'coffee', 'file_ext' => 'coffee', 'compile_command' => 'coffee -c {source}', 'run_command' => 'coffee {source}', 'is_active' => false, 'category' => 'interpreted'],

            // Other
            // Issue #305 -- Ada exige que o nome do arquivo case com o nome
            // da unidade: `solution.adb` tem de conter `procedure
            // Solution`. Nao e coisa do juiz, e da linguagem; esta anotado
            // no manual do organizador ao lado da regra equivalente de Java.
            ['name' => 'Ada (GNAT 15)', 'extension' => 'adb', 'file_ext' => 'adb', 'compile_command' => 'gnatmake -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            // Issue #305, Lote D -- COBOL, e a suposicao que nao se
            // confirmou.
            //
            // Esta entrada entrou no lote das linguagens "que nao existem no
            // Alpine e precisam vir de fora". Nao e o caso: `apk search -x
            // gnucobol` devolve `gnucobol-3.2-r0`. A entrada estava inativa
            // por uma crenca sobre o repositorio, nao por uma medicao dele
            // -- e sair de fora custou um `apk add`.
            ['name' => 'COBOL (GnuCOBOL 3.2)', 'extension' => 'cob', 'file_ext' => 'cob', 'compile_command' => 'cobc -x -o {output} {source}', 'run_command' => './{executable}', 'is_active' => true, 'category' => 'compiled'],
            ['name' => 'Icon', 'extension' => 'icn', 'file_ext' => 'icn', 'compile_command' => 'icont -o {output} {source}', 'run_command' => './{executable}', 'is_active' => false, 'category' => 'compiled'],
            ['name' => 'Pike', 'extension' => 'pike', 'file_ext' => 'pike', 'compile_command' => 'pike -e "compile_file(\"{source}\")"', 'run_command' => 'pike {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'Smalltalk (GST)', 'extension' => 'st', 'file_ext' => 'st', 'compile_command' => 'gst --quiet {source} 2>&1', 'run_command' => 'gst {source}', 'is_active' => false, 'category' => 'interpreted'],
            // Issue #305 -- o `compile_command` anterior era o proprio
            // interpretador: `tclsh {source}` EXECUTA o programa. Medido, a
            // "compilacao" do a+b falhou com "can't use empty string as
            // operand of +", porque rodou sem entrada -- toda solucao
            // correta viraria erro de compilacao.
            //
            // Tcl nao tem compilador para oferecer; `tcl-check`
            // (docker/judge/bin/) usa `info complete`, que responde se o
            // script fecha chaves, colchetes e aspas. E o mesmo nivel de
            // garantia do `bash -n` que a entrada `sh` ja usa, e o nome sem
            // numero de versao e deliberado: nada aqui promete uma versao.
            ['name' => 'Tcl', 'extension' => 'tcl', 'file_ext' => 'tcl', 'compile_command' => 'tcl-check {source}', 'run_command' => 'tclsh {source}', 'is_active' => true, 'category' => 'interpreted'],
            ['name' => 'Forth (GForth)', 'extension' => 'forth', 'file_ext' => 'fs', 'compile_command' => 'gforth {source} -e bye', 'run_command' => 'gforth {source}', 'is_active' => false, 'category' => 'interpreted'],
            ['name' => 'BC', 'extension' => 'bc', 'file_ext' => 'bc', 'compile_command' => 'bc -l {source} < /dev/null', 'run_command' => 'bc -l {source}', 'is_active' => false, 'category' => 'interpreted'],
        ];
    }

    /**
     * Get all available languages for selection
     */
    public static function getAllLanguages(): array
    {
        return collect(self::getDefaultLanguages())->map(function ($lang, $index) {
            return array_merge($lang, ['id' => $index + 1]);
        })->all();
    }

    /**
     * Get the actual file extension for this language
     */
    public function getFileExtension(): string
    {
        $defaults = collect(self::getDefaultLanguages())->keyBy('extension');

        return $defaults[$this->extension]['file_ext'] ?? $this->extension;
    }
}
