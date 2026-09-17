<?php

namespace App\Console\Commands;

use App\Exceptions\SandboxUnavailableException;
use App\Services\AutoJudgeService;
use App\Services\CgroupMemoryLimiter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Issue #194 -- o que a organizacao roda NA MAQUINA DE JULGAMENTO, antes da
 * prova, para saber se aquela caixa confina de verdade.
 *
 * Nao e a mesma pergunta que a suite responde. JudgeSandboxConfinementTest e
 * JudgeSandboxIsolationTest provam que o codigo monta o bwrap certo. O que
 * faltava era provar que AQUELE kernel, com AQUELA configuracao de cgroup,
 * NAQUELE host emprestado, aguenta codigo hostil -- e a premissa do #53 e
 * justamente que a maquina fica no rack de outra instituicao, configurada
 * por outra pessoa, com um kernel que ninguem daqui escolheu.
 *
 * O `mojtools` trata isso como pre-requisito operacional e nao como teste
 * de CI, com uma instrucao categorica: se qualquer caso falhar, nao use
 * aquela maquina em prova. E a mesma regra aqui, e por isso o comando sai
 * com codigo diferente de zero se um unico caso falhar.
 *
 * Cada caso passa pelo MESMO wrapWithBwrap() e pelo MESMO
 * CgroupMemoryLimiter::confine() que julgam submissao de verdade. Montar um
 * sandbox proprio para o autoteste seria provar coisa diferente da que
 * acontece na prova, que e exatamente o modo de falha que este comando
 * existe para fechar.
 */
class JudgehostSelfTestCommand extends Command
{
    protected $signature = 'judgehost:selftest {--json : Saida em JSON, para um checklist automatizado}';

    protected $description = 'Prova, nesta maquina, que o sandbox de julgamento contem codigo hostil';

    /**
     * Deliberadamente apertados, e nao os valores de producao.
     *
     * Um teste de memoria contra o limite real (512 MB) leva segundos e
     * pressiona o host; contra 64 MB, o mesmo kernel decide a mesma coisa em
     * uma fracao do tempo. O que se prova e que o mecanismo BATE, nao qual
     * numero foi escolhido.
     */
    private const MEMORY_MB = 64;

    private const CPU_SECONDS = 2;

    private const WALL_SECONDS = 8;

    private const MAX_PROCESSES = 64;

    private const FILE_SIZE_KB = 2048;

    private string $workspace;

