<?php

namespace Tests\Feature;

use App\Jobs\JudgeRunJob;
use App\Models\Answer;
use App\Models\Contest;
use App\Models\Judgehost;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Services\AutoJudgeService;
use App\Services\Judgehost\SandboxPreflight;
use App\Services\JudgeWorkQueue;
use Helium\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * As janelas do pipeline de julgamento: issues #312, #313 e #314.
 *
 * ESTES TESTES FORAM INVERTIDOS. Ate o PR que fechou as tres issues, cada
 * `test_janela_*` FIXAVA uma sequencia de estados alcancavel e errada, e o
 * docblock mandava inverte-lo quando a guarda existisse. A guarda existe:
 * cada teste agora afirma o OPOSTO do que afirmava, e e a guarda que ele
 * mede. Quebrar uma delas faz o teste correspondente falhar -- foi assim
 * que cada uma foi verificada, removendo a correcao e conferindo a falha.
 *
 * A regra continua valendo ao contrario: nao "conserte" um destes testes
 * sem reabrir a issue que ele cita. Um deles ficar verde de novo com a
 * guarda removida significa que ele parou de medir alguma coisa.
 *
 * Cada guarda vem acompanhada do seu CONTROLE POSITIVO, e agora eles sao de
 * dois tipos: o que mostra que a guarda dispara e o que mostra que ela NAO
 * come o caminho legitimo -- run pendente continua sendo julgada, e run
 * genuinamente abandonada continua sendo recuperada pelo watchdog do #45.
 * Uma guarda que so recusa nao seria correcao nenhuma.
 *
 * Nada aqui depende do sandbox: sao estados de linha e ordem de chamadas,
 * entao a suite mede o mesmo numa maquina sem bwrap e dentro do
 * Dockerfile.judge. Nenhum destes testes pula.
 */
class JudgingPipelineRaceTest extends TestCase
{
    use RefreshDatabase;

    private Contest $contest;

    private Site $site;

    private Problem $problem;

