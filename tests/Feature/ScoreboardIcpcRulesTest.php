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
use App\Services\ContestAwards;
use App\Services\ContestClock;
use App\Services\ContestScoreRecomputer;
use App\Services\RejudgingService;
use App\Services\ScoreboardRanking;
use Helium\User;
use Laravel\Sanctum\Sanctum;
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

        // Os vereditos vem do catalogo padrao, e nao de uma factory com
        // valores escolhidos aqui. E uma exigencia dos #321/#322: quem
        // decide se um veredito custa tentativa e
        // `Answer::getDefaultAnswers()`, entao um fixture proprio testaria
        // uma politica que nenhuma prova real usa.
        $this->ac = $this->answer('AC');
        $this->wa = $this->answer('WA');
        $this->ce = $this->answer('CE');
    }

    /** Um veredito deste contest, como o catalogo padrao o define. */
    private function answer(string $shortName): Answer
    {
        $row = collect(Answer::getDefaultAnswers())->firstWhere('short_name', $shortName);

        return Answer::create($row + ['contest_id' => $this->contest->id]);
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
        return $this->submitAtSecond($team, $problem, $minute * 60, $answer, $site);
    }

    /**
     * O mesmo, com o segundo exato.
     *
     * Existe por causa do #317: dois AC no mesmo minuto sao o caso em que a
     * marca de primeiro a resolver tem que ser decidida em segundos, e
     * `submit()` so sabe falar em minutos.
     */
    private function submitAtSecond(User $team, Problem $problem, int $second, ?Answer $answer, ?Site $site = null): Run
    {
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => ($site ?? $this->site)->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'contest_time' => $second,
            'status' => $answer ? 'judged' : 'pending',
            'answer_id' => $answer?->id,
            'judged_time' => $answer ? $second : null,
        ]);

        Score::updateScore($run);

        return $run;
    }

    /**
     * O veredito que a NOSSA infraestrutura escreve quando o julgamento
     * falha, vindo do catalogo padrao e nao de valores escolhidos aqui:
     * o que o #321 conserta e o comportamento de uma prova real, e o
     * catalogo e onde a marca `counts_as_attempt` e decidida.
     */
    private function contactStaff(): Answer
    {
        return $this->answer('CS');
    }

    /**
     * As celulas de uma equipe num dos dois placares, indexadas por problema.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cells(User $team, bool $frozen = false): array
    {
        foreach (Leaderboard::getScoreboard($this->contest->id, $frozen) as $row) {
            if ((int) $row['user']->user_id === (int) $team->user_id) {
                return collect($row['problems'])->keyBy('problem_id')->all();
            }
        }

        return [];
    }

    /** Quem tem a marca de primeiro a resolver neste problema, nos dois placares. */
    private function firstSolvers(Problem $problem, bool $frozen = false): array
    {
        $names = [];

        foreach (Leaderboard::getScoreboard($this->contest->id, $frozen) as $row) {
            foreach ($row['problems'] as $cell) {
                if ((int) $cell['problem_id'] === (int) $problem->id && $cell['is_first_solver']) {
                    $names[] = $row['user']->fullname;
                }
            }
        }

        sort($names);

        return $names;
    }

    /** As equipes citadas num premio de /awards. */
    private function awardTeams(string $awardId): array
    {
        $awards = (new ContestAwards)->forContest($this->contest->fresh())['awards'];

        foreach ($awards as $award) {
            if ($award['id'] === $awardId) {
                return User::whereIn('user_id', $award['team_ids'])
                    ->pluck('fullname')
                    ->sort()
                    ->values()
                    ->all();
            }
        }

        return [];
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
     * Issue #322 -- isto NAO e uma regra derivada da ICPC, e uma POLITICA, e
     * agora ela esta escrita: ver o docblock de `Answer::getDefaultAnswers()`
     * e `docs/specs/322-politica-de-penalidade-do-erro-de-compilacao.md`. O
     * padrao e penalizar, por compatibilidade com o BOCA e com a pratica da
     * Maratona, e deixou de ser o unico comportamento possivel -- o teste
     * irmao abaixo desliga a marca e mede o contrario.
     *
     * Mutacao que este teste pega, executada: em
     * `Answer::getDefaultAnswers()`, trocar o `counts_as_attempt` do `CE`
     * para `false` e refletir isso na linha usada aqui => attempts 1 e
     * penalidade 0. Falhou, que e o que tem que acontecer no dia em que
     * alguem inverter o padrao sem querer.
     *
     * Vale registrar por que o mecanismo esta no escopo e nao dentro de
     * `Score::reduceCell()`: tentar pular ali por `short_name` NAO derruba
     * teste nenhum, porque os dois chamadores carregam a relacao como
     * `answer:id,is_accepted` -- a reducao nao tem como saber qual veredito
     * ela esta contando, so se ele aceita ou nao. Ou seja, "CE nao conta"
     * nao e um `if` que falta na reducao; e uma decisao que muda quais runs
     * chegam ate ela.
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
        $this->assertSame(20, (int) $cell->penalty_time, 'por padrao o CE custa uma penalidade inteira');
    }

    /**
     * Issue #322 -- e a prova que desliga a marca nao paga por ele.
     *
     * A politica passa a ser uma linha de `answers`, que ja e por prova:
     * uma prova de treino ou uma seletiva interna desliga
     * `counts_as_attempt` no `CE` dela e nada mais muda. Nenhuma coluna nova
     * em `contests`, e o mesmo mecanismo que tira o `CS` do #321 da conta.
     *
     * Mutacao que este teste pega, executada: em
     * `Run::scopeCountingTowardsScore()`, remover o
     * `->whereHas('answer', fn ($q) => $q->where('counts_as_attempt', true))`
     * => attempts 2 e penalidade 20, ou seja, a marca nao faz nada. Falhou.
     */
    public function test_a_prova_pode_decidir_que_o_erro_de_compilacao_nao_penaliza(): void
    {
        $this->ce->update(['counts_as_attempt' => false]);

        $problem = $this->problem('A');
        $team = $this->team('Equipe A');

        $this->submit($team, $problem, 10, $this->ce);
        $this->submit($team, $problem, 30, $this->ac);

        $cell = $this->cell($team, $problem);

        $this->assertSame(1, (int) $cell->attempts, 'o CE nao chega nem a ser contado como tentativa');
        $this->assertSame(30, (int) $cell->solved_time);
        $this->assertSame(0, (int) $cell->penalty_time);
        $this->assertSame(30, (int) $this->standing($team)->total_time);
    }

    /**
     * Issue #321 -- a equipe nao paga pelo julgamento que a NOSSA
     * infraestrutura nao conseguiu fazer.
     *
     * O cenario e o que faltava na cobertura do #45: um `CS` escrito sem
     * `Score::updateScore()` (que e o que o watchdog e
     * `AutoJudgeService::handleJudgingError()` fazem) e DEPOIS um segundo
     * envio na mesma celula. E o segundo envio que revela o defeito: a
     * celula e recomposta do zero desde o #171, entao o `CS` entrava na
     * conta sem que ninguem tivesse chamado nada. A protecao dos dois
     * comentarios nao adiava o problema por engano -- ela so podia adiar.
     *
     * Controle positivo no mesmo teste: `Equipe azarada` e `Equipe errada`
     * fazem exatamente os mesmos dois envios, nos mesmos minutos. A unica
     * diferenca e o veredito do primeiro -- `CS` nosso contra `WA` dela --,
     * e e so a segunda que paga os 20 minutos.
     *
     * Mutacao que este teste pega, executada: em
     * `Run::scopeCountingTowardsScore()`, remover o
     * `->whereHas('answer', fn ($q) => $q->where('counts_as_attempt', true))`
     * => a equipe azarada volta a `attempts=2, penalty=20`, que e a medicao
     * da issue. Falhou.
     */
    public function test_o_veredito_de_falha_da_nossa_infraestrutura_nao_custa_tentativa(): void
    {
        $cs = $this->contactStaff();
        $problem = $this->problem('A');

        $unlucky = $this->team('Equipe azarada');
        $wrong = $this->team('Equipe errada');

        // Exatamente o que os dois caminhos de falha fazem: `judged` com o
        // veredito `CS`, e nada de Score::updateScore().
        Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $unlucky->user_id,
            'problem_id' => $problem->id,
            'contest_time' => 10 * 60,
            'status' => 'judged',
            'answer_id' => $cs->id,
            'judged_time' => 12 * 60,
        ]);

        $this->assertFalse(
            Score::where('user_id', $unlucky->user_id)->where('problem_id', $problem->id)->exists(),
            'no instante da falha a celula nem existe -- e esse estado intermediario que fazia o defeito ser invisivel'
        );

        $this->submit($wrong, $problem, 10, $this->wa);

        $this->submit($unlucky, $problem, 30, $this->ac);
        $this->submit($wrong, $problem, 30, $this->ac);

        $unluckyCell = $this->cell($unlucky, $problem);
        $this->assertSame(1, (int) $unluckyCell->attempts, 'o unico envio que conta e o que a equipe de fato errou -- nenhum');
        $this->assertSame(30, (int) $unluckyCell->solved_time);
        $this->assertSame(0, (int) $unluckyCell->penalty_time);
        $this->assertSame(30, (int) $this->standing($unlucky)->total_time);

        $wrongCell = $this->cell($wrong, $problem);
        $this->assertSame(2, (int) $wrongCell->attempts, 'controle positivo: os mesmos dois envios, com um WA no lugar do CS');
        $this->assertSame(20, (int) $wrongCell->penalty_time);
        $this->assertSame(50, (int) $this->standing($wrong)->total_time);

        $board = $this->board();
        $this->assertSame(1, $board['Equipe azarada']['rank'], 'e a diferenca decide a posicao');
        $this->assertSame(2, $board['Equipe errada']['rank']);
    }

    /**
     * Issue #321 -- e o placar congelado concorda, que e a metade que se
     * perde com facilidade.
     *
     * O congelado nao usa o escopo: ele faz uma consulta so para o contest
     * inteiro e filtra em memoria. Ate este PR ele reescrevia o predicado
     * por extenso, entao a exclusao do `CS` teria valido no placar ao vivo e
     * nao no congelado -- duas formulacoes da mesma regra discordando na
     * mesma requisicao, que e o defeito que o #317 mede em outro lugar deste
     * mesmo arquivo.
     *
     * Mutacao que este teste pega, executada: em `FrozenScoreboard::cell()`,
     * trocar `$run->countsTowardsScore($gated)` de volta pelo predicado
     * escrito a mao (`status === 'judged' && answer_id !== null && ...`)
     * => o placar congelado cobra os 20 minutos que o ao vivo nao cobra.
     * Falhou.
     */
    public function test_o_placar_congelado_tambem_nao_cobra_pela_falha_da_nossa_infraestrutura(): void
    {
        $cs = $this->contactStaff();
        $problem = $this->problem('A');
        $team = $this->team('Equipe azarada');

        Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'contest_time' => 10 * 60,
            'status' => 'judged',
            'answer_id' => $cs->id,
            'judged_time' => 12 * 60,
        ]);

        $this->submit($team, $problem, 30, $this->ac);

        $frozen = $this->cells($team, true)[$problem->id];

        $this->assertSame(1, $frozen['attempts']);
        $this->assertTrue($frozen['is_solved']);
        $this->assertSame(30, $frozen['solved_time']);
        $this->assertSame(0, $frozen['penalty_time']);
        $this->assertSame(0, $frozen['pending'], 'resolvida antes do corte, a celula esta fechada');
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

    /**
     * Issue #319 -- o congelamento de uma sede esconde o que AQUELA sede ja
     * congelou, e so isso.
     *
     * O #276 ligou duracao e congelamento proprios por sede e `ContestClock`
     * passou a responder corretamente QUANDO cada sede congela. O corte, que
     * decide O QUE o placar esconde, continuou global: uma sede com janela
     * mais curta tinha a ultima hora inteira dela no telao, na pagina
     * publica e no feed CLICS enquanto ainda competia.
     *
     * Controle positivo dentro do teste: as duas equipes resolvem o MESMO
     * problema no MESMO minuto de prova (190). A unica diferenca entre elas
     * e a janela da sede -- 240 minutos contra os 300 da prova --, e e ela
     * que decide qual das duas celulas o placar congelado mostra.
     *
     * Mutacao que este teste pega, executada: em `FrozenScoreboard::rows()`,
     * trocar `self::cutoffSeconds($contest, $siteId)` por
     * `self::cutoffSeconds($contest, null)` (o corte global, que e o que o
     * codigo fazia) => a equipe da sede curta aparece resolvida, com minuto
     * e tudo, no placar que diz estar congelado. Falhou.
     */
    public function test_o_corte_do_congelamento_usa_a_janela_da_sede_do_envio(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(200), 'freeze_time' => 60]);
        $this->contest->refresh();

        // A sede curta corre 240 minutos e congela nos 180 dela. A outra
        // herda a prova: 300 minutos, congelamento nos 240.
        $short = Site::factory()->create(['contest_id' => $this->contest->id, 'duration' => 240, 'freeze_time' => 60]);
        app(ContestClock::class)->forget();

        $clock = app(ContestClock::class);
        $this->assertTrue($clock->isFrozenFor($this->contest, $short->id), 'o cenario depende de a sede curta ja ter congelado');

        $problem = $this->problem('A');
        $fromShort = $this->team('Sede curta', $short);
        $fromFull = $this->team('Sede inteira', $this->site);

        $this->submit($fromShort, $problem, 190, $this->ac, $short);
        $this->submit($fromFull, $problem, 190, $this->ac, $this->site);

        $shortCell = $this->cells($fromShort, true)[$problem->id];
        $this->assertFalse($shortCell['is_solved'], 'a sede curta ja congelou aos 180; o AC dela aos 190 esta escondido');
        $this->assertSame(0, $shortCell['solved_time']);
        $this->assertSame(1, $shortCell['pending'], 'escondido nao e invisivel: a ICPC mostra a tentativa como pendente');

        $fullCell = $this->cells($fromFull, true)[$problem->id];
        $this->assertTrue($fullCell['is_solved'], 'controle positivo: mesmo minuto, sede que so congela aos 240');
        $this->assertSame(190, $fullCell['solved_time']);

        $board = $this->board(true);
        $this->assertSame(0, $board['Sede curta']['solved']);
        $this->assertSame(1, $board['Sede inteira']['solved']);
    }

    /**
     * A direcao oposta do mesmo defeito: uma sede com janela MAIOR nao pode
     * ter escondido o que ela ainda nao congelou.
     *
     * Menos grave que revelar -- esconder demais nao entrega informacao a
     * ninguem -- mas e o mesmo corte errado, e um teste so da primeira
     * direcao passaria com um `min()` global no lugar da janela da sede.
     *
     * Mutacao que este teste pega, executada: a mesma troca por
     * `cutoffSeconds($contest, null)` => o AC aos 245 da sede longa, que
     * congela so aos 300, aparece como pendente. Falhou.
     */
    public function test_a_sede_com_janela_maior_nao_tem_o_placar_escondido_cedo_demais(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(290), 'freeze_time' => 60]);
        $this->contest->refresh();

        $long = Site::factory()->create(['contest_id' => $this->contest->id, 'duration' => 360, 'freeze_time' => 60]);
        app(ContestClock::class)->forget();

        $problem = $this->problem('A');
        $team = $this->team('Sede longa', $long);

        $this->submit($team, $problem, 245, $this->ac, $long);

        $cell = $this->cells($team, true)[$problem->id];

        $this->assertTrue($cell['is_solved'], 'a sede longa so congela aos 300 dela; aos 245 ainda nao ha o que esconder');
        $this->assertSame(245, $cell['solved_time']);
        $this->assertSame(0, $cell['pending']);
    }

    // -- desempate ---------------------------------------------------------

    /**
     * Issue #316 -- o terceiro criterio da ICPC, e a medalha que dependia
     * dele.
     *
     * "Teams who solve the same number of problems are ranked first by least
     * total time and, if need be, by the earliest time of submission of the
     * last accepted run."
     *
     * Controle positivo: `Alfa` e criada ANTES de `Bravo`, e as duas tem 2
     * resolvidos e 60 minutos de tempo total. Com dois criterios so, o
     * `usort` estavel do PHP devolvia a ordem de entrada -- que vem de uma
     * consulta sem `ORDER BY` -- e `ContestAwards`, que fatia as medalhas
     * por indice, dava o ouro a `Alfa`. Pela regra, `Bravo` ganha: o ultimo
     * AC dela foi aos 40, e o de `Alfa` aos 50.
     *
     * Mutacao que este teste pega, executada: em `ScoreboardRanking::apply()`,
     * tirar `last_solved_time` das duas chaves do `usort` (voltando aos dois
     * criterios) => `Alfa` volta a rank 1 e leva o ouro, so por ter sido
     * criada primeiro. Falhou.
     */
    public function test_o_empate_em_tempo_e_decidido_pelo_ultimo_ac_e_nao_pela_ordem_do_banco(): void
    {
        $this->contest->update(['medal_gold' => 1, 'medal_silver' => 1, 'medal_bronze' => 0, 'rank_median_cut' => false]);
        $this->contest->refresh();

        $a = $this->problem('A');
        $b = $this->problem('B');

        // A ordem de criacao favorece Alfa: e exatamente o que nao pode
        // decidir a medalha.
        $alfa = $this->team('Alfa');
        $bravo = $this->team('Bravo');

        $this->submit($alfa, $a, 10, $this->ac);
        $this->submit($alfa, $b, 50, $this->ac);
        $this->submit($bravo, $a, 20, $this->ac);
        $this->submit($bravo, $b, 40, $this->ac);

        $board = $this->board();

        $this->assertSame(2, $board['Alfa']['solved']);
        $this->assertSame(2, $board['Bravo']['solved']);
        $this->assertSame(60, $board['Alfa']['time'], 'o empate e real: os dois primeiros criterios nao separam as duas');
        $this->assertSame(60, $board['Bravo']['time']);

        $this->assertSame(1, $board['Bravo']['rank'], 'o ultimo AC de Bravo foi aos 40; o de Alfa, aos 50');
        $this->assertSame(2, $board['Alfa']['rank']);

        $this->assertSame(['Bravo'], $this->awardTeams('winner'));
        $this->assertSame(['Bravo'], $this->awardTeams('gold-medal'));
        $this->assertSame(['Alfa'], $this->awardTeams('silver-medal'));
    }

    /**
     * Issue #316, caminho (2) -- o empate que SOBRA depois dos tres
     * criterios fica na mesma posicao, e a ordem do array para de depender
     * do banco.
     *
     * Compartilhar posicao no empate e o comportamento certo e o codigo ja
     * fazia. O dano estava em quem le a ORDEM como classificacao:
     * `ContestAwards` fatia medalhas por indice, `IcpcReportBuilder` itera o
     * placar, `ResultsBundleBuilder` e o feed CLICS idem. Em SQLite a ordem
     * sem `ORDER BY` parece deterministica; em MySQL o mesmo conjunto de
     * submissoes podia premiar equipes diferentes em dois carregamentos.
     *
     * Estavel nao e o mesmo que certo -- um empate ate aqui e uma decisao da
     * banca --, e por isso as duas ficam em rank 1: o que este teste fixa e
     * que a decisao nao seja tomada pelo plano de consulta, em silencio.
     *
     * Mutacao que este teste pega, executada: em `ScoreboardRanking::apply()`,
     * tirar `user_id` das duas chaves do `usort` => as linhas invertidas
     * saem invertidas, porque `usort` e estavel e nada mais separa as duas.
     * Falhou.
     */
    public function test_o_empate_que_sobra_compartilha_a_posicao_e_tem_ordem_estavel(): void
    {
        $problem = $this->problem('A');
        $first = $this->team('Equipe A');
        $second = $this->team('Equipe B');

        $this->submit($first, $problem, 30, $this->ac);
        $this->submit($second, $problem, 30, $this->ac);

        $board = $this->board();
        $this->assertSame(1, $board['Equipe A']['rank'], 'empate completo: as duas sao primeiras');
        $this->assertSame(1, $board['Equipe B']['rank']);

        $rows = Leaderboard::getScoreboard($this->contest->id);
        $order = fn (array $ranked) => array_map(fn (array $row) => $row['user']->fullname, $ranked);

        $this->assertSame(
            $order(ScoreboardRanking::apply($rows)),
            $order(ScoreboardRanking::apply(array_reverse($rows))),
            'as mesmas linhas em ordem invertida tem que sair na mesma ordem'
        );
    }

    // -- primeiro a resolver ----------------------------------------------

    /**
     * Issue #317 -- a marca e de quem submeteu primeiro, e nao de quem foi
     * julgado primeiro.
     *
     * `Cedo` submete aos 10 e o run fica pendente; `Tarde` submete aos 20 e
     * e julgada; so entao a de `Cedo` e julgada. Nao e cenario de
     * laboratorio: e o que um rejulgamento, um veredito retido pelo portao
     * do #138 e qualquer prova com mais de um judgehost (#53) produzem de
     * rotina.
     *
     * A atribuicao antiga era "se ainda nao existe nenhum primeiro a
     * resolver neste problema, sou eu" -- nada ali olhava tempo nenhum.
     *
     * Mutacao que este teste pega, executada: em `Score::recomputeFor()`,
     * trocar a chamada a `recomputeFirstSolver()` pelo bloco incremental
     * antigo (`$isFirstSolver = ! self::where(...)->where('is_first_solver',
     * true)->exists()`) => `Tarde` fica com a marca e o premio de /awards
     * cita ela. Falhou.
     */
    public function test_a_marca_de_primeiro_a_resolver_e_de_quem_submeteu_antes(): void
    {
        $problem = $this->problem('A');
        $early = $this->team('Cedo');
        $late = $this->team('Tarde');

        $pending = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $early->user_id,
            'problem_id' => $problem->id,
            'contest_time' => 10 * 60,
            'status' => 'pending',
            'answer_id' => null,
        ]);

        $this->submit($late, $problem, 20, $this->ac);

        $this->assertTrue((bool) $this->cell($late, $problem)->is_first_solver, 'enquanto e a unica resolvida, a marca e dela');

        $pending->update(['status' => 'judged', 'answer_id' => $this->ac->id, 'judged_time' => 30 * 60]);
        Score::updateScore($pending->fresh());

        $this->assertTrue((bool) $this->cell($early, $problem)->is_first_solver, 'julgada depois, mas submetida antes');
        $this->assertFalse((bool) $this->cell($late, $problem)->is_first_solver, 'e a marca sai de quem a tinha');

        $this->assertSame(['Cedo'], $this->awardTeams("first-to-solve-{$problem->id}"));
    }

    /**
     * Issue #317 -- e os dois placares param de discordar.
     *
     * O controle positivo da issue, medido aqui: os mesmos runs, na mesma
     * requisicao, dando respostas opostas. O placar congelado ja recalculava
     * a marca a partir dos tempos (e acertava); o ao vivo a lia de
     * `scores.is_first_solver` (e errava). Um dos dois estava errado, e era
     * o ao vivo.
     *
     * `T1` erra aos 10 e acerta aos 20; `T2` acerta aos 15. Os vereditos de
     * `T1` sao gravados antes -- o que e so a ordem em que o juiz clicou.
     *
     * Mutacao que este teste pega, executada: a mesma troca pelo bloco
     * incremental antigo => o placar ao vivo aponta `T1` e o congelado
     * aponta `T2`, na mesma execucao. Falhou.
     */
    public function test_os_dois_placares_apontam_o_mesmo_primeiro_a_resolver(): void
    {
        $problem = $this->problem('A');
        $t1 = $this->team('T1');
        $t2 = $this->team('T2');

        $this->submit($t1, $problem, 10, $this->wa);
        $this->submit($t1, $problem, 20, $this->ac);
        $this->submit($t2, $problem, 15, $this->ac);

        $this->assertSame(['T2'], $this->firstSolvers($problem), 'placar ao vivo');
        $this->assertSame(['T2'], $this->firstSolvers($problem, true), 'placar congelado, mesmos runs, mesma requisicao');
    }

    /**
     * Issue #317 -- empate no mesmo minuto se decide em SEGUNDOS.
     *
     * `solved_time` e minuto arredondado e nao serve como chave de
     * comparacao. O placar congelado desempatava por indice da linha, ou
     * seja: a equipe que chegou 40 segundos antes perdia a marca para a que
     * aparece antes na lista de equipes.
     *
     * Mutacao que este teste pega, executada: em
     * `FrozenScoreboard::markFirstSolvers()`, voltar a chave para
     * `[$cell['solved_time'], $index]` => o congelado aponta `Depois`, que e
     * a primeira linha da lista, e discorda do ao vivo. Falhou.
     */
    public function test_dois_ac_no_mesmo_minuto_sao_desempatados_por_segundo(): void
    {
        $problem = $this->problem('A');

        // A que aparece primeiro na lista de equipes e a que chegou DEPOIS:
        // sem isso o desempate por indice acertaria por acidente.
        $later = $this->team('Depois');
        $sooner = $this->team('Antes');

        $this->submitAtSecond($later, $problem, 650, $this->ac);
        $this->submitAtSecond($sooner, $problem, 610, $this->ac);

        $this->assertSame(10, (int) $this->cell($later, $problem)->solved_time, 'os dois marcam o minuto 10');
        $this->assertSame(10, (int) $this->cell($sooner, $problem)->solved_time);

        $this->assertSame(['Antes'], $this->firstSolvers($problem), 'placar ao vivo');
        $this->assertSame(['Antes'], $this->firstSolvers($problem, true), 'placar congelado');
    }

    /**
     * Issue #318 -- rejulgar o AC de quem tinha a marca passa a marca
     * adiante, em vez de deixar o problema sem primeiro a resolver.
     *
     * O #171 consertou a marca sobreviver ao rejulgamento; a outra metade
     * ficou. A marca sumia e ninguem a herdava, porque o rejulgamento so
     * toca as celulas dos runs que ele contem -- e some em silencio: nenhum
     * log, nenhuma diferenca de veredito, e a equipe que ganharia a marca
     * nunca soube que ganhou.
     *
     * Controle positivo: `Bravo` continua com o AC dela (`is_solved`
     * true) e e a unica equipe que resolveu o problema. Se a marca nao for
     * dela, nao e de ninguem.
     *
     * Mutacao que este teste pega, executada: em
     * `Score::recomputeFirstSolver()`, remover a segunda metade (o `update`
     * que DA a marca ao vencedor), deixando so a que a tira => o problema
     * fica com zero primeiros a resolver e o premio
     * `first-to-solve-<problema>` some de /awards. Falhou.
     */
    public function test_perder_o_ac_do_primeiro_a_resolver_passa_a_marca_para_o_seguinte(): void
    {
        $problem = $this->problem('A');
        $alfa = $this->team('Alfa');
        $bravo = $this->team('Bravo');

        $accepted = $this->submit($alfa, $problem, 10, $this->ac);
        $this->submit($bravo, $problem, 20, $this->ac);

        $this->assertTrue((bool) $this->cell($alfa, $problem)->is_first_solver);
        $this->assertFalse((bool) $this->cell($bravo, $problem)->is_first_solver);

        $accepted->update(['answer_id' => $this->wa->id]);
        Score::updateScore($accepted->fresh());

        $this->assertFalse((bool) $this->cell($alfa, $problem)->is_solved, 'Alfa perdeu o AC');
        $this->assertFalse((bool) $this->cell($alfa, $problem)->is_first_solver);

        $this->assertTrue((bool) $this->cell($bravo, $problem)->is_solved, 'controle positivo: Bravo continua resolvida');
        $this->assertTrue((bool) $this->cell($bravo, $problem)->is_first_solver, 'e passa a ser a primeira a resolver');

        $this->assertSame(
            1,
            Score::where('problem_id', $problem->id)->where('is_first_solver', true)->count(),
            'um problema resolvido tem exatamente um primeiro a resolver'
        );
        $this->assertSame(['Bravo'], $this->awardTeams("first-to-solve-{$problem->id}"));
    }

    // -- congelamento e a propria equipe -----------------------------------

    /**
     * Issue #320 -- /my-score nao entrega a classificacao ao vivo durante o
     * congelamento.
     *
     * `leaderboard.rank` e reescrito a cada veredito da prova inteira,
     * inclusive os que o congelamento esconde, e `userScore()` o devolvia
     * cru -- sem mencionar congelamento em lugar nenhum, enquanto
     * `index()`, no mesmo arquivo, calculava a decisao com cuidado. Uma
     * equipe consultando a rota em laco na ultima hora sabia, pelo proprio
     * numero, quantas equipes passaram por ela e quando. RN-007 e
     * RF-F12-004 sao P0 no SRS.
     *
     * Controle positivo: o `/scoreboard` servido pela MESMA API, para o
     * mesmo usuario, no mesmo instante, nao se move -- e era essa a
     * informacao que /my-score entregava no mesmo segundo.
     *
     * Mutacao que este teste pega, executada: em
     * `Api\ScoreboardController::userScore()`, devolver
     * `$leaderboardEntry?->rank` como antes => a posicao vira 2 no instante
     * do solve escondido. Falhou.
     */
    public function test_my_score_nao_revela_a_classificacao_ao_vivo_durante_o_congelamento(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(270), 'freeze_time' => 60]);
        $this->contest->refresh();
        app(ContestClock::class)->forget();

        $a = $this->problem('A');
        $b = $this->problem('B');
        $me = $this->team('Eu');
        $rival = $this->team('Rival');

        $this->submit($me, $a, 60, $this->ac);
        $this->submit($rival, $a, 70, $this->ac);

        Sanctum::actingAs($me, ['*']);

        $before = $this->getJson("/api/contests/{$this->contest->id}/my-score");
        $before->assertOk();
        $this->assertSame(1, $before->json('rank'));
        $this->assertTrue($before->json('is_frozen'));

        // O AC do rival aos 265 esta DENTRO do congelamento (corte aos 240).
        $this->submit($rival, $b, 265, $this->ac);

        $after = $this->getJson("/api/contests/{$this->contest->id}/my-score");
        $after->assertOk();
        $this->assertSame(1, $after->json('rank'), 'a posicao entregue e a do placar que todo mundo ve');

        $public = $this->getJson("/api/contests/{$this->contest->id}/scoreboard")->json('scoreboard');
        $byName = collect($public)->keyBy(fn (array $row) => $row['user']['fullname']);
        $this->assertSame(1, $byName['Eu']['rank'], 'controle positivo: o placar congelado nao se moveu');
        $this->assertSame(1, $byName['Eu']['problems_solved']);
        $this->assertSame(1, $byName['Rival']['problems_solved']);

        // E, descongelado, a rota volta a contar a verdade -- senao este
        // teste passaria com um `rank => null` fixo.
        $this->contest->update(['unfrozen_at' => now()]);
        $this->contest->refresh();
        app(ContestClock::class)->forget();

        $revealed = $this->getJson("/api/contests/{$this->contest->id}/my-score");
        $this->assertFalse($revealed->json('is_frozen'));
        $this->assertSame(2, $revealed->json('rank'), 'o rival resolveu dois problemas e passou mesmo');
    }
}
