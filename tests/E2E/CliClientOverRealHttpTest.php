<?php

namespace Tests\E2E;

use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Symfony\Component\Process\Process;

/**
 * Issue #145 -- bin/mh against a real server over a real socket.
 *
 * The client is the only part of this system that is not PHP and does not
 * share a process with the application, so a test that faked its HTTP would
 * be testing nothing: the whole risk is in the seam. It runs the actual
 * script, with its own config file, against `artisan serve`.
 *
 * This is also the first test in the repository that exercises the token
 * route from #159 the way a client does -- credentials in, bearer token
 * out, token used. Before #159 none of this was possible at all: the
 * Sanctum table was never migrated and nothing issued a token, so every
 * authenticated route was unreachable by anything that is not a browser.
 *
 * Judging is deliberately out of scope here. The seeded problem has
 * auto_judge off and the verdict is written into the database by this test,
 * standing in for the judge -- otherwise the client's test would need
 * bubblewrap and GNU time and would skip on every developer machine, which
 * is precisely the fate this suite already suffers elsewhere.
 *
 * Deliberately not extending Tests\TestCase: that boots the app with an
 * in-memory database inside a transaction, and no other process can see
 * either.
 */
class CliClientOverRealHttpTest extends BaseTestCase
{
    private string $workspace;

    private string $serverDatabase;

    private ?Process $server = null;

    private int $port;

    private string $environmentName;

    protected function setUp(): void
    {
        parent::setUp();

        // Before anything is initialised: a skip here means tearDown() runs
        // against typed properties that were never set.
        $this->skipWithoutPython();

        $this->workspace = sys_get_temp_dir().'/mh_cli_e2e_'.getmypid().'_'.random_int(1000, 9999);
        @mkdir($this->workspace, 0755, true);

        $this->serverDatabase = $this->workspace.'/server.sqlite';
        touch($this->serverDatabase);

        $this->port = $this->freePort();
        $this->environmentName = 'clie2e'.getmypid();
        $this->writeEnvironmentFile();
    }

    protected function tearDown(): void
    {
        if (! isset($this->environmentName)) {
            parent::tearDown();

            return;
        }

        $this->server?->stop(2);

        @unlink($this->environmentFile());
        $this->removeTree($this->workspace);

        parent::tearDown();
    }

