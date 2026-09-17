<?php

namespace Tests\Feature;

use App\Jobs\JudgeRunJob;
use App\Models\Contest;
use App\Models\Run;
use App\Services\AutoJudgeService;
use App\Services\Judgehost\SandboxPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Issue #282 -- o silencio vira mensagem.
 *
 * Quem sobe `docker-compose.dev.yml` recebe a aplicacao inteira e nenhuma
 * capacidade de julgar: aquele compose nao tem servico de juiz, e o `queue`
 * dele roda na imagem do app, como root. Submeter nao dava erro -- dava
 * nada. A conclusao natural de quem chega no projeto e "esta quebrado", e
 * nao "esta pilha nao julga de proposito".
 *
 * E havia a leitura pior: alguem "faz funcionar" -- instala o bwrap, liga
 * `privileged` -- e acaba executando codigo submetido sem confinamento
 * nenhum, achando que esta confinando. Medido nesse arranjo, o
 * `judgehost:selftest` reprovava `fork_bomb`, `network` e `secrets`, este
 * ultimo com /etc/shadow legivel de dentro do sandbox.
 */
class SandboxPreflightTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O bwrap desligado e a condicao mais grave, e por isso vem primeiro.
     */
    public function test_a_disabled_sandbox_blocks_judging(): void
    {
        config(['autojudge.use_bwrap' => false]);

        $blocker = app(SandboxPreflight::class)->blocker();

        $this->assertNotNull($blocker, 'sandbox desligado passou pela pre-checagem');
        $this->assertSame(SandboxPreflight::KEY_DISABLED, $blocker['key']);
        $this->assertStringContainsString('sem confinamento', $blocker['reason']);
    }

    public function test_a_missing_binary_blocks_judging(): void
    {
        config([
            'autojudge.use_bwrap' => true,
            'autojudge.bwrap_path' => '/nao/existe/bwrap',
        ]);

        $blocker = app(SandboxPreflight::class)->blocker();

        $this->assertNotNull($blocker, 'bwrap ausente passou pela pre-checagem');
        $this->assertSame(SandboxPreflight::KEY_BINARY, $blocker['key']);
        $this->assertStringContainsString('/nao/existe/bwrap', $blocker['reason']);
    }

    /**
     * A condicao que o compose de dev cria, e a que ninguem procurava.
     *
     * `posix_getuid()` nao e algo que um teste possa mudar, e uma guarda que
     * so o CI consegue exercitar e uma guarda que ninguem verifica -- por
     * isso `isRoot()` e protegido e dobravel.
     */
    public function test_running_as_root_blocks_judging(): void
    {
        config(['autojudge.use_bwrap' => true, 'autojudge.bwrap_path' => $this->umBinarioQueExiste()]);

        $blocker = $this->comoRoot()->blocker();

        $this->assertNotNull($blocker, 'root passou pela pre-checagem');
        $this->assertSame(SandboxPreflight::KEY_PRIVILEGE, $blocker['key']);
        $this->assertStringContainsString('uid 1000', $blocker['reason'], 'a mensagem nao diz o que fazer');
    }

    /**
     * O controle positivo, e o mais importante daqui: sem ele todo o resto
     * passaria igual se `blocker()` passasse a recusar sempre -- e uma
     * pre-checagem que recusa sempre nao deixa nada julgar.
     */
    public function test_a_fit_machine_is_not_blocked(): void
    {
        config([
            'autojudge.use_bwrap' => true,
            'autojudge.bwrap_path' => $this->umBinarioQueExiste(),
        ]);

        $this->assertNull(
            $this->comoUsuarioComum()->blocker(),
            'uma maquina apta foi recusada'
        );
    }

    // ---------------------------------------------------------------
    // O daemon.
    // ---------------------------------------------------------------

    public function test_the_daemon_refuses_to_start_on_an_unfit_machine(): void
    {
        config(['autojudge.use_bwrap' => false]);

        $this->artisan('autojudge:start', ['--once' => true])
            ->expectsOutputToContain('Esta maquina nao pode julgar.')
            ->expectsOutputToContain('judgehost:selftest')
            ->assertFailed();
    }

    /**
     * E numa maquina apta ele sobe -- senao a recusa acima valeria por
     * "o daemon nunca sobe".
     */
    public function test_the_daemon_still_starts_on_a_fit_machine(): void
    {
        config([
            'autojudge.use_bwrap' => true,
            'autojudge.bwrap_path' => $this->umBinarioQueExiste(),
        ]);

        $this->app->instance(SandboxPreflight::class, $this->comoUsuarioComum());

        $this->artisan('autojudge:start', ['--once' => true])
            ->expectsOutputToContain('Auto-judge daemon started')
            ->assertSuccessful();
    }

    // ---------------------------------------------------------------
    // O job da fila -- o caminho que o compose de dev usa.
    // ---------------------------------------------------------------

    public function test_the_queue_job_refuses_and_says_why(): void
    {
        config(['autojudge.use_bwrap' => false]);

        $run = $this->umaRunPendente();

        // O servico de julgamento nao pode nem ser chamado.
        $judge = $this->mock(AutoJudgeService::class);
        $judge->shouldNotReceive('judge');

        (new JudgeRunJob($run))->handle($judge, app(SandboxPreflight::class));

        $run->refresh();

        $this->assertSame('pending', $run->status, 'a run foi marcada como julgada sem veredito apurado');
        $this->assertStringContainsString(
            'Julgamento recusado',
            (string) $run->auto_judge_result,
            'o envio continuou sem dizer por que nao sera julgado'
        );
        $this->assertStringContainsString('sem confinamento', (string) $run->auto_judge_result);
    }

    /**
     * A run fica `pending` de proposito: consertada a maquina,
     * `runs:reconcile-stuck` (#45) a pega de novo. Este teste fixa isso, que
     * e a diferenca entre "espera" e "veredito inventado".
     */
    public function test_the_refused_run_stays_pickable(): void
    {
        config(['autojudge.use_bwrap' => false]);

        $run = $this->umaRunPendente();

        $judge = $this->mock(AutoJudgeService::class);
        $judge->shouldNotReceive('judge');

        (new JudgeRunJob($run))->handle($judge, app(SandboxPreflight::class));

        $this->assertDatabaseHas('runs', ['id' => $run->id, 'status' => 'pending']);
    }

    public function test_the_refusal_is_recorded_in_the_contest_log(): void
    {
        config(['autojudge.use_bwrap' => false]);

        $run = $this->umaRunPendente();

        $judge = $this->mock(AutoJudgeService::class);
        $judge->shouldNotReceive('judge');

        (new JudgeRunJob($run))->handle($judge, app(SandboxPreflight::class));

        $this->assertDatabaseHas('contest_logs', ['contest_id' => $run->contest_id, 'type' => 'warning']);
    }

    /**
     * A ORDEM importa, e foi um defeito meu pego pela suite existente.
     *
     * Com a guarda no topo do `handle()`, uma run JA JULGADA que fosse
     * redespachada -- o watchdog do #45 redespacha -- voltava para `pending`
     * e tinha o resultado real trocado por uma mensagem de recusa. A
     * pergunta certa vem primeiro: "isto ainda precisa de veredito?" antes
     * de "esta maquina pode dar um?".
     */
    public function test_an_already_judged_run_is_left_alone_on_an_unfit_machine(): void
    {
        config(['autojudge.use_bwrap' => false]);

        $run = $this->umaRunPendente();
        $run->update(['status' => 'judged', 'auto_judge_result' => 'Accepted']);

        $judge = $this->mock(AutoJudgeService::class);
        $judge->shouldNotReceive('judge');

        (new JudgeRunJob($run))->handle($judge, app(SandboxPreflight::class));

        $run->refresh();

        $this->assertSame('judged', $run->status, 'a run julgada voltou para pendente');
        $this->assertSame('Accepted', $run->auto_judge_result, 'o resultado real foi trocado pela recusa');
    }

    /**
     * E numa maquina apta o job julga normalmente -- o controle positivo do
     * lado do job.
     */
    public function test_the_queue_job_still_judges_on_a_fit_machine(): void
    {
        config([
            'autojudge.use_bwrap' => true,
            'autojudge.bwrap_path' => $this->umBinarioQueExiste(),
        ]);

        $run = $this->umaRunPendente();

        $judge = $this->mock(AutoJudgeService::class);
        $judge->shouldReceive('judge')->once();

        (new JudgeRunJob($run))->handle($judge, $this->comoUsuarioComum());
    }

    // ---------------------------------------------------------------
    // O selftest continua sendo a autoridade.
    // ---------------------------------------------------------------

    public function test_the_selftest_reports_root_as_a_failed_case(): void
    {
        config([
            'autojudge.use_bwrap' => true,
            'autojudge.bwrap_path' => $this->umBinarioQueExiste(),
        ]);

        $this->app->instance(SandboxPreflight::class, $this->comoRoot());

        $codigo = Artisan::call('judgehost:selftest', ['--json' => true]);
        $saida = Artisan::output();

        $this->assertSame(1, $codigo, 'o selftest passou numa maquina rodando como root');

        $documento = json_decode($saida, true);

        $this->assertIsArray($documento, 'o selftest nao devolveu JSON parseavel: '.$saida);

        $this->assertSame(
            [SandboxPreflight::KEY_PRIVILEGE],
            array_column($documento['cases'] ?? [], 'key'),
            'o selftest nao parou no caso de privilegio'
        );
        $this->assertFalse($documento['cases'][0]['passed']);
    }

    // ---------------------------------------------------------------

    private function comoRoot(): SandboxPreflight
    {
        return new class extends SandboxPreflight
        {
            protected function isRoot(): bool
            {
                return true;
            }
        };
    }

    private function comoUsuarioComum(): SandboxPreflight
    {
        return new class extends SandboxPreflight
        {
            protected function isRoot(): bool
            {
                return false;
            }
        };
    }

    /**
     * Um executável que existe em qualquer máquina onde esta suíte roda --
     * o teste é sobre a pré-checagem, e não sobre ter bubblewrap instalado.
     */
    private function umBinarioQueExiste(): string
    {
        return PHP_BINARY;
    }

    private function umaRunPendente(): Run
    {
        $contest = Contest::factory()->create();

        return Run::factory()->create([
            'contest_id' => $contest->id,
            'status' => 'pending',
        ]);
    }
}
