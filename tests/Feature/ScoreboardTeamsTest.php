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
use Tests\TestCase;

/**
 * Issue #212 -- quem aparece no placar.
 *
 * A resposta era "quem ja teve um run julgado", e ninguem tinha decidido
 * isso: a linha de `leaderboard` nasce em Score::recomputeFor(), que so
 * roda para run julgado, e getScoreboard() iterava essas linhas.
 *
 * Numa prova: nos primeiros minutos a tela fica praticamente vazia, e uma
 * equipe que submeteu e espera julgamento nao se encontra nela. Do ponto de
 * vista dela a submissao sumiu.
 */
class ScoreboardTeamsTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->running()->create(['is_public' => true]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'A']);
    }

    /**
     * @return list<string>
     */
    private function names(bool $frozen = false): array
    {
        return array_map(
            fn (array $row) => $row['user']->fullname,
            Leaderboard::getScoreboard($this->contest->id, $frozen)
        );
    }

    private function team(string $name, array $attributes = []): User
    {
        return $this->createTestUser(array_merge([
            'fullname' => $name,
            'user_type' => 'team',
            'contest_id' => $this->contest->id,
        ], $attributes));
    }

    // -- quem entra ---------------------------------------------------------

    public function test_a_team_that_has_not_submitted_anything_is_on_the_board(): void
    {
        $this->team('Equipe Sem Envio');

        $this->assertSame(['Equipe Sem Envio'], $this->names());
    }

    /**
     * O sintoma concreto: a equipe submeteu, esta esperando julgamento, e
     * nao se encontra na tela onde iria procurar confirmacao.
     */
    public function test_a_team_waiting_for_a_verdict_is_on_the_board(): void
    {
        $team = $this->team('Equipe Aguardando', ['site_id' => $this->site->id]);

        Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $this->problem->id,
            'status' => 'pending',
        ]);

        $this->assertSame(['Equipe Aguardando'], $this->names());
    }

    /**
     * No esquema do BOCA uma conta chega ao contest pela propria coluna ou
     * pela sede. Contest::memberContestIds() ja enfrentou esta pergunta --
     * do outro lado, partindo do usuario -- e respondeu "qualquer um dos
     * dois"; ler so users.contest_id deixaria de fora a equipe cuja
     * inscricao preencheu a sede.
     */
    public function test_a_team_attached_only_through_its_site_is_on_the_board(): void
    {
        $this->createTestUser([
            'fullname' => 'Equipe Pela Sede',
            'user_type' => 'team',
            'contest_id' => null,
            'site_id' => $this->site->id,
        ]);

        $this->assertSame(['Equipe Pela Sede'], $this->names());
    }

    // -- quem fica de fora --------------------------------------------------

    public function test_staff_profiles_are_not_on_the_board(): void
    {
        foreach (['judge', 'admin', 'staff', 'site', 'score'] as $type) {
            $this->createTestUser([
                'fullname' => 'Nao compete '.$type,
                'user_type' => $type,
                'contest_id' => $this->contest->id,
                'site_id' => $this->site->id,
            ]);
        }

        $this->team('Equipe de verdade');

        $this->assertSame(['Equipe de verdade'], $this->names());
    }

    public function test_a_disabled_account_is_not_on_the_board(): void
    {
        $this->team('Equipe Desabilitada', ['is_enabled' => false]);
        $this->team('Equipe Ativa');

        $this->assertSame(['Equipe Ativa'], $this->names());
    }

    public function test_a_team_from_another_contest_is_not_on_the_board(): void
    {
        $outro = Contest::factory()->running()->create();
        $this->createTestUser([
            'fullname' => 'Equipe de Outro Contest',
            'user_type' => 'team',
            'contest_id' => $outro->id,
        ]);

        $this->team('Equipe Daqui');

        $this->assertSame(['Equipe Daqui'], $this->names());
    }

    /**
     * A uniao e deliberada. So as equipes do contest seria uma mudanca que
     * REMOVE gente da tela, e ninguem pediu isso: uma conta cujas colunas de
     * vinculo estao estranhas, mas que tem pontuacao registrada, esta
     * visivelmente participando.
     */
    public function test_an_account_with_a_scored_row_but_no_membership_columns_is_kept(): void
    {
        $orfao = $this->createTestUser(['fullname' => 'Linha Antiga', 'user_type' => 'team', 'contest_id' => null]);
        Leaderboard::create([
            'contest_id' => $this->contest->id,
            'user_id' => $orfao->user_id,
            'problems_solved' => 1,
            'total_time' => 40,
            'rank' => 1,
        ]);

        $this->assertContains('Linha Antiga', $this->names());
    }

    // -- ordem --------------------------------------------------------------

    /**
     * Quem nao resolveu nada vai para o fim, e nao muda a posicao de quem
     * ja estava na tabela.
     */
    public function test_teams_with_nothing_solved_go_last_without_moving_the_others(): void
    {
        $lider = $this->team('Lider');
        $segundo = $this->team('Segundo');
        $this->team('Zerado');

        Leaderboard::create(['contest_id' => $this->contest->id, 'user_id' => $lider->user_id, 'problems_solved' => 2, 'total_time' => 30, 'rank' => 1]);
        Leaderboard::create(['contest_id' => $this->contest->id, 'user_id' => $segundo->user_id, 'problems_solved' => 1, 'total_time' => 50, 'rank' => 2]);

        $rows = Leaderboard::getScoreboard($this->contest->id);

        $this->assertSame(['Lider', 'Segundo', 'Zerado'], array_map(fn ($r) => $r['user']->fullname, $rows));
        $this->assertSame([1, 2, 3], array_map(fn ($r) => $r['rank'], $rows));
    }

    /**
     * Empate compartilha a posicao e a seguinte pula -- a regra da ICPC, a
     * mesma de Leaderboard::recalculateRanks() e a mesma do placar
     * congelado, porque agora as tres chamam a mesma funcao.
     */
    public function test_a_tie_shares_the_place_and_the_next_one_skips(): void
    {
        $a = $this->team('Empatado A');
        $b = $this->team('Empatado B');
        $c = $this->team('Terceiro');

        foreach ([$a, $b] as $team) {
            Leaderboard::create(['contest_id' => $this->contest->id, 'user_id' => $team->user_id, 'problems_solved' => 2, 'total_time' => 30, 'rank' => 0]);
        }
        Leaderboard::create(['contest_id' => $this->contest->id, 'user_id' => $c->user_id, 'problems_solved' => 1, 'total_time' => 10, 'rank' => 0]);

        $this->assertSame([1, 1, 3], array_map(fn ($r) => $r['rank'], Leaderboard::getScoreboard($this->contest->id)));
    }

    // -- os dois placares ---------------------------------------------------

    /**
     * Os dois placares tem que listar as MESMAS equipes. Durante o
     * congelamento, uma equipe que aparece num e some do outro seria lida
     * como informacao -- e seria informacao errada.
     */
    public function test_the_frozen_board_lists_the_same_teams(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(270)]);
        $this->contest->refresh();
        $this->assertTrue($this->contest->isFrozen());

        $this->team('Equipe Sem Envio');
        $comEnvio = $this->team('Equipe Com Envio', ['site_id' => $this->site->id]);

        $yes = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $comEnvio->user_id,
            'problem_id' => $this->problem->id,
            'status' => 'judged',
            'answer_id' => $yes->id,
            'contest_time' => 30 * 60,
            'judged_time' => 30 * 60,
        ]);
        Score::updateScore($run);

        $live = $this->names();
        $frozen = $this->names(true);

        sort($live);
        sort($frozen);

        $this->assertSame($live, $frozen);
        $this->assertSame(['Equipe Com Envio', 'Equipe Sem Envio'], $frozen);
    }
}