    public function handle(AutoJudgeService $judge, CgroupMemoryLimiter $cgroup): int
    {
        // Com --json, NADA alem do JSON sai daqui.
        //
        // O cabecalho saia antes desta linha, e isso quebrava o modo que
        // existe justamente para ser lido por outra coisa: a saida era um
        // titulo, uma linha em branco e so entao o documento, e json_decode
        // -- ou `jq`, ou qualquer consumidor -- devolvia null sobre o
        // conjunto. Um formato de maquina com uma saudacao em cima nao e um
        // formato de maquina. O job Judging do CI foi quem mostrou.
        if (! $this->option('json')) {
            $this->line('Autoteste do sandbox de julgamento -- '.gethostname());
            $this->newLine();
        }

        // A informacao mais importante que este comando pode dar, e por isso
        // vem antes de qualquer caso: uma instalacao com o bwrap desligado
        // julga codigo submetido sem confinamento nenhum e nao avisa
        // ninguem. A variavel existe para a suite rodar em maquina de
        // desenvolvedor; numa maquina que julga, ela e a falha.
        //
        // A recusa tambem sai como JSON quando foi pedido JSON: um script
        // que recebesse texto solto aqui nao conseguiria distinguir "esta
        // maquina nao confina" de "o comando quebrou", e as duas coisas
        // pedem reacoes diferentes.
        if (! config('autojudge.use_bwrap', true)) {
            return $this->report([$this->result(
                'sandbox_enabled',
                'O sandbox esta ligado',
                'AUTOJUDGE_USE_BWRAP ligado',
                false,
                // Curto porque tem que caber numa linha de terminal. A
                // primeira versao explicava, na mesma frase, que a maquina
                // executa codigo submetido sem confinamento -- e o texto
                // passava de oitenta colunas, entao a frase acionavel
                // ("nao use em prova") quebrava no meio. Um aviso que so
                // cabe na tela do autor nao e um aviso.
                'AUTOJUDGE_USE_BWRAP esta DESLIGADO. Nao use em prova.'
            )]);
        }

        // E logo depois: o binario esta la?
        //
        // O caso de cima cobre "alguem desligou o sandbox". Este cobre
        // "ninguem desligou nada, a maquina so nao tem bubblewrap" -- que e
        // o caso COMUM quando uma instituicao parceira empresta o que tem
        // (#53), e e a maquina para a qual este comando foi escrito.
        //
        // Sem ele a primeira chamada a wrapWithBwrap() lanca
        // SandboxUnavailableException e o comando morre com um stack trace.
        // Em --json isso e pior que feio: a saida deixa de ser JSON, e vale
        // aqui a mesma frase escrita no caso de cima -- um consumidor nao
        // consegue distinguir "esta maquina nao confina" de "o comando
        // quebrou". Medido num laptop sem bwrap: os dois caminhos saiam com
        // exit 1 e so o de cima era parseavel.
        $bwrap = (string) config('autojudge.bwrap_path', '/usr/bin/bwrap');

        if (! file_exists($bwrap) || ! is_executable($bwrap)) {
            return $this->report([$this->result(
                'sandbox_binary',
                'O binario do sandbox existe',
                $bwrap.' executavel',
                false,
                'bwrap ausente ou nao executavel. Instale o bubblewrap. Nao use em prova.'
            )]);
        }

        $this->workspace = sys_get_temp_dir().'/mh-selftest-'.getmypid();

        if (! @mkdir($this->workspace, 0o700, true) && ! is_dir($this->workspace)) {
            return $this->report([$this->result(
                'workspace',
                'Diretorio de trabalho',
                'criavel',
                false,
                "Nao foi possivel criar o diretorio de trabalho em {$this->workspace}."
            )]);
        }

        try {
            $results = $this->runCases($judge, $cgroup);
        } catch (SandboxUnavailableException $e) {
            // A checagem acima pega o caso conhecido. Este pega o resto: o
            // servico recusa executar sem confinamento em mais de um ponto,
            // e qualquer recusa que escape daqui vira stack trace de novo --
            // o defeito, nao o sintoma, e o comando falar duas linguas.
            $results = [$this->result(
                'sandbox_binary',
                'O binario do sandbox existe',
                'sandbox utilizavel',
                false,
                $this->firstLine($e->getMessage())
            )];
        } finally {
            $this->removeWorkspace();
        }

        return $this->report($results);
    }

    /**
     * @return list<array{key: string, title: string, expected: string, passed: bool, detail: string}>
     */
    private function runCases(AutoJudgeService $judge, CgroupMemoryLimiter $cgroup): array
    {
        $results = [];

        // Antes de tudo: o sandbox sobe? Um bwrap instalado e um bwrap que
        // consegue criar namespace de usuario sao coisas diferentes -- num
        // container sem privilegio, ou num kernel com
        // apparmor_restrict_unprivileged_userns, o segundo nao acontece. Sem
        // esta porta, todos os casos seguintes falhariam pelo mesmo motivo e
        // nenhuma das mensagens diria qual.
        $boot = $this->inSandbox($judge, 'echo vivo');

        if (trim($boot['stdout']) !== 'vivo') {
            $results[] = $this->result(
                'sandbox_boots',
                'O sandbox sobe',
                'bwrap cria o namespace e executa um comando',
                false,
                'O sandbox nao executou nem um echo. '.$this->firstLine($boot['stderr'] ?: 'sem saida de erro')
            );

            return $results;
        }

        $results[] = $this->result('sandbox_boots', 'O sandbox sobe', 'bwrap cria o namespace e executa um comando', true, 'ok');
        $results[] = $this->forkBomb($judge);
        $results[] = $this->unboundedAllocation($judge, $cgroup);
        $results[] = $this->fillDisk($judge);
        $results[] = $this->network($judge);
        $results[] = $this->secrets($judge);
        $results[] = $this->cpuTime($judge);
        $results[] = $this->wallTime($judge);

        return $results;
    }

