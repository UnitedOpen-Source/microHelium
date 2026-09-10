<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Leaderboard;
use App\Models\Problem;
use App\Models\Score;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoreboardControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test the scoreboard index page.
     *
     * @return void
     */
    public function test_scoreboard_index_page_loads_and_displays_teams()
    {
        // 1. Arrange
        $contest = Contest::factory()->create(['is_active' => true]);
        $alpha = User::factory()->create(['fullname' => 'Team Alpha']);
        $bravo = User::factory()->create(['fullname' => 'Team Bravo']);

        Leaderboard::create(['contest_id' => $contest->id, 'user_id' => $alpha->user_id, 'problems_solved' => 1, 'total_time' => 50, 'rank' => 2]);
        Leaderboard::create(['contest_id' => $contest->id, 'user_id' => $bravo->user_id, 'problems_solved' => 2, 'total_time' => 30, 'rank' => 1]);

        // 2. Act
        $response = $this->get('/scoreboard');

        // 3. Assert
        $response->assertStatus(200);
        $response->assertViewIs('scoreboard');
        $response->assertViewHas('entries', function ($entries) {
            // Ranked by the Leaderboard rank field, best first
            return $entries[0]['user']->fullname === 'Team Bravo' && $entries[1]['user']->fullname === 'Team Alpha';
        });
        $response->assertSeeText('Team Bravo');
    }

    /**
     * Test the scoreboard CSV export.
     *
     * @return void
     */
    public function test_scoreboard_export_generates_correct_csv()
    {
        // 1. Arrange
        $contest = Contest::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['fullname' => 'Team CSV']);
        Leaderboard::create(['contest_id' => $contest->id, 'user_id' => $user->user_id, 'problems_solved' => 0, 'total_time' => 0, 'rank' => 1]);

        // 2. Act
        $response = $this->get('/scoreboard/export');

        // 3. Assert
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        // Check for header
        $this->assertStringContainsString('Posicao,Time,"Problemas Resolvidos",Penalidade', $content);

        // Check for data
        $this->assertStringContainsString('1,"Team CSV",0,0', $content);
    }

    /**
     * A fullname starting with =, +, -, or @ opened in Excel/Sheets is
     * interpreted as a formula (CSV/formula injection) unless neutralized.
     */
    public function test_scoreboard_export_neutralizes_formula_injection_in_team_name()
    {
        $contest = Contest::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['fullname' => '=HYPERLINK("http://evil.example")']);
        Leaderboard::create(['contest_id' => $contest->id, 'user_id' => $user->user_id, 'problems_solved' => 0, 'total_time' => 0, 'rank' => 1]);

        $response = $this->get('/scoreboard/export');

        $content = $response->streamedContent();

        $this->assertStringNotContainsString(',=HYPERLINK', $content);
        $this->assertStringContainsString("'=HYPERLINK", $content);
    }
}
