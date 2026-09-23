<?php

namespace App\Services\Judgehost;

use App\Models\Language;
use App\Support\Judge\PerfilDaImagem;
use App\Support\Judge\ToolchainManifest;
use App\Support\Judge\ToolchainVersions;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Issue #117 -- what this machine can actually run, found out rather than
 * declared.
 *
 * A configured list of languages is a list someone has to remember to
 * update, and the day it is wrong a host repeatedly takes work it cannot
 * do and hands it straight back (#125). So this probes: for each language
 * the server knows about, it takes the executable each command actually
 * starts with and asks the shell whether it exists.
 *
 * Deliberately only the binary. Running a real compile of a sample program
 * per language on every restart would be a better test and a much slower
 * start, and the failure it would catch beyond this one -- a toolchain
 * installed but broken -- is already handled by giving the run back.
 */
class MachineCapabilities
{
    /**
     * Issue #305 -- o PATH que a sonda consulta, injetado em vez de global.
     *
     * Em producao fica `null` e nada muda: o subprocesso herda o ambiente,
     * que e o PATH da maquina de verdade. Quem passa um valor aqui e teste
     * que precisa montar uma maquina hipotetica ("um host com o SDK 8 e sem
     * o 10").
     *
     * Existe porque a alternativa obvia -- `putenv('PATH=...')` em volta da
     * chamada -- NAO funciona, e falha do jeito pior: em silencio e so em
     * algumas maquinas. O `Process` resolve o ambiente do filho com
     * `$_ENV + getenv()` (symfony/process, `getDefaultEnv()`), e em `+` o
     * operando da ESQUERDA vence. Entao:
     *
     *   variables_order sem `E` -> $_ENV vazio  -> o putenv vale
     *   variables_order com `E` -> $_ENV['PATH'] existe -> o putenv e IGNORADO
     *
     * A imagem do juiz e `php:8.3-cli-alpine`, que nao ativa nenhum php.ini,
     * entao ela cai no padrao embutido do PHP (`EGPCS`) -- com o `E`. Um
     * teste escrito com `putenv` passava na maquina de desenvolvimento
     * (php.ini com `GPCS`) e reprovava dentro da imagem, que e a forma
     * invertida do defeito recorrente deste repositorio: verde onde o
     * toolchain NAO esta, vermelho onde ele esta.
     */
    public function __construct(private readonly ?string $searchPath = null) {}

    /**
     * Issue #303 -- quanto tempo se espera um toolchain dizer a propria
     * versao antes de desistir dele.
     */
    private const VERSION_PROBE_SECONDS = 20;

    /**
     * Versao ja lida, por comando. Nome proprio, e nao `$probed`: um teste
     * deste arquivo estende a classe com um `public array $probed`.
     *
     * @var array<string, string|null>
     */
    private array $versoesLidas = [];

    /**
     * The extensions this machine can run, out of the ones given.
     *
     * Takes models, plain objects, or the decoded arrays the API returns.
     * That last one is not hypothetical: this shipped reading properties
     * off values that were arrays, so every language was skipped and the
     * agent declared nothing -- silently, because declaring nothing is a
     * valid answer meaning "can judge anything". It degraded safely and did
     * nothing, which is the worst way for a feature to fail.
     *
     * @param  iterable<Language|object|array<string, mixed>>  $languages
     * @return list<string>
     */
    public function detect(iterable $languages): array
    {
        $supported = [];

        foreach ($languages as $language) {
            $extension = (string) ($this->field($language, 'extension') ?? '');

            if ($extension === '' || in_array($extension, $supported, true)) {
                continue;
            }

            // Issue #306, passo 3 -- numa imagem enxuta a sonda sozinha
            // mente: os invocadores de docker/judge/bin e os estagios
            // compilados estao em todo perfil, entao o binario de uma
            // linguagem que o perfil nao instalou pode existir (o
            // `scratch-run` existe numa imagem sem Node). Declarar isso seria
            // reivindicar trabalho que a maquina nao sabe fazer -- a #125 de
            // novo. A imagem declara o que PROMETE e a sonda ENCONTRA. Numa
            // imagem `completo` (o padrao) isto nao filtra nada.
            if (! PerfilDaImagem::permite($extension)) {
                continue;
            }

            $binaries = array_filter([
                $this->executableOf((string) ($this->field($language, 'compile_command') ?? '')),
                $this->executableOf((string) ($this->field($language, 'run_command') ?? '')),
            ]);

            // A language with neither command is not a language this can
            // decide about; leave it to the give-back path rather than
            // claiming an ability that was never checked.
            if ($binaries === []) {
                continue;
            }

            if (collect($binaries)->every(fn (string $binary) => $this->exists($binary))) {
                $supported[] = $extension;
            }
        }

        return $supported;
    }

    /**
     * Issue #303 -- a versao de cada toolchain que esta maquina tem.
     *
     * A spec do julgamento distribuido promete que a capacidade declarada
     * "inclui versao de linguagem"; ate aqui ela nao incluia, e dois hosts
     * de um mesmo parque -- um com GCC 13, outro com GCC 15 -- declaravam
     * exatamente a mesma capacidade. Numa maratona a versao do compilador e
     * parte do edital, e um rejulgamento noutra maquina podia dar outro
     * veredito sem que nada acusasse.
     *
     * Tres decisoes que valem mais que o codigo:
     *
     * 1. **Sonda, e nao pino do Dockerfile.** Ler `DockerfileToolchain` diria
     *    o que o NOSSO repositorio manda instalar; o host que importa e o da
     *    instituicao parceira, que construiu a imagem dela. Ver
     *    {@see ToolchainVersions}.
     *
     * 2. **So para extensoes ja declaradas.** Recebe o resultado de
     *    `detect()`: sondar versao de toolchain ausente e pagar um
     *    subprocesso para ouvir "not found".
     *
     * 3. **Nunca reduz o que foi declarado.** Versao que nao se conseguiu
     *    ler simplesmente nao aparece no mapa, e a extensao continua
     *    declarada do mesmo jeito. A #354 mostrou o custo de uma lista
     *    PARCIAL de capacidades -- pior que vazia, porque parece correta --
     *    e por isso a versao e informacao anexa, nunca um segundo portao.
     *
     * O custo e real e por isso e memoizado por processo: `kotlinc -version`
     * e `scalac -version` sobem uma JVM, e o agente re-registra a cada falha
     * de transporte. Os toolchains de uma maquina nao mudam enquanto o
     * processo vive -- e exatamente o contrato que `detect()` ja documenta
     * ("corrects itself by restarting its agent") -- entao a segunda
     * pergunta e respondida do cache.
     *
     * @param  list<string>  $extensions
     * @return array<string, string>
     */
    public function versionsOf(array $extensions): array
    {
        $versions = [];

        foreach ($extensions as $extension) {
            $command = ToolchainVersions::commandFor($extension);

            if ($command === null) {
                continue;
            }

            $version = $this->probeVersion($command);

            if ($version !== null) {
                $versions[$extension] = $version;
            }
        }

        return $versions;
    }

    /**
     * O texto que um comando de identificacao imprime, lido uma vez por
     * processo e por comando.
     *
     * Um timeout curto de proposito: a sonda roda no register, e o register
     * e o que devolve os runs que esta maquina estava segurando. Um toolchain
     * que trava a responder `--version` nao pode atrasar isso -- ele perde a
     * versao, nao a vaga.
     */
    private function probeVersion(string $command): ?string
    {
        if (array_key_exists($command, $this->versoesLidas)) {
            return $this->versoesLidas[$command];
        }

        $pendente = Process::timeout(self::VERSION_PROBE_SECONDS);

        // Mesmo motivo do `exists()`: PATH injetado vai EXPLICITO para o
        // processo filho, porque `putenv` nao vale nas duas
        // `variables_order` -- ver o comentario do construtor.
        if ($this->searchPath !== null) {
            $pendente = $pendente->env(['PATH' => $this->searchPath]);
        }

        try {
            $saida = trim($pendente->run(['sh', '-c', $command.' 2>&1'])->output());
        } catch (Throwable $e) {
            $saida = '';
        }

        return $this->versoesLidas[$command] = ToolchainVersions::extract($saida);
    }

    /**
     * One field, however the caller happens to be carrying it.
     */
    private function field(mixed $language, string $key): mixed
    {
        if (is_array($language)) {
            return $language[$key] ?? null;
        }

        return is_object($language) ? ($language->{$key} ?? null) : null;
    }

    public function cpuCount(): ?int
    {
        $count = (int) trim((string) @shell_exec('nproc 2>/dev/null'));

        return $count > 0 ? $count : null;
    }

    public function memoryMb(): ?int
    {
        $kb = (int) trim((string) @shell_exec("awk '/MemTotal/ {print \$2}' /proc/meminfo 2>/dev/null"));

        return $kb > 0 ? intdiv($kb, 1024) : null;
    }

    /**
     * The program a command line actually starts, or null when it starts no
     * installed program at all.
     *
     * Issue #354 -- this used to skip only tokens that BEGIN with `{`, and
     * the run command of every compiled language is literally
     * `./{executable}`, which begins with a dot. So the token handed to the
     * probe was the template itself, `command -v './{executable}'` failed on
     * any machine alive, and since a language needs every one of its
     * binaries to exist, all 22 compiled languages were dropped from the
     * declared list. A remote judgehost then refused exactly C, C++, Rust
     * and Go -- the ones it was most certainly able to compile.
     *
     * The rule now has a single owner, {@see ToolchainManifest::executableOf()},
     * which already got this right for the install manifest: a token
     * containing `{` anywhere is a mould, not a program, and a command that
     * starts with one answers "no installed program" rather than moving on
     * to the next token (the next token is a `<` or another placeholder, not
     * a better guess). Two copies of this rule is how they came to disagree.
     */
    private function executableOf(string $command): ?string
    {
        return ToolchainManifest::executableOf($command);
    }

    /**
     * Protected, not private, so a test can ask what this probes for without
     * needing the toolchain installed -- the question the catalogue-wide
     * regression net asks is WHICH binary each language sends it looking
     * for, which is the part that was wrong.
     */
    protected function exists(string $binary): bool
    {
        // An absolute path is checked directly; anything else is resolved
        // the way the judge itself would resolve it, through PATH.
        if (str_starts_with($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }

        $comando = ['sh', '-c', 'command -v '.escapeshellarg($binary)];

        // Sem PATH injetado o filho herda o ambiente, que e o comportamento
        // de producao. Com PATH injetado ele vai EXPLICITO para o processo,
        // que e o unico jeito que vale nas duas `variables_order` -- ver o
        // comentario do construtor.
        if ($this->searchPath !== null) {
            return Process::env(['PATH' => $this->searchPath])->run($comando)->successful();
        }

        return Process::run($comando)->successful();
    }
}