    /**
     * Fork bomb, contida pelo `ulimit -u`.
     *
     * Limitada de proposito, e nao a `:(){ :|:& };:` classica. Uma bomba sem
     * teto num host onde o limite NAO pega e um host derrubado -- e derrubar
     * a maquina e a unica coisa que este comando nao pode fazer, porque ele
     * roda antes da prova e nao durante. Tentar tres vezes o limite prova o
     * mesmo: ou o kernel recusa os forks, ou nao recusa.
     *
     * Duas evidencias, porque uma so mente. O `ulimit -u` lido de dentro
     * mostra que o prologo chegou ate la; a mensagem de fork recusado mostra
     * que o numero e obedecido. Um sandbox onde o limite aparece e nao e
     * cumprido passaria pelo primeiro sozinho.
     */
    private function forkBomb(AutoJudgeService $judge): array
    {
        $attempts = self::MAX_PROCESSES * 3;
        $script = 'echo "LIMITE=$(ulimit -u)"; '
            ."i=0; while [ \$i -lt {$attempts} ]; do sleep 5 & i=\$((i+1)); done; "
            .'echo FIM';

        $out = $this->inSandbox($judge, $script, ['max_processes' => self::MAX_PROCESSES]);
        $combined = $out['stdout']."\n".$out['stderr'];

        $limitApplied = str_contains($out['stdout'], 'LIMITE='.self::MAX_PROCESSES);
        $forksRefused = (bool) preg_match('/fork|Resource temporarily unavailable|Cannot allocate/i', $combined);

        return $this->result(
            'fork_bomb',
            'Fork bomb',
            'contida pelo limite de processos, sem derrubar o host',
            $limitApplied && $forksRefused,
            match (true) {
                ! $limitApplied => 'O `ulimit -u` nao chegou a valer dentro do sandbox: '.$this->firstLine($out['stdout']),
                ! $forksRefused => 'O sandbox criou '.$attempts.' processos sem o kernel recusar nenhum.',
                default => 'O kernel recusou os forks acima de '.self::MAX_PROCESSES.'.',
            }
        );
    }

    /**
     * Alocacao sem teto, morta pelo cgroup (#86) ou pelo `ulimit -v`.
     *
     * O `tr` enche a substituicao de comando na memoria do proprio bash, sem
     * depender de nenhum runtime instalado -- o autoteste tem que valer numa
     * maquina que ainda nao terminou de ser preparada.
     *
     * Qual mecanismo prendeu entra no relatorio porque a diferenca importa:
     * com cgroup o kernel mata; sem ele o `ulimit -v` so recusa a alocacao,
     * e ha runtime que reserva endereco sem tocar (a razao de o #86 ter
     * medido isso em vez de supor).
     */
    private function unboundedAllocation(AutoJudgeService $judge, CgroupMemoryLimiter $cgroup): array
    {
        $viaCgroup = $cgroup->isAvailable();
        $name = 'selftest_'.getmypid();

        $megabytes = self::MEMORY_MB * 4;
        $attempted = $megabytes * 1024 * 1024;
        $script = "x=\$(dd if=/dev/zero bs=1M count={$megabytes} 2>/dev/null | tr '\\0' 'a'); echo \"SOBREVIVEU=\${#x}\"";

        $out = $this->inSandbox($judge, $script, [
            // Sem cgroup, a barreira de espaco de enderecamento e tudo o que
            // resta -- e e a configuracao de uma instalacao sem subarvore
            // delegada, que existe.
            'memory_kb' => $viaCgroup ? null : self::MEMORY_MB * 1024,

            // Folga de CPU so para este caso. Copiar 256 MB com `tr` custa
            // mais de dois segundos de CPU numa maquina modesta, e um teste
            // de memoria morto pelo limite de TEMPO nao teria testado
            // memoria nenhuma -- teria passado, ou falhado, pelo motivo
            // errado. Continua abaixo do backstop de parede.
            'cpu_seconds' => self::WALL_SECONDS - 2,
        ], $viaCgroup ? $cgroup : null, $name);

        // Sobreviver e ter ficado com a string INTEIRA. Uma alocacao que o
        // `ulimit -v` recusa pode deixar o bash vivo com um buffer truncado,
        // e a simples presenca da linha diria "sobreviveu" para exatamente o
        // caso em que a barreira funcionou.
        preg_match('/SOBREVIVEU=(\d+)/', $out['stdout'], $sizes);
        $survived = isset($sizes[1]) && (int) $sizes[1] >= $attempted;
        $killedByCgroup = $viaCgroup && $cgroup->exceeded($out['cgroup_usage'], self::MEMORY_MB, $out['exit_code'] === 0);

        return $this->result(
            'memory',
            'Alocacao sem teto',
            $viaCgroup ? 'morta pelo memory.max do cgroup' : 'recusada pelo ulimit -v (sem cgroup nesta maquina)',
            ! $survived && ($killedByCgroup || ! $viaCgroup),
            match (true) {
                $survived => 'O processo alocou '.$megabytes.' MB com um teto de '.self::MEMORY_MB.' MB e sobreviveu.',
                $killedByCgroup => 'O kernel matou o processo no memory.max.',
                $viaCgroup => 'O processo morreu, mas o cgroup nao registrou oom_kill nem pico no teto -- o que matou nao foi o limite.',
                default => 'A alocacao foi recusada pelo ulimit -v. Sem cgroup nesta maquina, esta e a unica barreira: veja config/autojudge.php.',
            }
        );
    }

