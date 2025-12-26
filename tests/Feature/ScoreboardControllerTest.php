<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        DB::table('teams')->insert([
            ['teamName' => 'Team Alpha', 'score' => 100],
            ['teamName' => 'Team Bravo', 'score' => 200],
        ]);

        // 2. Act
        $response = $this->get('/scoreboard');

        // 3. Assert
        $response->assertStatus(200);
        $response->assertViewIs('scoreboard');
        $response->assertViewHas('teams', function ($teams) {
            // Check that teams are ordered by score DESC
            return $teams[0]->teamName === 'Team Bravo' && $teams[1]->teamName === 'Team Alpha';
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
        $teamId = DB::table('teams')->insertGetId(
            ['teamName' => 'Team CSV', 'score' => 150]
        );

        // 2. Act
        $response = $this->get('/scoreboard/export');

        // 3. Assert
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        
        $content = $response->streamedContent();

        // Check for header
        $this->assertStringContainsString('Posicao,Time,"Problemas Resolvidos",Penalidade,Pontuacao', $content);
        
        // Check for data (with 0 for non-existent columns)
        $this->assertStringContainsString('1,"Team CSV",0,0,150', $content);
    }
}