    /**
     * The client is Python 3 and the standard library, which is what a
     * university lab already has. A machine without it cannot run the
     * client, so there is nothing here to measure -- say so in one line
     * rather than failing for a reason that is not a defect.
     */
    private function skipWithoutPython(): void
    {
        $probe = new Process([$this->python(), '--version']);
        $probe->run();

        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('python3 is not available, so bin/mh cannot run here.');
        }
    }

    private function python(): string
    {
        return getenv('MH_PYTHON') ?: 'python3';
    }

    public function test_a_team_logs_in_submits_and_reads_its_verdict_with_no_browser(): void
    {
        $migrate = $this->artisan(['migrate', '--force', '--no-interaction'], 300);
        $this->assertTrue($migrate->isSuccessful(), 'migrate failed: '.$migrate->getErrorOutput());

        $seed = $this->php(['tests/E2E/support/seed_cli_scenario.php', $this->workspace], 120);
        $this->assertTrue(
            $seed->isSuccessful(),
            "seed failed:\n".$seed->getOutput().$seed->getErrorOutput()
        );

        $contestId = (int) file_get_contents($this->workspace.'/contest_id');
        $problemId = (int) file_get_contents($this->workspace.'/problem_id');
        $languageId = (int) file_get_contents($this->workspace.'/language_id');

        $this->startServer();

        // 1. A token, from credentials, over HTTP. This is the step that did
        //    not exist before #159.
        $login = $this->mh(['login', '--login', 'equipe01', '--password', 'senha-da-equipe']);
        $this->assertSame(0, $login->getExitCode(), "login failed:\n".$this->say($login));
        $this->assertStringContainsString('Autenticado como equipe01', $login->getOutput());

        $config = json_decode((string) file_get_contents($this->configFile()), true);
        $this->assertNotEmpty($config['token'] ?? null, 'the client stored no token');

        // The token is a real row, and the plain text is not in it.
        [, $secret] = explode('|', $config['token'], 2);
        $stored = $this->db()->query('select token from personal_access_tokens')->fetchColumn();
        $this->assertSame(hash('sha256', $secret), $stored);

        // 0600, because this file lives in a shared lab account's home.
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->configFile())), -4));

        // 2. The contest's problems and languages, discovered rather than
        //    configured -- which is what lets `mh submit A.sh` work.
        $problems = $this->mh(['problems']);
        $this->assertSame(0, $problems->getExitCode(), $this->say($problems));
        $this->assertStringContainsString('A      Soma', $problems->getOutput());
        $this->assertStringContainsString('.sh', $problems->getOutput());
        $this->assertStringNotContainsString(
            '.py',
            $problems->getOutput(),
            'an inactive language was offered; submitting it would be refused by the server'
        );

        // 3. A submission, with the problem taken from the file name and the
        //    language from its extension.
        $source = $this->workspace.'/A.sh';
        file_put_contents($source, "read a b\necho \$((a + b))\n");

        $submit = $this->mh(['submit', $source, '--no-wait']);
        $this->assertSame(0, $submit->getExitCode(), "submit failed:\n".$this->say($submit));
        $this->assertStringContainsString('para A (Shell)', $submit->getOutput());

        $run = $this->db()
            ->query('select id, contest_id, problem_id, language_id, status, filename from runs')
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($run, "no run reached the database:\n".$this->say($submit));
        $this->assertSame($contestId, (int) $run['contest_id']);
        $this->assertSame($problemId, (int) $run['problem_id'], 'the file name did not resolve to problem A');
        $this->assertSame($languageId, (int) $run['language_id'], 'the extension did not resolve to Shell');
        $this->assertSame('A.sh', $run['filename']);
        $this->assertSame('pending', $run['status']);

        // 4. The verdict, as the judge would leave it.
        $accepted = (int) $this->db()
            ->query("select id from answers where short_name = 'AC'")
            ->fetchColumn();
        $this->db()->exec(
            "update runs set status = 'judged', answer_id = {$accepted} where id = ".(int) $run['id']
        );

        $runs = $this->mh(['runs']);
        $this->assertSame(0, $runs->getExitCode(), $this->say($runs));
        $this->assertMatchesRegularExpression('/#\d+\s+A\s+judged\s+AC/', $runs->getOutput());

        // 5. And the credential can be withdrawn from the machine it was
        //    typed into, which is the half that makes a lab token safe to
        //    issue at all.
        $logout = $this->mh(['logout']);
        $this->assertSame(0, $logout->getExitCode(), $this->say($logout));
        $this->assertSame(
            0,
            (int) $this->db()->query('select count(*) from personal_access_tokens')->fetchColumn(),
            'logout left the token working on the server'
        );
    }

    /**
     * A file name with a quote in it, all the way to the database.
     *
     * Found in review. `Content-Disposition: form-data; name="source_file";
     * filename="{name}"` had the name interpolated raw, so `A"1.sh` produced
     * `filename="A"1.sh"` -- malformed per RFC 7578 4.2, because the quote
     * closes the parameter early. A POSIX file name may legally contain a
     * quote, a backslash, and on Linux even a raw newline, and Linux is the
     * lab machine this client is written for.
     *
     * This asserts against the real parser rather than against the RFC:
     * whether backslash-escaping inside a quoted parameter is understood is
     * a property of the server, and "the specification permits it" is a
     * different claim from "this application reads it".
     *
     * Issue #311 -- o que se espera no BANCO mudou, e o que este teste mede
     * nao mudou.
     *
     * Antes daquela issue a API gravava `getClientOriginalName()` cru, entao
     * "a aspa chegou inteira" e "a aspa esta gravada" eram a mesma
     * afirmacao. Nao sao mais: a aspa chega inteira e e SANEADA na entrada,
     * porque dali ela seguia sem escape para a linha que o AutoJudgeService
     * entrega a `bash -c`.
     *
     * A propriedade que este teste existe para medir continua inteira, e e
     * por isso que a asserção continua valendo alguma coisa. Se o
     * `header_value()` do `bin/mh` voltar a interpolar cru, a aspa fecha o
     * parametro cedo, o servidor recebe `A` -- e nao o nome todo -- e o
     * banco guarda `A`. Com a codificacao certa ele recebe `A"1.sh` inteiro
     * e guarda `A_1.sh`. Os dois casos continuam distinguiveis; so deixou de
     * ser a aspa literal o que os distingue. Medido: com a interpolacao crua
     * restaurada, este teste falha com 'A'.
     */
    public function test_a_file_name_with_a_quote_in_it_survives_the_upload(): void
    {
        $migrate = $this->artisan(['migrate', '--force', '--no-interaction'], 300);
        $this->assertTrue($migrate->isSuccessful(), 'migrate failed: '.$migrate->getErrorOutput());

        $seed = $this->php(['tests/E2E/support/seed_cli_scenario.php', $this->workspace], 120);
        $this->assertTrue($seed->isSuccessful(), $seed->getErrorOutput());

        $this->startServer();

        $login = $this->mh(['login', '--login', 'equipe01', '--password', 'senha-da-equipe']);
        $this->assertSame(0, $login->getExitCode(), $this->say($login));

        // --problem is needed because the file name no longer spells the
        // problem's short name, which is itself the realistic case: the
        // competitor called the file something of their own.
        $source = $this->workspace.'/A"1.sh';
        file_put_contents($source, "read a b\necho \$((a + b))\n");

        $submit = $this->mh(['submit', $source, '--problem', 'A', '--no-wait']);
        $this->assertSame(0, $submit->getExitCode(), "submit failed:\n".$this->say($submit));

        $run = $this->db()->query('select filename, source_hash from runs')->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($run, "nothing reached the database:\n".$this->say($submit));

        // O nome INTEIRO chegou -- e a aspa virou `_` na entrada (#311). Um
        // parametro fechado cedo pela aspa daria `A`, e e esse o caso que
        // esta asserção separa.
        $this->assertSame(
            'A_1.sh',
            $run['filename'],
            'the quote did not survive the header encoding: the server saw a truncated name'
        );
        $this->assertNotSame('A', $run['filename'], 'the quoted parameter was closed early by the quote');
        $this->assertSame(hash_file('sha256', $source), $run['source_hash'], 'the file body was corrupted');
    }

    /**
     * The three ways a competitor gets this wrong under time pressure. Each
     * has to come back as a sentence they can act on, not an HTTP status:
     * a CLI that prints "422" during a contest costs the team the minutes
     * it takes to find out what that meant.
     */
    public function test_the_client_explains_itself_when_something_is_wrong(): void
    {
        $migrate = $this->artisan(['migrate', '--force', '--no-interaction'], 300);
        $this->assertTrue($migrate->isSuccessful(), 'migrate failed: '.$migrate->getErrorOutput());

        $seed = $this->php(['tests/E2E/support/seed_cli_scenario.php', $this->workspace], 120);
        $this->assertTrue($seed->isSuccessful(), $seed->getErrorOutput());

        $this->startServer();

        $wrongPassword = $this->mh(['login', '--login', 'equipe01', '--password', 'nao-e-essa']);
        $this->assertSame(2, $wrongPassword->getExitCode());
        $this->assertStringContainsString('Credenciais invalidas', $this->say($wrongPassword));

        $second = $this->mh(['login', '--login', 'equipe01', '--password', 'senha-da-equipe']);
        $this->assertSame(0, $second->getExitCode(), $this->say($second));

        // A language the contest has but has switched off. The client must
        // refuse locally: the server would answer 422 "Language is not
        // available", after the whole file had been uploaded.
        $python = $this->workspace.'/A.py';
        file_put_contents($python, "print(sum(map(int, input().split())))\n");

        $inactive = $this->mh(['submit', $python]);
        $this->assertSame(2, $inactive->getExitCode());
        $this->assertStringContainsString('Nenhuma linguagem para a extensão ".py"', $this->say($inactive));
        $this->assertStringContainsString('Disponíveis: sh', $this->say($inactive));

        // A file named after a problem that is not in this contest.
        $wrongName = $this->workspace.'/Z.sh';
        file_put_contents($wrongName, "echo 0\n");

        $unknown = $this->mh(['submit', $wrongName]);
        $this->assertSame(2, $unknown->getExitCode());
        $this->assertStringContainsString('Nenhum problema com a sigla "z"', $this->say($unknown));
        $this->assertStringContainsString('Disponíveis: A', $this->say($unknown));

        // Nothing was sent in either case -- the point of refusing locally.
        $this->assertSame(
            0,
            (int) $this->db()->query('select count(*) from runs')->fetchColumn(),
            'the client uploaded a submission it had already decided was wrong'
        );
    }

    /**
     * The waiting path, and the exit code that comes out of it.
     *
     * `mh submit a.cpp && echo ok` is the whole reason the verdict is the
     * exit status, and the only way to prove it is to let the client really
     * poll: start it, let the judge (this test) answer while it is waiting,
     * and read what it exits with.
     */
    public function test_submitting_waits_for_the_verdict_and_exits_with_it(): void
    {
        $migrate = $this->artisan(['migrate', '--force', '--no-interaction'], 300);
        $this->assertTrue($migrate->isSuccessful(), 'migrate failed: '.$migrate->getErrorOutput());

        $seed = $this->php(['tests/E2E/support/seed_cli_scenario.php', $this->workspace], 120);
        $this->assertTrue($seed->isSuccessful(), $seed->getErrorOutput());

        $this->startServer();

        $login = $this->mh(['login', '--login', 'equipe01', '--password', 'senha-da-equipe']);
        $this->assertSame(0, $login->getExitCode(), $this->say($login));

        $source = $this->workspace.'/A.sh';
        file_put_contents($source, "read a b\necho \$((a + b))\n");

        $submit = $this->start(['submit', $source, '--timeout', '60']);

        $runId = $this->waitForRun();

        // The judge's part, played by this test: the seeded problem has
        // auto_judge off precisely so that no toolchain is needed here.
        $rejected = (int) $this->db()
            ->query("select id from answers where short_name = 'WA'")
            ->fetchColumn();
        $this->db()->exec("update runs set status = 'judged', answer_id = {$rejected} where id = {$runId}");

        $submit->wait();

        // 1, not 0: a wrong answer is a failed command, so the shell's own
        // `&&` does the right thing without the team parsing anything.
        $this->assertSame(1, $submit->getExitCode(), 'a rejected run exited 0: '.$this->say($submit));
        $this->assertStringContainsString('judged', $submit->getOutput());
        $this->assertStringContainsString('WA', $submit->getOutput());
    }

    /**
     * The throttle from #159, proved where it matters.
     *
     * Every other test of it dispatches requests into this application's own
     * router in one process. This one goes through a socket, through the
     * middleware stack, into a cache the server writes to disk -- which is
     * the arrangement a password-guesser would actually meet. It earns its
     * place because the first version of this file failed for exactly this
     * reason: the limit was real, and the test had not been given a fresh
     * counter.
     */
    public function test_repeated_wrong_passwords_are_throttled_over_real_http(): void
    {
        $migrate = $this->artisan(['migrate', '--force', '--no-interaction'], 300);
        $this->assertTrue($migrate->isSuccessful(), 'migrate failed: '.$migrate->getErrorOutput());

        $seed = $this->php(['tests/E2E/support/seed_cli_scenario.php', $this->workspace], 120);
        $this->assertTrue($seed->isSuccessful(), $seed->getErrorOutput());

        $this->startServer();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $refused = $this->mh(['login', '--login', 'equipe01', '--password', 'chute-'.$attempt]);

            $this->assertSame(2, $refused->getExitCode());
            $this->assertStringContainsString(
                'Credenciais invalidas',
                $this->say($refused),
                "attempt {$attempt} was not simply refused"
            );
        }

        // The sixth is refused by the limiter, and -- the half that matters --
        // it is refused even though this one has the RIGHT password.
        $blocked = $this->mh(['login', '--login', 'equipe01', '--password', 'senha-da-equipe']);

        $this->assertSame(2, $blocked->getExitCode());
        $this->assertStringContainsString('Tentativas demais', $this->say($blocked));

        $this->assertSame(
            0,
            (int) $this->db()->query('select count(*) from personal_access_tokens')->fetchColumn(),
            'a token was issued despite the limiter'
        );
    }

    /**
     * The id of the run the client has just created, once it is there.
     */
    private function waitForRun(): int
    {
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $id = $this->db()->query('select id from runs order by id desc limit 1')->fetchColumn();

            if ($id !== false) {
                return (int) $id;
            }

            usleep(200_000);
        }

        $this->fail('the client never submitted anything.');
    }

    // ----------------------------------------------------------------------

    private function start(array $arguments): Process
    {
        $process = $this->process($arguments, 120);
        $process->start();

        return $process;
    }

    private function mh(array $arguments, int $timeout = 120): Process
    {
        $process = $this->process($arguments, $timeout);

        $process->run();

        return $process;
    }

    private function process(array $arguments, int $timeout): Process
    {
        return new Process(
            array_merge([$this->python(), 'bin/mh', '--server', 'http://127.0.0.1:'.$this->port], $arguments),
            $this->base(),
            [
                'MH_CONFIG' => $this->configFile(),
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                // The client asks for a password on a TTY when it is not
                // given one. There is no TTY here, and a client that blocks
                // would hang the suite rather than fail it.
                'HOME' => $this->workspace,
            ],
            null,
            $timeout,
        );
    }

    private function configFile(): string
    {
        return $this->workspace.'/mh-config.json';
    }

    private function say(Process $process): string
    {
        return $process->getOutput().$process->getErrorOutput();
    }

    private function db(): PDO
    {
        $pdo = new PDO('sqlite:'.$this->serverDatabase);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function base(): string
    {
        return dirname(__DIR__, 2);
    }

    private function environmentFile(): string
    {
        return $this->base().'/.env.'.$this->environmentName;
    }

    private function writeEnvironmentFile(): void
    {
        $lines = [];

        foreach (file($this->base().'/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (! in_array(strtok($line, '='), array_keys($this->overrides()), true)) {
                $lines[] = $line;
            }
        }

        foreach ($this->overrides() as $key => $value) {
            $lines[] = $key.'='.$value;
        }

        file_put_contents($this->environmentFile(), implode(PHP_EOL, $lines).PHP_EOL);
    }

    /**
     * @return array<string, string>
     */
    private function overrides(): array
    {
        return [
            'APP_ENV' => $this->environmentName,
            'APP_DEBUG' => 'true',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->serverDatabase,
            // POST /api/tokens is throttled, so an unreachable cache turns
            // every login into a 500 whose body is a connection error.
            //
            // And the cache gets its own directory per test, which is not
            // tidiness: the rate limiter counts into this store, the
            // repository's storage/framework/cache/data is shared by every
            // test method AND by every previous run, and without this the
            // fourth login in a row -- across runs -- came back "too many
            // attempts". The limit is real and this test proves it below;
            // it just has to be measured against a fresh counter.
            'CACHE_STORE' => 'file',
            'CACHE_FILE_PATH' => $this->workspace.'/cache',
            'SESSION_DRIVER' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'TELESCOPE_ENABLED' => 'false',
            'PULSE_ENABLED' => 'false',
            'AUTOJUDGE_USE_BWRAP' => 'false',
        ];
    }

    private function artisan(array $command, int $timeout = 120): Process
    {
        return $this->php(array_merge(['artisan'], $command), $timeout);
    }

    private function php(array $arguments, int $timeout = 120): Process
    {
        // Both the file and the explicit variables, deliberately: PHPUnit
        // forces DB_DATABASE=:memory: into this process's environment,
        // Symfony Process passes the parent environment to children, and
        // Laravel's immutable loader lets a real environment variable beat
        // the file.
        $process = new Process(
            array_merge([PHP_BINARY], $arguments),
            $this->base(),
            $this->overrides() + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
            null,
            $timeout,
        );

        $process->run();

        return $process;
    }

    private function startServer(): void
    {
        $this->server = new Process(
            [PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', '--port='.$this->port],
            $this->base(),
            $this->overrides() + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
            null,
            null,
        );
        $this->server->start();

        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            if (! $this->server->isRunning()) {
                $this->fail('artisan serve exited: '.$this->server->getErrorOutput());
            }

            usleep(200_000);
        }

        $this->fail('artisan serve did not accept connections within 30s: '.$this->server->getErrorOutput());
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            $this->markTestSkipped("Cannot bind a local port: {$errstr}");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