    /**
     * Encher o disco, contido pelo `ulimit -f`.
     *
     * O que se confere e o tamanho do arquivo, e nao so o codigo de saida:
     * um `dd` que morre de SIGXFSZ e um `dd` que falhou por outro motivo
     * saem os dois diferente de zero, e so o primeiro prova alguma coisa.
     */
    private function fillDisk(AutoJudgeService $judge): array
    {
        $megabytes = (int) (self::FILE_SIZE_KB / 1024) * 8;
        $script = "dd if=/dev/zero of=./grande bs=1M count={$megabytes} 2>/dev/null; echo \"TAMANHO=\$(wc -c < ./grande 2>/dev/null || echo 0)\"";

        $out = $this->inSandbox($judge, $script, ['file_size_kb' => self::FILE_SIZE_KB]);

        preg_match('/TAMANHO=(\d+)/', $out['stdout'], $m);
        $written = isset($m[1]) ? (int) $m[1] : -1;
        $ceiling = self::FILE_SIZE_KB * 1024;

        return $this->result(
            'disk',
            'Escrever ate encher',
            'contido pelo limite de tamanho de arquivo do workspace',
            $written >= 0 && $written <= $ceiling,
            $written < 0
                ? 'Nao foi possivel medir o arquivo escrito.'
                : 'Escreveu '.$written.' bytes com teto de '.$ceiling.'.'
        );
    }

    /**
     * Rede, recusada pelo namespace.
     *
     * Lendo /proc/net/dev, e nao tentando abrir um socket: uma tentativa de
     * conexao falha igual numa maquina que so esta sem internet, o que
     * provaria nada. Dentro de um namespace de rede novo so existe `lo`, e
     * isso e uma afirmacao sobre o isolamento e nao sobre a rede da casa.
     */
    private function network(AutoJudgeService $judge): array
    {
        $out = $this->inSandbox($judge, "awk -F: 'NR>2 {gsub(/ /,\"\",\$1); print \$1}' /proc/net/dev");

        $interfaces = array_values(array_filter(array_map('trim', explode("\n", $out['stdout']))));
        $foreign = array_values(array_diff($interfaces, ['lo']));

        return $this->result(
            'network',
            'Abrir socket',
            'recusado: sem rede dentro do sandbox',
            $interfaces !== [] && $foreign === [],
            match (true) {
                $interfaces === [] => 'Nao foi possivel ler /proc/net/dev dentro do sandbox.',
                $foreign !== [] => 'O sandbox enxerga interface de rede alem de lo: '.implode(', ', $foreign),
                default => 'Dentro do sandbox so existe lo.',
            }
        );
    }

