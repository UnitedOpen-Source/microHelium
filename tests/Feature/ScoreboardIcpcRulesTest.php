<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\ContestTimeAdjustment;
use App\Models\Leaderboard;
use App\Models\Problem;
use App\Models\Rejudging;
use App\Models\RejudgingRun;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Services\ContestClock;
use App\Services\ContestScoreRecomputer;
use App\Services\RejudgingService;
use Helium\User;
use Tests\TestCase;

/**
 * As regras de pontuacao da Maratona de Programacao (SBC/ICPC), escritas
 * como prova inteira e nao como asserção sobre uma funcao isolada.
 *
 * O texto normativo que estes testes seguem:
 *
 *   "Teams are ranked according to the most problems solved. Teams who
 *    solve the same number of problems are ranked first by least total
 *    time [...] The time consumed for a solved problem is the time elapsed
 *    from the beginning of the contest to the submittal of the first
 *    accepted run plus 20 penalty minutes for every previously rejected
 *    run for that problem. There is no time consumed for a problem that is
 *    not solved."
 *
 * Por que uma prova inteira e nao uma unidade: a regra so aparece no
 * cenario. `Score::reduceCell()` e pura e ja e facil de testar sozinha; o
 * que ninguem testava era a composicao -- celula, leaderboard,
 * `ScoreboardRanking` e o relogio da sede juntos --, e e nessa composicao
 * que um erro de placar vive sem ser notado ate a premiacao.
 *
 * Cada teste abaixo foi verificado por mutacao: a mutacao que ele deveria
 * pegar esta escrita no docblock, e foi executada. Teste verde contra
 * mecanismo que nao pode funcionar e o modo de falha catalogado deste
 * repositorio.
 */
class ScoreboardIcpcRulesTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Answer $ac;

    private Answer $wa;

    private Answer $ce;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create([
            'is_active' => true,
            'is_practice' => false,
            'is_public' => true,
            'start_time' => now()->subMinutes(200),
            'duration' => 300,
            'freeze_time' => 0,
            'penalty' => 20,
        ]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->ac = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'AC', 'is_accepted' => true]);
        $this->wa = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'WA', 'is_accepted' => false]);
        $this->ce = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'CE', 'is_accepted' => false]);
    }

    private function team(string $name, ?Site $site = null): User
    {
        return $this->createTestUser([
            'fullname' => $name,
            'contest_id' => $this->contest->id,
            'site_id' => ($site ?? $this->site)->id,
        ]);
    }

    private function problem(string $shortName): Problem
    {
        return Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => $shortName]);
    }

    /** Um envio no minuto $minute da prova, ja julgado com $answer. */
    private function submit(User $team, Problem $problem, int $minute, ?Answer $answer, ?Site $site = null): Run
    {
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => ($site ?? $this->site)->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'contest_time' => $minute * 60,
            'status' => $answer ? 'judged' : 'pending',
            'answer_id' => $answer?->id,
            'judged_time' => $answer ? $minute * 60 : null,
        ]);

        Score::updateScore($run);

        return $run;
    }

    private function cell(User $team, Problem $problem): Score
    {
        return Score::where('contest_id', $this->contest->id)
            ->where('user_id', $team->user_id)
            ->where('problem_id', $problem->id)
            ->firstOrFail();
    }

    private function standing(User $team): Leaderboard
    {
        return Leaderboard::where('contest_id', $this->contest->id)
            ->where('user_id', $team->user_id)
            ->firstOrFail();
    }

    /**
     * @return array<string, array{rank: int, solved: int, time: int}>
     */
    private function board(bool $frozen = false): array
    {
        $out = [];

        foreach (Leaderboard::getScoreboard($this->contest->id, $frozen) as $row) {
            $out[$row['user']->fullname] = [
                'rank' => (int) $row['rank'],
                'solved' => (int) $row['problems_solved'],
                'time' => (int) $row['total_time'],
            ];
        }

        return $out;
    }

    // -- penalidade --------------------------------------------------------

    /**
     * A conta da ICPC inteira, num problema so: duas erradas antes da
     * aceita custam 20 minutos cada, e a aceita traz o minuto dela.
     *
     * Mutacao que este teste pega, executada:
     * `Score::reduceCell()`, `($attempts - 1) * $penalty` -> `$attempts * $penalty`
     * => penalidade 60 em vez de 40, total 90 em vez de 70. Falhou, como tem
     * que falhar: essa e exatamente a diferenca entre penalizar a submissao
     * aceita ou nao.
     */
    public function test_a_penalidade_e_vinte_minutos_por_tentativa_anterior_a_aceita(): void
    {
        $problem = $this->problem('A');
        $team = $this->team('Equipe A');

        $this->submit($team, $problem, 10, $this->wa);
        $this->submit($team, $problem, 20, $this->wa);
        $this->submit($team, $problem, 30, $this->ac);

        $cell = $this->cell($team, $problem);

        $this->assertTrue((bool) $cell->is_solved);
        $this->assertSame(30, (int) $cell->solved_time, 'o tempo e o minuto da aceita');
        $this->assertSame(40, (int) $cell->penalty_time, 'duas erradas antes da aceita, 20 minutos cada');
        $this->assertSame(70, (int) $this->standing($team)->total_time);
    }

    /**
     * "There is no time consumed for a problem that is not solved."
     *
     * Mutacao que este teste pega, executada: no retorno final de
     * `Score::reduceCell()` (o caminho nao resolvido),
     * `'penalty_time' => 0` -> `'penalty_time' => $attempts * $penalty`
     * => a equipe passa a dever 60 minutos por um problema que nao
     * resolveu, e o `total_time` do leaderboard sobe com ela. Falhou.
     */
    public function test_problema_nunca_resolvido_nao_gera_penalidade(): void
    {
        $solved = $this->problem('A');
        $never = $this->problem('B');
        $team = $this->team('Equipe A');

        $this->submit($team, $solved, 30, $this->ac);
        $this->submit($team, $never, 10, $this->wa);
        $this->submit($team, $never, 20, $this->wa);
        $this->submit($team, $never, 25, $this->wa);

        $cell = $this->cell($team, $never);

        $this->assertFalse((bool) $cell->is_solved);
        $this->assertSame(3, (int) $cell->attempts, 'as tentativas sao contadas; e o que a tela mostra');
        $this->assertSame(0, (int) $cell->penalty_time);
        $this->assertSame(0, (int) $cell->solved_time);

        $standing = $this->standing($team);
        $this->assertSame(1, (int) $standing->problems_solved);
        $this->assertSame(30, (int) $standing->total_time, 'so o problema resolvido entra na conta');
    }

    /**
     * Uma equipe que submete de novo depois de ter passado -- por engano, ou
     * porque nao viu o veredito -- nao paga por isso.
     *
     * Mutacao que este teste pega, executada: em `Score::reduceCell()`,
     * trocar o `return` de dentro do laco por guardar o resultado e
     * continuar iterando => attempts 4 e penalidade 60. Falhou.
     */
    public function test_submissao_errada_depois_da_aceita_nao_conta(): void
    {
        $problem = $this->problem('A');
        $team = $this->team('Equipe A');

        $this->submit($team, $problem, 10, $this->wa);
        $this->submit($team, $problem, 20, $this->wa);
        $this->submit($team, $problem, 30, $this->ac);
        $this->submit($team, $problem, 40, $this->wa);
        $this->submit($team, $problem, 50, $this->wa);

        $cell = $this->cell($team, $problem);

        $this->assertSame(3, (int) $cell->attempts, 'a contagem para na aceita');
        $this->assertSame(30, (int) $cell->solved_time);
        $this->assertSame(40, (int) $cell->penalty_time);
    }

    /**
     * O erro de compilacao conta como tentativa incorreta neste sistema.
     *
     * Isto NAO e uma regra derivada da ICPC -- e uma politica, e ela nao
     * esta registrada em spec nenhuma nem e configuravel: a unica coisa que
     * decide o assunto e `answers.is_accepted = false` em
     * `Answer::getDefaultAnswers()`. O teste existe para que mudar a
     * politica seja uma decisao e nao um efeito colateral. Ver a issue
     * aberta sobre o assunto.
     *
     * Mutacao que este teste pega, executada: em
     * `Run::scopeCountingTowardsScore()`, acrescentar
     * `->whereDoesntHave('answer', fn ($q) => $q->where('short_name', 'CE'))`
     * => attempts 1 e penalidade 0. Falhou, que e o que tem que acontecer
     * no dia em que alguem implementar a politica oposta.
     *
     * Vale registrar por que a mutacao foi feita no escopo e nao dentro de
     * `Score::reduceCell()`: tentar pular ali por `short_name` NAO derruba
     * teste nenhum, porque os dois chamadores carregam a relacao como
     * `answer:id,is_accepted` -- a reducao nao tem como saber qual veredito
     * ela esta contando, so se ele aceita ou nao. Ou seja, "CE nao conta"
     * nao e um `if` que falta na reducao; e uma decisao que teria que
     * mudar quais runs chegam ate ela.
     */
    public function test_erro_de_compilacao_conta_como_tentativa_incorreta(): void
    {
        $problem = $this->problem('A');
        $team = $this->team('Equipe A');

        $this->submit($team, $problem, 10, $this->ce);
        $this->submit($team, $problem, 30, $this->ac);

        $cell = $this->cell($team, $problem);

        $this->assertSame(2, (int) $cell->attempts);
        $this->assertSame(30, (int) $cell->solved_time);
        $this->assertSame(20, (int) $cell->penalty_time, 'hoje o CE custa uma penalidade inteira');
    }

    // -- tempo por sede ----------------------------------------------------

    /**
     * Issue #198/#276 -- a penalidade e paga no relogio da SEDE da equipe.
     *
     * Duas equipes submetem a aceita no mesmo minuto de parede. A sede de
     * uma delas perdeu meia hora; essa meia hora nao pode aparecer na conta
     * dela, e e o que decide qual das duas fica na frente.
     *
     * Mutacao que este teste pega, executada: em
     * `Score::adjustedSecondsResolver()`, trocar `$run->site_id` por `null`
     * (o relogio global) => as duas equipes marcam 100 minutos, empatam, e
     * a asserção de ordem cai. Falhou. Esta e a mutacao que importa: com o
     * relogio global, a sede que perdeu tempo e penalizada por te-lo
     * perdido.
     */
    public function test_a_penalidade_usa_o_relogio_da_sede_da_equipe(): void
    {
        $other = Site::factory()->create(['contest_id' => $this->contest->id]);

        ContestTimeAdjustment::create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'starts_at' => $this->contest->start_time->copy()->addMinutes(30),
            'ends_at' => $this->contest->start_time->copy()->addMinutes(60),
            'reason' => 'queda de energia na sede',
        ]);

        app(ContestClock::class)->forget();

        $problem = $this->problem('A');
        $affected = $this->team('Sede com queda', $this->site);
        $normal = $this->team('Sede normal', $other);

        $this->submit($affected, $problem, 100, $this->ac, $this->site);
        $this->submit($normal, $problem, 100, $this->ac, $other);

        $this->assertSame(70, (int) $this->cell($affected, $problem)->solved_time, 'os 30 minutos removidos saem da conta desta sede');
        $this->assertSame(100, (int) $this->cell($normal, $problem)->solved_time, 'a outra sede nao e tocada');

        $board = $this->board();
        $this->assertSame(1, $board['Sede com queda']['rank']);
        $this->assertSame(2, $board['Sede normal']['rank']);
    }

    // -- rejulgamento ------------------------------------------------------

    /**
     * Issue #192 -- um AC que vira WA num rejulgamento devolve exatamente a
     * penalidade certa, e a aceita seguinte assume o lugar dela.
     *
     * O cenario e o caro: WA aos 10, AC aos 20 (a equipe fica com 20+20=40),
     * e uma terceira submissao aceita aos 50. Quando o rejulgamento derruba
     * a aceita dos 20, a celula tem que virar "resolvido aos 50, com duas
     * erradas antes" -- 50+40=90 -- e nao alguma soma acumulada do estado
     * anterior.
     *
     * Passa pelo `RejudgingService::apply()` de verdade, e nao por um
     * `Score::updateScore()` a mao: o que se quer provar e que o caminho
     * em lote recompoe o placar, nao que a reducao pura funciona.
     *
     * Mutacao que este teste pega, executada: em `RejudgingService`,
     * remover a chamada a `recomputeAffectedCells($members)` no fim do
     * `apply()` => o veredito muda no run e o placar continua dizendo
     * "resolvido aos 20, penalidade 20". Falhou. E e o defeito silencioso
     * exato: a tela do juiz mostra o veredito novo e o placar mostra o
     * antigo.
     */
    public function test_rejulgar_a_aceita_para_errada_devolve_a_penalidade_certa(): void
    {
        $problem = $this->problem('A');
        $team = $this->team('Equipe A');

        $this->submit($team, $problem, 10, $this->wa);
        $accepted = $this->submit($team, $problem, 20, $this->ac);
        $this->submit($team, $problem, 50, $this->ac);

        $before = $this->cell($team, $problem);
        $this->assertSame(20, (int) $before->solved_time, 'antes do rejulgamento, a primeira aceita manda');
        $this->assertSame(20, (int) $before->penalty_time);
        $this->assertSame(40, (int) $this->standing($team)->total_time);

        $rejudging = Rejudging::create([
            'contest_id' => $this->contest->id,
            'reason' => 'caso de teste 7 estava errado',
            'filters' => ['run_ids' => [$accepted->id]],
            'include_accepted' => true,
            'status' => Rejudging::STATUS_READY,
        ]);

        RejudgingRun::create([
            'rejudging_id' => $rejudging->id,
            'run_id' => $accepted->id,
            'old_answer_id' => $this->ac->id,
            'old_status' => 'judged',
            'old_judged_time' => $accepted->judged_time,
            'new_answer_id' => $this->wa->id,
            'judged_at' => now(),
        ]);

        app(RejudgingService::class)->apply($rejudging->fresh(), null);

        $after = $this->cell($team, $problem);
        $this->assertTrue((bool) $after->is_solved, 'a terceira submissao ainda resolve o problema');
        $this->assertSame(50, (int) $after->solved_time, 'a aceita que sobrou e a dos 50');
        $this->assertSame(40, (int) $after->penalty_time, 'agora sao duas erradas antes dela');
        $this->assertSame(3, (int) $after->attempts);
        $this->assertSame(90, (int) $this->standing($team)->total_time);
    }

    /**
     * O rejulgamento que tira a unica aceita tira o problema da equipe --
     * resolvidos e tempo voltam ao que eram antes de ela resolver.
     *
     * Mutacao que este teste pega, executada: em
     * `Leaderboard::updateForUser()`, remover o `where('is_solved', true)`
     * => a equipe continua com 1 resolvido e com tempo depois de perder a
     * aceita. Falhou.
     */
    public function test_perder_a_unica_aceita_devolve_a_equipe_ao_zero(): void
    {
        $problem = $this->problem('A');
        $team = $this->team('Equipe A');

        $this->submit($team, $problem, 10, $this->wa);
        $accepted = $this->submit($team, $problem, 20, $this->ac);

        $this->assertSame(1, (int) $this->standing($team)->problems_solved);

        $accepted->update(['answer_id' => $this->wa->id]);
        Score::updateScore($accepted->fresh());

        $standing = $this->standing($team);
        $this->assertSame(0, (int) $standing->problems_solved);
        $this->assertSame(0, (int) $standing->total_time);
        $this->assertFalse((bool) $this->cell($team, $problem)->is_solved);
        $this->assertSame(0, (int) $this->cell($team, $problem)->penalty_time);
    }

    // -- congelamento ------------------------------------------------------

    /**
     * Issue #211 -- descongelar tem que devolver o placar que sempre esteve
     * la, e nao um placar reconstruido.
     *
     * O caso em que um erro aqui e invisivel ate a premiacao: o placar
     * congelado esconde, a organizacao descongela, e ninguem tem como
     * conferir se o que apareceu e o que teria aparecido sem congelamento
     * nenhum -- porque o congelamento ja acabou.
     *
     * A prova e feita com um veredito que chega FORA DE ORDEM (a aceita de
     * uma equipe e julgada depois da de outra que submeteu mais tarde), que
     * e o caso em que uma contagem acumulada divergiria.
     *
     * Mutacao que este teste pega, executada: em `Score::recomputeFor()`,
     * remover a chamada a `Leaderboard::updateForUser()` => o placar servido
     * depois do descongelamento fica com os numeros de antes do ultimo
     * veredito e a comparacao com a recomposicao do zero cai. Falhou.
     */
    public function test_descongelar_devolve_o_mesmo_placar_que_nunca_ter_congelado(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(270), 'freeze_time' => 60]);
        $this->contest->refresh();

        $a = $this->problem('A');
        $b = $this->problem('B');
        $early = $this->team('Equipe cedo');
        $late = $this->team('Equipe tarde');

        // A aceita de 'Equipe cedo' chega aos 250 mas so e julgada depois da
        // de 'Equipe tarde', aos 260.
        $pending = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $early->user_id,
            'problem_id' => $a->id,
            'contest_time' => 250 * 60,
            'status' => 'pending',
            'answer_id' => null,
        ]);

        $this->submit($late, $a, 260, $this->ac);
        $this->submit($late, $b, 100, $this->wa);

        $pending->update(['status' => 'judged', 'answer_id' => $this->ac->id, 'judged_time' => 265 * 60]);
        Score::updateScore($pending->fresh());

        $this->assertTrue($this->contest->fresh()->isFrozen(), 'o cenario depende de o contest estar congelado');

        $frozen = $this->board(true);
        $this->assertSame(0, $frozen['Equipe cedo']['solved'], 'o congelamento esconde as duas aceitas');
        $this->assertSame(0, $frozen['Equipe tarde']['solved']);

        $this->contest->update(['unfrozen_at' => now()]);
        $this->contest->refresh();
        $this->assertFalse($this->contest->isFrozen());

        $revealed = $this->board();

        // A referencia: o mesmo placar recomposto do zero a partir dos runs,
        // sem passar por nenhum estado intermediario.
        app(ContestScoreRecomputer::class)->recompute($this->contest);
        $recomputed = $this->board();

        $this->assertSame($recomputed, $revealed, 'o placar revelado tem que ser o placar recomposto do zero');
        $this->assertSame(1, $revealed['Equipe cedo']['solved']);
        $this->assertSame(250, $revealed['Equipe cedo']['time']);
        $this->assertSame(260, $revealed['Equipe tarde']['time']);
        $this->assertSame(1, $revealed['Equipe cedo']['rank'], 'quem resolveu antes fica na frente depois da revelacao');
        $this->assertSame(2, $revealed['Equipe tarde']['rank']);
    }
}
