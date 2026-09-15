<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Judgehost;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Issue #199 -- quando o julgamento para, alguem precisa ser avisado.
 *
 * Os testes aqui cobrem duas coisas diferentes e igualmente importantes:
 * que a condicao e detectada, e que ela NAO vira aviso antes da hora. A
 * segunda metade e a que decide se o canal serve para alguma coisa: um
 * alerta que dispara quando alguem reinicia um judgehost no intervalo e um
 * alerta que a organizacao silencia, e um canal silenciado nao carrega o
 * problema seguinte.
 */
class JudgingAlertsCommandTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Cache::flush();

        $this->contest = Contest::factory()->create([
            'is_active' => true,
            'is_practice' => false,
            'start_time' => now()->subHour(),
            'duration' => 300,
        ]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);

        config([
            'judging.alerts.enabled' => true,
            'judging.alerts.webhook.url' => 'https://alerts.example/hook',
            'judging.alerts.webhook.secret' => '',
            'judging.alerts.dwell_minutes.default' => 5,
            'judging.alerts.dwell_minutes.no_judgehost' => 2,
            'judging.alerts.repeat_after_minutes' => 60,
            'judging.alerts.judgehost_silence_seconds' => 120,
            'judging.alerts.queue_depth' => 3,
            'judging.alerts.give_back_repeats' => 3,
        ]);
    }

    private function alerts(): array
    {
        $this->artisan('judging:alerts')->assertSuccessful();

        return ContestLog::where('contest_id', $this->contest->id)
            ->get()
            ->filter(fn (ContestLog $log) => ($log->context['event'] ?? null) === 'judging_alert')
            ->values()
            ->all();
    }

    private function pendingRuns(int $count): void
    {
        $problem = Problem::factory()->create(['contest_id' => $this->contest->id]);
        $user = $this->createTestUser(['contest_id' => $this->contest->id, 'site_id' => $this->site->id]);

        Run::factory()->count($count)->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'problem_id' => $problem->id,
            'user_id' => $user->user_id,
            'status' => 'pending',
        ]);
    }

    // -- histerese ---------------------------------------------------------

    public function test_a_condition_does_not_alert_before_its_dwell(): void
    {
        $this->pendingRuns(10);

        $this->assertSame([], $this->alerts(), 'a fila represada virou aviso no primeiro tique');
        Http::assertNothingSent();
    }

    /**
     * O tique seguinte, ainda dentro do dwell, tambem tem que ficar quieto.
     *
     * Este teste existe separado do anterior porque os dois passam por
     * caminhos diferentes: no primeiro tique nao ha estado guardado e a
     * funcao sai antes de comparar tempo nenhum; a partir do segundo e a
     * comparacao com `since` que segura. Sem este, a comparacao podia ser
     * apagada inteira e a suite continuava verde -- foi o que uma mutacao
     * mostrou.
     */
    public function test_a_condition_does_not_alert_on_later_ticks_still_inside_the_dwell(): void
    {
        $this->pendingRuns(10);
        $this->alerts();

        $this->travel(2)->minutes();

        $this->assertSame([], $this->alerts(), 'avisou antes de a condicao se sustentar pelo dwell');
    }

    public function test_a_condition_alerts_once_the_dwell_has_passed(): void
    {
        $this->pendingRuns(10);
        $this->alerts();

        $this->travel(6)->minutes();

        $alerts = $this->alerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('queue_backlog', $alerts[0]->context['condition']);
        $this->assertSame('triggered', $alerts[0]->context['status']);
    }

    /**
     * A prova dura cinco horas. Se o aviso se repete a cada minuto enquanto
     * a fila esta represada, sao trezentos avisos, e o tricentesimo nao
     * informa nada que o primeiro ja nao tenha dito.
     */
    public function test_a_condition_that_keeps_holding_is_not_repeated_within_the_cooldown(): void
    {
        $this->pendingRuns(10);
        $this->alerts();
        $this->travel(6)->minutes();
        $this->alerts();

        $this->travel(30)->minutes();

        $this->assertCount(1, $this->alerts(), 'o aviso se repetiu dentro do cooldown');
    }

    public function test_a_condition_that_keeps_holding_is_repeated_after_the_cooldown(): void
    {
        $this->pendingRuns(10);
        $this->alerts();
        $this->travel(6)->minutes();
        $this->alerts();

        $this->travel(61)->minutes();

        $alerts = $this->alerts();

        $this->assertCount(2, $alerts);
        $this->assertSame('reminder', $alerts[1]->context['status']);
    }

    /**
     * Sem isto a organizacao tem que ficar conferindo uma tela para
     * descobrir se o que lhe contaram ainda vale -- que e exatamente a
     * situacao que esta issue existe para acabar.
     */
    public function test_recovery_is_announced(): void
    {
        $this->pendingRuns(10);
        $this->alerts();
        $this->travel(6)->minutes();
        $this->alerts();

        Run::where('contest_id', $this->contest->id)->update(['status' => 'judged']);

        $alerts = $this->alerts();

        $this->assertCount(2, $alerts);
        $this->assertSame('resolved', $alerts[1]->context['status']);
    }

    /**
     * Uma condicao que nunca passou do dwell nunca foi problema de
     * ninguem, e anunciar que ela "se resolveu" seria contar sobre algo que
     * nunca foi contado.
     */
    public function test_a_condition_that_never_alerted_does_not_announce_a_recovery(): void
    {
        $this->pendingRuns(10);
        $this->alerts();

        Run::where('contest_id', $this->contest->id)->update(['status' => 'judged']);

        $this->assertSame([], $this->alerts());
    }

    // -- condicoes ---------------------------------------------------------

    public function test_every_enabled_judgehost_gone_silent_alerts(): void
    {
        Judgehost::create([
            'name' => 'judge-1',
            'token_hash' => str_repeat('a', 64),
            'enabled' => true,
            'last_seen_at' => now()->subMinutes(10),
        ]);

        $this->alerts();
        $this->travel(3)->minutes();

        $alerts = $this->alerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('no_judgehost', $alerts[0]->context['condition']);
    }

    /**
     * Uma instalacao que julga pela fila local nao tem judgehost nenhum
     * cadastrado. Dizer a ela que nenhuma maquina respondeu seria um alarme
     * falso permanente -- a maneira mais rapida de ensinar uma organizacao
     * a ignorar o canal.
     */
    public function test_an_installation_with_no_judgehosts_is_not_told_they_are_silent(): void
    {
        $this->alerts();
        $this->travel(10)->minutes();

        $this->assertSame([], $this->alerts());
    }

    public function test_one_judgehost_still_answering_is_enough(): void
    {
        Judgehost::create(['name' => 'j-dead', 'token_hash' => str_repeat('a', 64), 'enabled' => true, 'last_seen_at' => now()->subMinutes(10)]);
        Judgehost::create(['name' => 'j-alive', 'token_hash' => str_repeat('b', 64), 'enabled' => true, 'last_seen_at' => now()]);

        $this->alerts();
        $this->travel(3)->minutes();

        $this->assertSame([], $this->alerts());
    }

    public function test_a_run_given_back_repeatedly_for_the_same_reason_alerts(): void
    {
        $problem = Problem::factory()->create(['contest_id' => $this->contest->id]);
        $user = $this->createTestUser(['contest_id' => $this->contest->id, 'site_id' => $this->site->id]);
        Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'problem_id' => $problem->id,
            'user_id' => $user->user_id,
            'status' => 'pending',
            'give_back_reason' => 'compile_timeout',
            'give_back_count' => 4,
        ]);

        $alerts = $this->alerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('give_back_loop', $alerts[0]->context['condition']);
        $this->assertSame('compile_timeout', $alerts[0]->context['give_back_reason']);
    }

    /**
     * Uma devolucao ou duas e a vida normal de uma prova: uma maquina
     * reiniciou, um lease expirou. O alerta e sobre a MESMA falha voltando,
     * e nao sobre haver devolucao.
     */
    public function test_a_run_given_back_fewer_times_than_the_threshold_is_not_an_alert(): void
    {
        $problem = Problem::factory()->create(['contest_id' => $this->contest->id]);
        $user = $this->createTestUser(['contest_id' => $this->contest->id, 'site_id' => $this->site->id]);
        Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'problem_id' => $problem->id,
            'user_id' => $user->user_id,
            'status' => 'pending',
            'give_back_reason' => 'lease_expired',
            'give_back_count' => 1,
        ]);

        $this->alerts();
        $this->travel(10)->minutes();

        $this->assertSame([], $this->alerts());
    }

    /**
     * A coluna que registra a devolucao nunca volta atras, entao um aviso
     * repetivel se anunciaria ate o fim da prova.
     */
    public function test_the_give_back_loop_is_announced_once_and_not_again(): void
    {
        $problem = Problem::factory()->create(['contest_id' => $this->contest->id]);
        $user = $this->createTestUser(['contest_id' => $this->contest->id, 'site_id' => $this->site->id]);
        Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'problem_id' => $problem->id,
            'user_id' => $user->user_id,
            'status' => 'pending',
            'give_back_reason' => 'compile_timeout',
            'give_back_count' => 4,
        ]);

        $this->alerts();
        $this->travel(120)->minutes();

        $this->assertCount(1, $this->alerts());
    }

    public function test_the_watchdog_giving_up_alerts(): void
    {
        $problem = Problem::factory()->create(['contest_id' => $this->contest->id]);
        $user = $this->createTestUser(['contest_id' => $this->contest->id, 'site_id' => $this->site->id]);
        $cs = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'CS']);

        Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'problem_id' => $problem->id,
            'user_id' => $user->user_id,
            'status' => 'judged',
            'answer_id' => $cs->id,
            'reconcile_attempts' => 1,
        ]);

        $alerts = $this->alerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('watchdog_gave_up', $alerts[0]->context['condition']);
        $this->assertSame(1, $alerts[0]->context['given_up']);
    }

    /**
     * Um run julgado normalmente depois de UMA retentativa e um run
     * recuperado -- o watchdog funcionou. Confundir os dois transformaria
     * cada recuperacao bem-sucedida num alarme.
     */
    public function test_a_run_recovered_by_the_watchdog_is_not_an_alert(): void
    {
        $problem = Problem::factory()->create(['contest_id' => $this->contest->id]);
        $user = $this->createTestUser(['contest_id' => $this->contest->id, 'site_id' => $this->site->id]);
        $accepted = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);

        Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'problem_id' => $problem->id,
            'user_id' => $user->user_id,
            'status' => 'judged',
            'answer_id' => $accepted->id,
            'reconcile_attempts' => 1,
        ]);

        $this->assertSame([], $this->alerts());
    }

    // -- entrega -----------------------------------------------------------

    public function test_the_webhook_carries_the_alert(): void
    {
        $this->pendingRuns(10);
        $this->alerts();
        $this->travel(6)->minutes();
        $this->alerts();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://alerts.example/hook'
                && $body['event'] === 'triggered'
                && $body['alert']['condition'] === 'queue_backlog'
                && $body['contest']['id'] === $this->contest->id;
        });
    }

    /**
     * Um endpoint que aceita qualquer POST e um endpoint que qualquer um
     * usa para dizer que o julgamento parou.
     */
    public function test_the_webhook_is_signed_when_a_secret_is_configured(): void
    {
        config(['judging.alerts.webhook.secret' => 'segredo']);

        $this->pendingRuns(10);
        $this->alerts();
        $this->travel(6)->minutes();
        $this->alerts();

        Http::assertSent(function ($request) {
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), 'segredo');

            return $request->hasHeader('X-Microhelium-Signature', $expected);
        });
    }

    /**
     * Isto roda no agendador, ao lado do watchdog que recupera run travado.
     * Um servidor de chat fora do ar nao pode derrubar o watchdog junto.
     */
    public function test_an_unreachable_webhook_does_not_fail_the_command(): void
    {
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $this->pendingRuns(10);
        $this->artisan('judging:alerts')->assertSuccessful();
        $this->travel(6)->minutes();
        $this->artisan('judging:alerts')->assertSuccessful();

        $this->assertCount(1, $this->alerts(), 'o aviso nao chegou ao log quando o webhook caiu');
    }

    public function test_no_webhook_configured_still_writes_the_contest_log(): void
    {
        config(['judging.alerts.webhook.url' => '']);

        $this->pendingRuns(10);
        $this->alerts();
        $this->travel(6)->minutes();

        $this->assertCount(1, $this->alerts());
        Http::assertNothingSent();
    }

    // -- escopo ------------------------------------------------------------

    /**
     * Um contest do ano passado esquecido com is_active = true dispararia
     * todo dia, para sempre, e um canal que avisa todo dia e um canal que
     * ninguem le no dia em que importa.
     */
    public function test_a_finished_contest_with_nothing_pending_is_not_evaluated(): void
    {
        Judgehost::create(['name' => 'j', 'token_hash' => str_repeat('a', 64), 'enabled' => true, 'last_seen_at' => now()->subYear()]);
        $this->contest->update(['start_time' => now()->subYear(), 'duration' => 300]);

        $this->alerts();
        $this->travel(10)->minutes();

        $this->assertSame([], $this->alerts());
    }

    /**
     * Mas um envio feito no ultimo minuto ainda precisa ser julgado depois
     * que o relogio zera.
     */
    public function test_a_finished_contest_with_work_still_pending_is_evaluated(): void
    {
        $this->pendingRuns(10);
        $this->contest->update(['start_time' => now()->subYear(), 'duration' => 300]);

        $this->alerts();
        $this->travel(6)->minutes();

        $this->assertCount(1, $this->alerts());
    }

    public function test_the_whole_thing_can_be_turned_off(): void
    {
        config(['judging.alerts.enabled' => false]);

        $this->pendingRuns(10);
        $this->alerts();
        $this->travel(6)->minutes();

        $this->assertSame([], $this->alerts());
        Http::assertNothingSent();
    }
}