    private Language $language;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subMinutes(30)]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id, 'max_judge_wait_time' => 900]);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id, 'auto_judge' => true]);
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);
        Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'AC', 'is_accepted' => true]);
        Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'WA', 'is_accepted' => false]);
        Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'CS', 'is_accepted' => false]);
    }

    // ------------------------------------------------------------------
    // Issue #314 -- o caminho local olha para a reivindicacao
    // ------------------------------------------------------------------

    /**
     * Issue #314, guarda de ENTRADA. INVERTIDO: antes media que o job da
     * fila julgava de novo uma run ja reivindicada.
     *
     * `JudgeRunJob::handle()` recusava apenas `status === 'judged'`, entao
     * uma run em `judging` -- ja reivindicada, sob lock de linha, por um
     * judgehost remoto (`JudgeWorkQueue::claimNext()`) ou pelo daemon local
     * (`claimNextLocally()`, #126) -- passava reto e era julgada em
     * paralelo com quem a segurava. O `ShouldBeUnique` do job nao cobre
     * isto: ele e por dispatch na fila, e nenhum dos dois caminhos de
     * reivindicacao passa pela fila.
     *
     * A guarda passou a ser `status !== 'pending'`. E a reivindicacao
     * sobrevive ao job: a run continua `judging`, continua com o mesmo
     * judgehost, e quem a esta julgando nao foi atrapalhado.
     */
    public function test_job_da_fila_recusa_run_ja_reivindicada_por_judgehost(): void
    {
        [$host] = Judgehost::issue('judge-01');
        $run = $this->pendingRun();

        $claimed = app(JudgeWorkQueue::class)->claimNext($host);
        $this->assertTrue($claimed->is($run));
        $this->assertSame('judging', $run->fresh()->status);
        $this->assertSame($host->id, (int) $run->fresh()->judgehost_id);

        $julgados = $this->countingJudge();

        (new JudgeRunJob($run->fresh()))->handle($julgados['service'], $this->permissivePreflight());

        $this->assertSame(0, $julgados['calls']->count, 'o job da fila julgou um run que ja estava reivindicado');

        $run->refresh();
        $this->assertSame('judging', $run->status, 'a reivindicacao de quem segura a run tem de sobreviver ao job');
        $this->assertSame($host->id, (int) $run->judgehost_id);
    }

    /**
     * CONTROLE POSITIVO da guarda acima: ela dispara, e ja disparava, para
     * a run ja julgada.
     */
    public function test_controle_positivo_job_da_fila_recusa_run_ja_julgado(): void
    {
        $run = $this->pendingRun();
        $run->update(['status' => 'judged']);

        $julgados = $this->countingJudge();

        (new JudgeRunJob($run->fresh()))->handle($julgados['service'], $this->permissivePreflight());

        $this->assertSame(0, $julgados['calls']->count, 'a guarda de "ja julgado" existe e funciona');
    }

    /**
     * CONTROLE POSITIVO do outro lado, e o que impede a guarda de virar
     * "nunca julga": a run PENDENTE -- o unico estado de onde o job tem de
     * julgar -- continua sendo julgada.
     */
    public function test_controle_positivo_job_da_fila_julga_run_pendente(): void
    {
        $run = $this->pendingRun();

        $julgados = $this->countingJudge();

        (new JudgeRunJob($run->fresh()))->handle($julgados['service'], $this->permissivePreflight());

        $this->assertSame(1, $julgados['calls']->count, 'a guarda nao pode recusar o caminho normal');
    }

    // ------------------------------------------------------------------
    // Issue #312 -- o watchdog olha para a reivindicacao
    // ------------------------------------------------------------------

    /**
     * Issue #312. INVERTIDO: antes media que o watchdog gastava a tentativa
     * de uma run que um judgehost estava julgando naquele instante.
     *
     * `Run::isOverdue()` media `created_at -> now()` e nunca lia
     * `claimed_at`, entao uma run reivindicada AGORA, com a lease renovada
     * pelo heartbeat (#124), entrava no escopo do watchdog so por ter sido
     * enviada ha muito tempo -- que e o caso normal de fila represada
     * (#199).
     *
     * Agora `Run::waitingSince()` mede, em `judging`, desde a
     * reivindicacao. CONTROLE POSITIVO embutido, o mesmo de sempre:
     * `expireStaleLeases()` devolve 0 sobre a MESMA linha, no mesmo
     * instante -- a informacao que distingue "maquina morta" de "maquina
     * trabalhando" esta na linha e ja era lida corretamente por outro
     * componente. Agora os dois concordam.
     */
    public function test_watchdog_nao_toca_run_com_lease_viva(): void
    {
        Bus::fake();

        [$host] = Judgehost::issue('judge-01');
        $run = $this->pendingRun();
        $run->forceFill(['created_at' => now()->subSeconds(1000)])->save();

        app(JudgeWorkQueue::class)->claimNext($host);
        $run->refresh();
        $this->assertSame('judging', $run->status);

        // Controle positivo: o proprio sistema sabe que a maquina esta viva.
        $this->assertSame(0, app(JudgeWorkQueue::class)->expireStaleLeases());

        $this->artisan('runs:reconcile-stuck')->assertExitCode(0);

        Bus::assertNotDispatched(JudgeRunJob::class);

        $run->refresh();
        $this->assertSame(0, $run->reconcile_attempts, 'a tentativa unica do #45 nao pode ser gasta com julgamento vivo');
        $this->assertSame('judging', $run->status);
        $this->assertSame($host->id, (int) $run->judgehost_id);
        $this->assertNotNull($run->claim_token);
    }

    /**
     * CONTROLE POSITIVO do #312, e o teste que impede a correcao de virar
     * "o watchdog desiste de desistir".
     *
     * A MESMA run, com a MESMA idade de envio, mas reivindicada ha 1000 s e
     * sem nenhum sinal desde entao: e isso que "maquina que sumiu" quer
     * dizer, porque o heartbeat (#124) renova `claimed_at` enquanto a
     * maquina vive. O watchdog continua recuperando -- e devolve a
     * reivindicacao morta para `pending` antes de redespachar, senao a
     * guarda de entrada do #314 receberia a run em `judging` e o
     * redespacho seria um no-op.
     */
    public function test_controle_positivo_watchdog_ainda_recupera_run_abandonada(): void
    {
        Bus::fake();

        [$host] = Judgehost::issue('judge-01');
        $run = $this->pendingRun();
        $run->forceFill(['created_at' => now()->subSeconds(1000)])->save();

        app(JudgeWorkQueue::class)->claimNext($host);
        $run->forceFill(['claimed_at' => now()->subSeconds(1000)])->save();

        $this->artisan('runs:reconcile-stuck')->assertExitCode(0);

        Bus::assertDispatched(JudgeRunJob::class, fn ($job) => $job->run->is($run));

        $run->refresh();
        $this->assertSame(1, $run->reconcile_attempts);
        $this->assertSame('pending', $run->status, 'a reivindicacao morta tem de ser devolvida antes do redespacho');
        $this->assertNull($run->judgehost_id);
        $this->assertNull($run->claimed_at);
        $this->assertNull($run->claim_token);
    }

    /**
     * Issue #312, a consequencia. INVERTIDO: antes media que a equipe
     * recebia `CS` num envio julgado corretamente e que o veredito real era
     * recusado com 409.
     *
     * Percorre o caminho inteiro por HTTP -- o judgehost busca trabalho, o
     * watchdog roda, o veredito chega. Agora o watchdog nao encerra nada, e
     * o 200 no fim e o controle positivo pelo avesso: o host tinha um claim
     * valido e um veredito para entregar, e o veredito ENTRA.
     */
    public function test_watchdog_nao_marca_cs_em_julgamento_vivo_e_o_veredito_real_e_aceito(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $run = $this->pendingRun();
        $run->forceFill(['created_at' => now()->subSeconds(1000), 'reconcile_attempts' => 1])->save();

        $payload = $this->postJson('/api/remote-judges/v1/fetch-work', [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->json('data');

        $this->assertSame($run->id, $payload['run_id']);

        $this->artisan('runs:reconcile-stuck')->assertExitCode(0);

        $run->refresh();
        $this->assertSame('judging', $run->status, 'o watchdog encerrou um julgamento vivo');
        $this->assertNull($run->answer_id);

        // O veredito de verdade chega logo depois e e aceito.
        $this->postJson("/api/remote-judges/v1/runs/{$run->id}/result", ['verdict' => 'AC'], [
            'Authorization' => 'Bearer '.$token,
            'X-Claim-Token' => $payload['claim_token'],
        ])->assertOk();

        $this->assertSame('AC', $run->fresh()->answer->short_name);
    }

    // ------------------------------------------------------------------
    // Issue #313 -- a tentativa so e gasta quando acontece
    // ------------------------------------------------------------------

    /**
     * Issue #313. INVERTIDO: antes media que a tentativa era contada com o
     * dispatch engolido pelo lock de unicidade.
     *
     * O watchdog incrementava `reconcile_attempts` e chamava
     * `JudgeRunJob::dispatch()` sem nunca verificar se alguma coisa foi
     * enfileirada. Com o lock tomado -- worker morto por SIGKILL antes de o
     * `CallQueuedHandler` libera-lo, ou a segunda passada caindo dentro dos
     * 300 s do `uniqueFor` -- o Laravel descarta o dispatch em silencio, e
     * cinco minutos depois o `giveUp()` encerrava a run como `CS`. A
     * recuperacao prometida pelo #45 era contada, nao executada.
     *
     * O dispatch continua sendo engolido: isso e o Laravel, e esta certo. O
     * que mudou e que o comando agora SABE, escutando `UniqueJobSkipped`, e
     * nao gasta a tentativa. Lock tomado quer dizer que existe um job desta
     * run em algum lugar -- argumento para esperar, nao para desistir.
     *
     * O lock e tomado aqui com a chave real, `UniqueLock::getKey()`, e nao
     * com uma string escrita a mao: uma chave errada faria este teste
     * passar pelo motivo errado.
     */
    public function test_lock_orfao_nao_gasta_a_tentativa_do_watchdog(): void
    {
        Bus::fake();

        $run = $this->pendingRun();
        $run->forceFill(['created_at' => now()->subSeconds(1000)])->save();

        $key = UniqueLock::getKey(new JudgeRunJob($run));
        $this->assertTrue(Cache::lock($key, 300)->get(), 'o lock orfao tem de ser adquirivel');

        $this->artisan('runs:reconcile-stuck')->assertExitCode(0);

        Bus::assertNotDispatched(JudgeRunJob::class);
        $this->assertSame(0, $run->fresh()->reconcile_attempts, 'tentativa contada sem nada ter sido enfileirado');

        // E a consequencia que a issue descreve nao acontece mais: a
        // passada seguinte, com o lock ainda tomado, nao encerra a run como
        // `CS` -- porque nenhuma tentativa chegou a ser gasta.
        $this->artisan('runs:reconcile-stuck')->assertExitCode(0);

        $run->refresh();
        $this->assertSame('pending', $run->status);
        $this->assertNull($run->answer_id);
    }

    /**
     * CONTROLE POSITIVO do teste acima: a unica diferenca entre os dois
     * cenarios e o lock. Sem ele, a mesma run atrasada e redespachada e a
     * tentativa e gasta -- que e o comportamento do #45 e continua inteiro.
     */
    public function test_controle_positivo_sem_lock_o_watchdog_redespacha(): void
    {
        Bus::fake();

        $run = $this->pendingRun();
        $run->forceFill(['created_at' => now()->subSeconds(1000)])->save();

        $this->artisan('runs:reconcile-stuck')->assertExitCode(0);

        Bus::assertDispatched(JudgeRunJob::class);
        $this->assertSame(1, $run->fresh()->reconcile_attempts);
    }

    // ------------------------------------------------------------------
    // Issue #314 -- o caminho local olha o que ja esta la antes de gravar
    // ------------------------------------------------------------------

    /**
     * Issue #314, guarda de SAIDA. INVERTIDO: antes media que o veredito
     * automatico sobrescrevia o veredito manual da banca.
     *
     * `AutoJudgeService::recordVerdict()` gravava sem checar status, sem
     * `lockForUpdate` e sem token de claim. Um julgamento que comecou antes
     * sobrescrevia o veredito que a banca deu a mao enquanto ele rodava --
     * e `verified_at`/`judge_id` sobreviviam, entao a run passava a exibir
     * um veredito automatico assinado por quem nunca o viu.
     *
     * A guarda nao e nova: e a do `ResultController` (#123), que rele a
     * linha sob lock e recusa o que nao esta mais em `judging`. Ela so nao
     * estava no caminho local. Agora esta no ponto por onde todo julgamento
     * passa (#87), e o relatorio atrasado -- local ou HTTP -- encontra a
     * mesma recusa.
     */
    public function test_veredito_local_nao_sobrescreve_veredito_manual_do_juri(): void
    {
        $run = $this->pendingRun();
        app(JudgeWorkQueue::class)->claimNextLocally();
        $run->refresh();
        $this->assertSame('judging', $run->status);

        $juri = User::factory()->create(['contest_id' => $this->contest->id, 'user_type' => 'judge']);
        $wa = Answer::where('contest_id', $this->contest->id)->where('short_name', 'WA')->first();

        $run->update([
            'status' => 'judged',
            'answer_id' => $wa->id,
            'judge_id' => $juri->user_id,
            'judged_time' => 120,
            'verified_at' => now(),
            'verified_by' => $juri->user_id,
        ]);

        app(AutoJudgeService::class)->recordVerdict($run->fresh(), [
            'verdict' => 'AC',
            'message' => 'Accepted',
        ]);

        $run->refresh();
        $this->assertSame('WA', $run->answer->short_name, 'o veredito da banca foi sobrescrito por um julgamento automatico');
        $this->assertNotNull($run->verified_at);
        $this->assertSame($juri->user_id, $run->judge_id);
        $this->assertSame($juri->user_id, $run->verified_by);
    }

    /**
     * Issue #314, a guarda de SAIDA pelo outro caminho de escrita.
     *
     * `handleJudgingError()` grava `CS` quando o julgamento explode, e
     * gravava com a mesma liberdade que o `recordVerdict()` tinha. A perda
     * e identica -- e por um caminho que nem sequer apurou nada: o veredito
     * da banca some e e trocado por "erro de julgamento".
     *
     * A interleaving e reproduzida no lugar exato em que ela acontece: a
     * banca decide DURANTE o `executeJudging()`, e a falha da maquina chega
     * depois. Nada aqui depende de temporizacao; e a ordem das chamadas.
     */
    public function test_erro_de_julgamento_nao_sobrescreve_veredito_manual_do_juri(): void
    {
        $run = $this->pendingRun();
        app(JudgeWorkQueue::class)->claimNextLocally();
        $run->refresh();
        $this->assertSame('judging', $run->status);

        $juri = User::factory()->create(['contest_id' => $this->contest->id, 'user_type' => 'judge']);
        $wa = Answer::where('contest_id', $this->contest->id)->where('short_name', 'WA')->first();

        $service = new class($juri, $wa) extends AutoJudgeService
        {
            public function __construct(private User $juri, private Answer $wa)
            {
                parent::__construct();
            }

            protected function executeJudging(Run $run): array
            {
                // A banca julga a mao enquanto este julgamento roda.
                $run->update([
                    'status' => 'judged',
                    'answer_id' => $this->wa->id,
                    'judge_id' => $this->juri->user_id,
                    'judged_time' => 120,
                    'verified_at' => now(),
                    'verified_by' => $this->juri->user_id,
                ]);

                throw new \RuntimeException('a maquina de julgamento caiu no meio');
            }
        };

        $service->judge($run);

        $run->refresh();
        $this->assertSame('WA', $run->answer->short_name, 'o veredito da banca virou CS por uma falha de infraestrutura');
        $this->assertSame($juri->user_id, $run->judge_id);
        $this->assertNotNull($run->verified_at);
    }

    /**
     * CONTROLE POSITIVO da guarda de saida, e o que impede que ela vire
     * "nunca grava": a run que AINDA esta em `judging` -- o estado em que
     * todo julgamento legitimo termina -- recebe o veredito normalmente.
     */
    public function test_controle_positivo_veredito_local_grava_em_run_ainda_em_julgamento(): void
    {
        $run = $this->pendingRun();
        app(JudgeWorkQueue::class)->claimNextLocally();
        $run->refresh();
        $this->assertSame('judging', $run->status);

        app(AutoJudgeService::class)->recordVerdict($run, [
            'verdict' => 'AC',
            'message' => 'Accepted',
        ]);

        $run->refresh();
        $this->assertSame('judged', $run->status);
        $this->assertSame('AC', $run->answer->short_name);
    }

    // ------------------------------------------------------------------

    private function pendingRun(): Run
    {
        $team = User::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_type' => 'team',
        ]);

        return Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
            'status' => 'pending',
            'answer_id' => null,
        ]);
    }

    /**
     * @return array{service: AutoJudgeService, calls: object}
     */
    private function countingJudge(): array
    {
        $calls = new class
        {
            public int $count = 0;
        };

        $service = new class($calls) extends AutoJudgeService
        {
            public function __construct(private object $calls)
            {
                parent::__construct();
            }

            public function judge(Run $run): void
            {
                $this->calls->count++;
            }
        };

        return ['service' => $service, 'calls' => $calls];
    }

    private function permissivePreflight(): SandboxPreflight
    {
        return new class extends SandboxPreflight
        {
            public function blocker(): ?array
            {
                return null;
            }
        };
    }
}