    /**
     * Segredos do host, negados.
     *
     * /etc ESTA montado (somente leitura) na lista de sandbox_paths, porque
     * os toolchains precisam dele -- entao o que impede a leitura de
     * /etc/shadow aqui e a permissao do arquivo, e nao a montagem. Isso
     * torna o caso mais importante do que parece: um judgehost que rode como
     * root le shadow de dentro do sandbox, e e exatamente o tipo de coisa
     * que so se descobre olhando a maquina.
     *
     * O .env da aplicacao entra na lista por outro motivo: ele NAO deve
     * estar montado de forma alguma (docs/specs/49-judge-isolation.md), e le-lo
     * seria a raiz da aplicacao ter vazado para dentro.
     */
    private function secrets(AutoJudgeService $judge): array
    {
        $targets = ['/etc/shadow', '/etc/sudoers', '/root/.ssh/id_rsa', base_path('.env')];
        $script = '';

        foreach ($targets as $target) {
            $script .= 'if head -c 1 '.escapeshellarg($target).' >/dev/null 2>&1; then echo "LEU='.$target.'"; fi; ';
        }

        $script .= 'echo FIM';

        $out = $this->inSandbox($judge, $script);

        preg_match_all('/LEU=(.+)/', $out['stdout'], $m);
        $read = $m[1] ?? [];

        return $this->result(
            'secrets',
            'Ler segredos do host',
            'negado',
            $read === [],
            $read === []
                ? 'Nenhum dos alvos foi legivel: '.implode(', ', $targets)
                : 'LEGIVEL de dentro do sandbox: '.implode(', ', array_map('trim', $read))
        );
    }

    /**
     * Tempo de CPU, morto pelo `ulimit -t`.
     */
    private function cpuTime(AutoJudgeService $judge): array
    {
        $started = microtime(true);
        $out = $this->inSandbox($judge, 'while :; do :; done; echo NUNCA', ['cpu_seconds' => self::CPU_SECONDS]);
        $elapsed = microtime(true) - $started;

        return $this->result(
            'cpu_time',
            'Exceder tempo de CPU',
            'morto pelo ulimit -t',
            ! str_contains($out['stdout'], 'NUNCA') && $elapsed < self::WALL_SECONDS,
            'O laco terminou em '.round($elapsed, 1).'s com limite de CPU de '.self::CPU_SECONDS.'s.'
        );
    }

    /**
     * Tempo de parede, morto pelo backstop.
     *
     * Este e o caso que a issue pede em separado por um motivo concreto:
     * `sleep` nao gasta CPU nenhuma, entao o `ulimit -t` nunca o alcanca.
     * Se o unico limite fosse o de CPU, um envio que dorme prenderia um
     * judgehost pelo tempo que quisesse -- e um judgehost preso e uma fila
     * que nao anda, que e o #199 inteiro.
     */
    private function wallTime(AutoJudgeService $judge): array
    {
        $started = microtime(true);
        $out = $this->inSandbox($judge, 'sleep 120; echo NUNCA', ['cpu_seconds' => self::CPU_SECONDS]);
        $elapsed = microtime(true) - $started;

        return $this->result(
            'wall_time',
            'Exceder tempo de parede sem gastar CPU',
            'morto pelo limite de parede, e nao so pelo de CPU',
            ! str_contains($out['stdout'], 'NUNCA') && $elapsed < 120,
            'O sleep foi interrompido em '.round($elapsed, 1).'s.'
        );
    }

    /**
     * Roda um trecho dentro do sandbox de verdade.
     *
     * NAO se chama execute(): Illuminate\Console\Command ja tem um
     * execute() protegido, e um metodo privado de mesmo nome e erro fatal na
     * carga da classe -- o comando nao existiria, e o sintoma seria um
     * autoteste que nunca roda numa maquina onde ninguem foi conferir.
     *
     * @param  array<string, mixed>  $options
     * @return array{stdout: string, stderr: string, exit_code: int|null, cgroup_usage: array{peak_bytes: ?int, oom_kills: int}|null}
     */
    private function inSandbox(
        AutoJudgeService $judge,
        string $script,
        array $options = [],
        ?CgroupMemoryLimiter $cgroup = null,
        ?string $cgroupName = null,
    ): array {
        // Redundante de proposito, e a redundancia foi medida.
        //
        // Apagando a porta la em cima do handle() e rodando a suite, os
        // trechos hostis deste arquivo -- a bomba de forks, o laco de CPU, a
        // alocacao de 256 MB -- passaram a rodar SEM CONFINAMENTO na
        // maquina, porque wrapWithBwrap() devolve o comando intacto quando
        // use_bwrap esta desligado. Uma unica linha no topo de um metodo era
        // tudo o que separava um autoteste de sandbox de um programa que
        // executa codigo hostil no host. Agora sao duas, em lugares
        // diferentes.
        if (! config('autojudge.use_bwrap', true)) {
            throw new \RuntimeException('Recusando executar o autoteste sem sandbox: o trecho e hostil por desenho.');
        }

        $command = $judge->wrapWithBwrap($script, $this->workspace, array_merge([
            'allow_net' => false,
            'cpu_seconds' => self::CPU_SECONDS,
            'file_size_kb' => self::FILE_SIZE_KB,
            'max_processes' => self::MAX_PROCESSES,
        ], $options));

        if ($cgroup !== null && $cgroupName !== null) {
            $command = $cgroup->confine($command, $cgroupName, self::MEMORY_MB);
        }

        $usage = null;

        try {
            $result = Process::timeout(self::WALL_SECONDS)->path($this->workspace)->run($command);
            $output = ['stdout' => $result->output(), 'stderr' => $result->errorOutput(), 'exit_code' => $result->exitCode()];
        } catch (\Throwable $e) {
            // O backstop de parede disparando NAO e erro deste comando: e o
            // resultado esperado do caso de tempo de parede. Vira saida
            // vazia com codigo nulo, e cada caso decide o que isso significa
            // para ele.
            $output = ['stdout' => '', 'stderr' => $e->getMessage(), 'exit_code' => null];
        } finally {
            if ($cgroup !== null && $cgroupName !== null) {
                $usage = $cgroup->release($cgroupName);
            }
        }

        return $output + ['cgroup_usage' => $usage];
    }

