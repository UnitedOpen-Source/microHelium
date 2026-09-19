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
 * TODO TESTE AQUI PASSA DE PROPOSITO, E ISSO NAO E O MESMO QUE "esta
 * certo". Cada `test_janela_*` FIXA uma sequencia de estados que hoje e
 * alcancavel e que nao deveria ser. Quando a guarda correspondente
 * existir, e para ele FALHAR -- a falha e o sinal de que a issue fechou, e
 * a hora de inverter a asercao. Nao "conserte" um destes testes sem fechar
 * a issue que ele cita.
 *
 * Cada janela vem acompanhada do seu CONTROLE POSITIVO: a mesma pergunta
 * feita a um componente que responde certo. Sem ele um teste verde nao
 * distingue "o mecanismo deixou passar" de "o teste nao estava medindo
 * nada" -- que e o modo de falha que este repositorio ja pagou caro.
 *
 * Nada aqui depende do sandbox: sao estados de linha e ordem de chamadas,
 * entao a suite mede o mesmo numa maquina sem bwrap e dentro do
 * Dockerfile.judge. Nenhum destes testes pula.
 *
 * As mutacoes que provam cada um estao escritas na issue correspondente,
 * na secao "Medicao".
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
    // Issue #314 -- o caminho local nao olha para a reivindicacao
    // ------------------------------------------------------------------

    /**
     * Issue #314, guarda de ENTRADA.
     *
     * `JudgeRunJob::handle()` so recusa `status === 'judged'`. Um run em
     * `judging` -- ja reivindicado, sob lock de linha, por um judgehost
     * remoto (`JudgeWorkQueue::claimNext()`) ou pelo daemon local
     * (`claimNextLocally()`, #126) -- passa reto e e julgado de novo.
     *
     * O `ShouldBeUnique` do job nao cobre isto: ele e por dispatch na
     * fila, e nenhum dos dois caminhos de reivindicacao passa pela fila.
     */
    public function test_janela_job_da_fila_julga_run_ja_reivindicado_por_judgehost(): void
    {
        [$host] = Judgehost::issue('judge-01');
        $run = $this->pendingRun();

        $claimed = app(JudgeWorkQueue::class)->claimNext($host);
        $this->assertTrue($claimed->is($run));
        $this->assertSame('judging', $run->fresh()->status);
        $this->assertSame($host->id, (int) $run->fresh()->judgehost_id);

        $julgados = $this->countingJudge();

        (new JudgeRunJob($run->fresh()))->handle($julgados['service'], $this->permissivePreflight());

        $this->assertSame(1, $julgados['calls']->count, 'o job da fila julgou um run que ja estava reivindicado');
    }

    /**
     * CONTROLE POSITIVO do teste acima: a guarda existe e dispara.
     *
     * E o que torna o resultado conclusivo -- nao e o job que esta
     * quebrado, e o conjunto de estados que ele reconhece que esta
     * incompleto.
     */
    public function test_controle_positivo_job_da_fila_recusa_run_ja_julgado(): void
    {
        $run = $this->pendingRun();
        $run->update(['status' => 'judged']);

        $julgados = $this->countingJudge();

        (new JudgeRunJob($run->fresh()))->handle($julgados['service'], $this->permissivePreflight());

        $this->assertSame(0, $julgados['calls']->count, 'a guarda de "ja julgado" existe e funciona');
    }

    // ------------------------------------------------------------------
    // Issue #312 -- o watchdog nao olha para a lease
    // ------------------------------------------------------------------

    /**
     * Issue #312.
     *
     * `Run::isOverdue()` mede `created_at -> now()` e nunca le
     * `claimed_at`, entao um run que um judgehost esta julgando AGORA,
     * com a lease renovada pelo heartbeat (#124), entra no escopo do
     * watchdog e gasta a tentativa.
     *
     * CONTROLE POSITIVO embutido: `expireStaleLeases()` devolve 0 sobre a
     * MESMA linha, no mesmo instante. A informacao que distingue "maquina
     * morta" de "maquina trabalhando" esta na linha e ja e lida
     * corretamente por outro componente.
     */
    public function test_janela_watchdog_redespacha_run_com_lease_viva(): void
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

        Bus::assertDispatched(JudgeRunJob::class, fn ($job) => $job->run->is($run));
        $this->assertSame(1, $run->fresh()->reconcile_attempts);
    }

    /**
     * Issue #312, a consequencia: a equipe recebe `CS` num envio que foi
     * julgado corretamente.
     *
     * Percorre o caminho inteiro por HTTP -- o judgehost busca trabalho,
     * o watchdog desiste, o veredito real chega. O 409 no fim e o segundo
     * CONTROLE POSITIVO: ele prova que o host tinha um claim valido e um
     * veredito para entregar, e que o servidor o recusou.
     */
    public function test_janela_watchdog_marca_cs_julgamento_vivo_e_descarta_o_veredito_real(): void
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
        $this->assertSame('judged', $run->status);
        $this->assertSame('CS', $run->answer->short_name);

        // O veredito de verdade chega logo depois e e recusado.
        $this->postJson("/api/remote-judges/v1/runs/{$run->id}/result", ['verdict' => 'AC'], [
            'Authorization' => 'Bearer '.$token,
            'X-Claim-Token' => $payload['claim_token'],
        ])->assertStatus(409);

        $this->assertSame('CS', $run->fresh()->answer->short_name);
    }

    // ------------------------------------------------------------------
    // Issue #313 -- a tentativa e contada, nao executada
    // ------------------------------------------------------------------

    /**
     * Issue #313.
     *
     * O watchdog incrementa `reconcile_attempts` e chama
     * `JudgeRunJob::dispatch()` sem nunca verificar se alguma coisa foi
     * enfileirada. Com o lock de unicidade tomado -- worker morto por
     * SIGKILL antes de `CallQueuedHandler` libera-lo, ou a segunda
     * passada caindo dentro dos 300 s do `uniqueFor` -- o Laravel
     * descarta o dispatch em silencio, e cinco minutos depois o
     * `giveUp()` encerra o run como `CS`.
     *
     * O lock e tomado aqui com a chave real, `UniqueLock::getKey()`, e
     * nao com uma string escrita a mao: uma chave errada faria este teste
     * passar pelo motivo errado.
     */
    public function test_janela_lock_orfao_engole_o_redespacho_do_watchdog(): void
    {
        Bus::fake();

        $run = $this->pendingRun();
        $run->forceFill(['created_at' => now()->subSeconds(1000)])->save();

        $key = UniqueLock::getKey(new JudgeRunJob($run));
        $this->assertTrue(Cache::lock($key, 300)->get(), 'o lock orfao tem de ser adquirivel');

        $this->artisan('runs:reconcile-stuck')->assertExitCode(0);

        Bus::assertNotDispatched(JudgeRunJob::class);
        $this->assertSame(1, $run->fresh()->reconcile_attempts);
    }

    /**
     * CONTROLE POSITIVO do teste acima: a unica diferenca entre os dois
     * cenarios e o lock. Sem ele, o mesmo run atrasado e redespachado.
     */
    public function test_controle_positivo_sem_lock_o_watchdog_redespacha(): void
    {
        Bus::fake();

        $run = $this->pendingRun();
        $run->forceFill(['created_at' => now()->subSeconds(1000)])->save();

        $this->artisan('runs:reconcile-stuck')->assertExitCode(0);

        Bus::assertDispatched(JudgeRunJob::class);
    }

    // ------------------------------------------------------------------
    // Issue #314 -- o caminho local grava sem olhar o que ja esta la
    // ------------------------------------------------------------------

    /**
     * Issue #314, guarda de SAIDA.
     *
     * `AutoJudgeService::recordVerdict()` grava sem checar status, sem
     * `lockForUpdate` e sem token de claim. Um julgamento que comecou
     * antes sobrescreve o veredito que a banca deu a mao enquanto ele
     * rodava -- e `verified_at`/`judge_id` sobrevivem, entao o run passa
     * a exibir um veredito automatico assinado por quem nunca o viu.
     *
     * O CONTROLE POSITIVO deste esta no
     * `test_janela_watchdog_marca_cs_...` acima: o MESMO relatorio
     * atrasado, chegando pelo `ResultController`, e recusado com 409 sob
     * lock de linha. A guarda existe, e a certa, e esta escrita no
     * docblock daquele controller (#123). So que so no caminho HTTP.
     */
    public function test_janela_veredito_local_sobrescreve_veredito_manual_do_juri(): void
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
        $this->assertSame('AC', $run->answer->short_name);
        $this->assertNotNull($run->verified_at);
        $this->assertSame($juri->user_id, $run->judge_id);
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
