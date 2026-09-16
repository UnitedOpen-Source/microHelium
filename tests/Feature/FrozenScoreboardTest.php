<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Leaderboard;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use Helium\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #211 -- o congelamento passa a esconder alguma coisa.
 *
 * Antes disto, `Leaderboard::getScoreboard()` recebia `$frozen` e nunca o
 * lia: o placar congelado e o placar ao vivo eram, byte a byte, o mesmo
 * placar, e a API respondia `is_frozen: true` entregando o solve
 * pos-congelamento na mesma carga.
 *
 * O teste que importa mais e o primeiro: os dois placares TEM que diferir.
 * Uma suite que so conferisse a forma do placar congelado passaria
 * inteirinha com o bug de volta.
 */
class FrozenScoreboardTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problemA;

    private Problem $problemB;

    private Answer $yes;

    private Answer $no;

    /** Prova de 300 min com congelamento nos ultimos 60: o corte fica aos 240. */
    private const CUTOFF_MINUTES = 240;

    protected function setUp(): void
    {
        parent::setUp();

        // Estamos a 270 minutos de prova: depois do congelamento, antes do fim.
        $this->contest = Contest::factory()->create([
            'is_active' => true,
            'is_practice' => false,
            'is_public' => true,
            'start_time' => now()->subMinutes(270),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
        ]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problemA = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'A']);
        $this->problemB = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'B']);
        $this->yes = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);
        $this->no = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'NO', 'is_accepted' => false]);

        $this->assertTrue($this->contest->isFrozen(), 'o cenario depende de o contest estar congelado');
    }

    private function team(string $name): User
    {
        return $this->createTestUser([
            'fullname' => $name,
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
        ]);
    }

    private function submit(User $team, Problem $problem, int $atMinute, ?Answer $answer): Run
    {
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'contest_time' => $atMinute * 60,
            'status' => $answer ? 'judged' : 'pending',
            'answer_id' => $answer?->id,
            'judged_time' => $answer ? $atMinute * 60 : null,
        ]);

        Score::updateScore($run);

        return $run;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cells(array $rows, User $team): array
    {
        foreach ($rows as $row) {
            if ((int) $row['user']->user_id === (int) $team->user_id) {
                return collect($row['problems'])->keyBy('problem_id')->toArray();
            }
        }

        return [];
    }

    // -- o bug -------------------------------------------------------------

    /**
     * O teste que o bug original passaria: os dois placares eram identicos.
     */
    public function test_the_frozen_board_is_not_the_live_board(): void
    {
        $team = $this->team('Equipe A');
        $this->submit($team, $this->problemA, 270, $this->yes);

        $this->assertNotEquals(
            json_encode(Leaderboard::getScoreboard($this->contest->id, false)),
            json_encode(Leaderboard::getScoreboard($this->contest->id, true)),
            'o placar congelado saiu igual ao placar ao vivo'
        );
    }

    public function test_a_solve_after_the_cutoff_is_hidden(): void
    {
        $team = $this->team('Equipe A');
        $this->submit($team, $this->problemA, 270, $this->yes);

        $cells = $this->cells(Leaderboard::getScoreboard($this->contest->id, true), $team);

        $this->assertFalse($cells[$this->problemA->id]['is_solved']);
        $this->assertSame(0, $cells[$this->problemA->id]['attempts']);
    }

    public function test_a_solve_before_the_cutoff_is_shown_exactly_as_the_live_board_shows_it(): void
    {
        $team = $this->team('Equipe A');
        $this->submit($team, $this->problemA, 10, $this->no);
        $this->submit($team, $this->problemA, 30, $this->yes);

        $frozen = $this->cells(Leaderboard::getScoreboard($this->contest->id, true), $team);
        $live = $this->cells(Leaderboard::getScoreboard($this->contest->id, false), $team);

        $this->assertTrue($frozen[$this->problemA->id]['is_solved']);
        $this->assertSame(30, $frozen[$this->problemA->id]['solved_time']);
        $this->assertSame(20, $frozen[$this->problemA->id]['penalty_time'], 'a tentativa errada anterior tem que pagar');
        $this->assertSame($live[$this->problemA->id]['attempts'], $frozen[$this->problemA->id]['attempts']);
    }

    /**
     * A celula congelada NAO e "sem informacao": a ICPC mostra as tentativas
     * do congelamento como pendentes, e e isso que faz a revelacao ter
     * graca. Esconder a existencia da submissao seria pior -- a equipe nao
     * saberia nem que o adversario tentou.
     */
    public function test_attempts_made_during_the_freeze_show_as_pending(): void
    {
        $team = $this->team('Equipe A');
        $this->submit($team, $this->problemA, 250, $this->no);
        $this->submit($team, $this->problemA, 270, $this->yes);

        $cells = $this->cells(Leaderboard::getScoreboard($this->contest->id, true), $team);

        $this->assertSame(2, $cells[$this->problemA->id]['pending']);
        $this->assertFalse($cells[$this->problemA->id]['is_solved']);
    }

    /**
     * Mostrar "resolvido + 3 pendentes" diria que a equipe continuou
     * submetendo um problema que ja tinha passado -- o que a ICPC nao conta
     * e a equipe nao fez.
     */
    public function test_a_cell_solved_before_the_cutoff_reports_no_pending(): void
    {
        $team = $this->team('Equipe A');
        $this->submit($team, $this->problemA, 30, $this->yes);
        $this->submit($team, $this->problemA, 250, $this->no);

        $cells = $this->cells(Leaderboard::getScoreboard($this->contest->id, true), $team);

        $this->assertTrue($cells[$this->problemA->id]['is_solved']);
        $this->assertSame(0, $cells[$this->problemA->id]['pending']);
    }

    /**
     * Um run ainda sem veredito, submetido ANTES do corte, tambem esta em
     * aberto: o placar nao pode contar o que ninguem julgou.
     */
    public function test_an_unjudged_run_from_before_the_cutoff_is_pending_too(): void
    {
        $team = $this->team('Equipe A');
        // O placar so lista quem ja teve algum run julgado -- a linha de
        // leaderboard nasce em Score::recomputeFor(). Ver a issue aberta
        // sobre equipes invisiveis; aqui o B so existe para por a equipe na
        // tabela, e o caso sob teste e a celula do A.
        $this->submit($team, $this->problemB, 50, $this->no);
        $this->submit($team, $this->problemA, 100, null);

        $cells = $this->cells(Leaderboard::getScoreboard($this->contest->id, true), $team);

        $this->assertSame(1, $cells[$this->problemA->id]['pending']);
        $this->assertSame(0, $cells[$this->problemA->id]['attempts']);
    }

    // -- ordem -------------------------------------------------------------

    /**
     * As posicoes tambem sao recalculadas. Servir a ordem ao vivo junto de
     * celulas congeladas diria, pela ordem, o que as celulas esconderam.
     */
    public function test_the_ranking_is_recomputed_from_the_frozen_cells(): void
    {
        $lider = $this->team('Lider ao vivo');
        $segundo = $this->team('Segundo ao vivo');

        // O segundo resolveu um antes do corte; o lider resolveu DOIS, mas o
        // segundo deles durante o congelamento.
        $this->submit($segundo, $this->problemA, 20, $this->yes);
        $this->submit($lider, $this->problemA, 10, $this->yes);
        $this->submit($lider, $this->problemB, 260, $this->yes);

        $live = Leaderboard::getScoreboard($this->contest->id, false);
        $frozen = Leaderboard::getScoreboard($this->contest->id, true);

        $this->assertSame(2, $live[0]['problems_solved'], 'ao vivo, o lider tem dois');
        $this->assertSame(1, $frozen[0]['problems_solved'], 'congelado, ninguem passa de um');
        $this->assertSame(1, $frozen[0]['rank']);
    }

    /**
     * A marca de primeiro a resolver e recalculada, e nao lida de
     * `scores.is_first_solver`: a marca guardada pode ter sido conquistada
     * durante o congelamento, e mostra-la entregaria o solve escondido.
     */
    public function test_a_first_solve_made_during_the_freeze_does_not_show_its_medal(): void
    {
        $team = $this->team('Equipe A');
        $this->submit($team, $this->problemB, 260, $this->yes);

        $liveCells = $this->cells(Leaderboard::getScoreboard($this->contest->id, false), $team);
        $frozenCells = $this->cells(Leaderboard::getScoreboard($this->contest->id, true), $team);

        $this->assertTrue($liveCells[$this->problemB->id]['is_first_solver']);
        $this->assertFalse($frozenCells[$this->problemB->id]['is_first_solver'] ?? false);
    }

    public function test_the_first_solve_before_the_cutoff_keeps_its_medal(): void
    {
        $primeiro = $this->team('Primeiro');
        $depois = $this->team('Depois');
        $this->submit($primeiro, $this->problemA, 10, $this->yes);
        $this->submit($depois, $this->problemA, 50, $this->yes);

        $cells = $this->cells(Leaderboard::getScoreboard($this->contest->id, true), $primeiro);
        $outras = $this->cells(Leaderboard::getScoreboard($this->contest->id, true), $depois);

        $this->assertTrue($cells[$this->problemA->id]['is_first_solver']);
        $this->assertFalse($outras[$this->problemA->id]['is_first_solver']);
    }

    // -- as portas ---------------------------------------------------------

    public function test_the_api_no_longer_contradicts_its_own_is_frozen_flag(): void
    {
        $team = $this->team('Equipe A');
        $this->submit($team, $this->problemA, 270, $this->yes);

        Sanctum::actingAs($team);
        $body = $this->getJson("/api/contests/{$this->contest->id}/scoreboard")->assertStatus(200)->json();

        $this->assertTrue($body['contest']['is_frozen']);
        $this->assertFalse($body['scoreboard'][0]['problems'][0]['is_solved']);
    }

    public function test_the_jury_still_sees_the_live_board(): void
    {
        $team = $this->team('Equipe A');
        $this->submit($team, $this->problemA, 270, $this->yes);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->createTestUser(['user_type' => 'judge', 'contest_id' => $this->contest->id]));

        $body = $this->getJson("/api/contests/{$this->contest->id}/scoreboard")->assertStatus(200)->json();

        $this->assertFalse($body['contest']['is_frozen']);
        $this->assertTrue($body['scoreboard'][0]['problems'][0]['is_solved']);
    }

    /**
     * /scoreboard e a pagina do projetor e nao tem autenticacao nenhuma --
     * era o placar ao vivo, aberto, para a sala inteira na ultima hora.
     */
    public function test_the_public_projector_page_is_frozen_for_a_visitor(): void
    {
        $team = $this->team('Equipe Congelada');
        $this->submit($team, $this->problemA, 30, $this->no);
        $this->submit($team, $this->problemA, 270, $this->yes);

        $html = $this->get('/scoreboard')->assertStatus(200)->getContent();

        $this->assertStringContainsString('Equipe Congelada', $html);
        $this->assertStringContainsString('Placar congelado', $html, 'a pagina esconde sem avisar');
        $this->assertStringNotContainsString('Aceito na tentativa', $html, 'a pagina publica mostrou o solve do congelamento');
    }

    /**
     * Sem um estado proprio, uma celula congelada com submissoes dentro caia
     * no "Nao tentado" -- que e falso, e e justamente a informacao que a
     * ICPC mantem visivel durante o congelamento.
     */
    public function test_the_projector_shows_a_frozen_cell_as_pending_and_not_as_untried(): void
    {
        $team = $this->team('Equipe Congelada');
        $this->submit($team, $this->problemB, 20, $this->yes);
        $this->submit($team, $this->problemA, 250, $this->no);
        $this->submit($team, $this->problemA, 270, $this->yes);

        $html = $this->get('/scoreboard')->assertStatus(200)->getContent();

        $this->assertStringContainsString('resultado não divulgado durante o congelamento', $html);
        $this->assertStringNotContainsString($this->problemA->name.': Não tentado', $html);
    }

    /**
     * E a banda de aviso nao pode aparecer quando nao ha congelamento: um
     * aviso permanente e um aviso que ninguem le.
     */
    public function test_the_projector_says_nothing_about_a_freeze_when_there_is_none(): void
    {
        $this->contest->update(['freeze_time' => 0]);

        $html = $this->get('/scoreboard')->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('Placar congelado', $html);
    }

    /**
     * O #189 pos o descongelamento; sem corte nao havia o que descongelar.
     */
    public function test_unfreezing_brings_the_hidden_solve_back(): void
    {
        $team = $this->team('Equipe A');
        $this->submit($team, $this->problemA, 270, $this->yes);

        $this->contest->update(['unfrozen_at' => now()]);
        $this->contest->refresh();

        $this->assertFalse($this->contest->isFrozen());

        Sanctum::actingAs($team);
        $body = $this->getJson("/api/contests/{$this->contest->id}/scoreboard")->assertStatus(200)->json();

        $this->assertFalse($body['contest']['is_frozen']);
        $this->assertTrue($body['scoreboard'][0]['problems'][0]['is_solved']);
    }

    /**
     * Um contest sem congelamento configurado (`freeze_time` = 0) nao tem
     * corte nenhum -- a mesma armadilha de sinal oposto que o #189 fechou no
     * predicado.
     */
    public function test_a_contest_configured_without_a_freeze_shows_everything(): void
    {
        $this->contest->update(['freeze_time' => 0]);
        $this->contest->refresh();

        $team = $this->team('Equipe A');
        $this->submit($team, $this->problemA, 270, $this->yes);

        $this->assertFalse($this->contest->isFrozen());

        $cells = $this->cells(Leaderboard::getScoreboard($this->contest->id, false), $team);
        $this->assertTrue($cells[$this->problemA->id]['is_solved']);
    }

    /**
     * O corte cai exatamente no minuto do congelamento, e o que acontece NO
     * minuto fica de fora: o congelamento comeca ali.
     */
    public function test_the_cutoff_is_the_freeze_minute_itself(): void
    {
        $team = $this->team('Equipe A');
        $this->submit($team, $this->problemA, self::CUTOFF_MINUTES - 1, $this->yes);
        $this->submit($team, $this->problemB, self::CUTOFF_MINUTES, $this->yes);

        $cells = $this->cells(Leaderboard::getScoreboard($this->contest->id, true), $team);

        $this->assertTrue($cells[$this->problemA->id]['is_solved'], 'um minuto antes do corte tem que aparecer');
        $this->assertFalse($cells[$this->problemB->id]['is_solved'], 'no minuto do corte ja esta congelado');
    }

    /**
     * A porta do #138 vale aqui tambem: um veredito retido da equipe nao
     * pode ser contado para ela no placar congelado.
     */
    public function test_a_withheld_verdict_does_not_count_on_the_frozen_board(): void
    {
        $this->contest->update(['verification_required' => true]);
        $this->contest->refresh();

        $team = $this->team('Equipe A');
        $run = $this->submit($team, $this->problemA, 30, $this->yes);
        $run->update(['verified_at' => null]);

        $cells = $this->cells(Leaderboard::getScoreboard($this->contest->id, true), $team);

        $this->assertFalse($cells[$this->problemA->id]['is_solved']);
        $this->assertSame(1, $cells[$this->problemA->id]['pending']);
    }

    /**
     * A forma nao pode depender de qual placar se esta lendo: um template
     * que so encontrasse `pending` as vezes teria que adivinhar.
     */
    public function test_the_live_board_carries_the_same_keys(): void
    {
        $team = $this->team('Equipe A');
        $this->submit($team, $this->problemA, 30, $this->yes);

        $live = $this->cells(Leaderboard::getScoreboard($this->contest->id, false), $team);
        $frozen = $this->cells(Leaderboard::getScoreboard($this->contest->id, true), $team);

        $this->assertSame(
            array_keys($frozen[$this->problemA->id]),
            array_keys($live[$this->problemA->id])
        );
    }
}