    /**
     * @return array{key: string, title: string, expected: string, passed: bool, detail: string}
     */
    private function result(string $key, string $title, string $expected, bool $passed, string $detail): array
    {
        $detail = self::printable($detail);

        return compact('key', 'title', 'expected', 'passed', 'detail');
    }

    /**
     * @param  list<array{key: string, title: string, expected: string, passed: bool, detail: string}>  $results
     */
    private function report(array $results): int
    {
        $failed = array_values(array_filter($results, fn (array $r) => ! $r['passed']));

        if ($this->option('json')) {
            // JSON_INVALID_UTF8_SUBSTITUTE como segunda barreira: printable()
            // ja limpa cada detalhe, mas um json_encode que falha aqui volta
            // `false` e vira uma linha em branco -- silencio no lugar do
            // relatorio. Melhor um caractere substituido do que um autoteste
            // que se apaga.
            $this->line((string) json_encode([
                'host' => gethostname(),
                'generated_at' => now()->toISOString(),
                'passed' => $failed === [],
                'cases' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($results as $case) {
            $mark = $case['passed'] ? '<info>  OK  </info>' : '<error> FALHA </error>';
            $this->line("{$mark} {$case['title']} -- {$case['expected']}");
            $this->line("        {$case['detail']}");
        }

        $this->newLine();

        // A linha que a organizacao cola num checklist. A regra e a do
        // mojtools, e e categorica de proposito: um caso que falha nao e um
        // detalhe a ponderar, e uma maquina que nao confina.
        if ($failed === []) {
            $this->info(count($results).'/'.count($results).' casos passaram em '.gethostname().'. Esta maquina pode julgar.');

            return self::SUCCESS;
        }

        $this->error(
            count($failed).' de '.count($results).' casos FALHARAM em '.gethostname().': '
            .implode(', ', array_column($failed, 'key')).'. NAO use esta maquina em prova.'
        );

        return self::FAILURE;
    }

    private function firstLine(string $text): string
    {
        return self::printable(trim(strtok($text, "\n") ?: ''));
    }

    /**
     * Texto que saiu do sandbox, seguro para json_encode().
     *
     * Nao e higiene defensiva: a saida em JSON chegou VAZIA no job Judging
     * do CI, e a causa era esta. Um dos casos carrega stderr do proprio
     * bwrap no relatorio, bytes que nao formam UTF-8 valido fazem
     * json_encode() devolver `false`, e `(string) false` e a string vazia --
     * o comando imprimia uma linha em branco e saia com codigo zero. Um
     * relatorio de confinamento que se apaga sozinho e pior do que um que
     * falha: ninguem vai conferir um autoteste que "passou".
     */
    private static function printable(string $text): string
    {
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    private function removeWorkspace(): void
    {
        if (! is_dir($this->workspace)) {
            return;
        }

        foreach (glob($this->workspace.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($this->workspace);
    }
}
