<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Problem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProblemControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> description/ directories written to real storage by statementFile() */
    private array $statementDirs = [];

    protected function tearDown(): void
    {
        // Storage::fake() does not reach these: Problem::getPackagePath() is a
        // real storage_path(), so the fixtures are real files on disk.
        foreach ($this->statementDirs as $dir) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
            @rmdir(dirname($dir));
            @rmdir(dirname($dir, 2));
        }

        parent::tearDown();
    }

    /**
     * Put a statement file where a BOCA package import would have left it.
     */
    private function statementFile(Problem $problem, string $filename, string $contents = '%PDF-1.4 fake'): void
    {
        $dir = storage_path("app/problems/{$problem->contest_id}/{$problem->basename}/description");
        @mkdir($dir, 0755, true);
        file_put_contents($dir.'/'.$filename, $contents);
        $this->statementDirs[] = $dir;
    }

    /**
     * Test the problems index page loads correctly for the active contest.
     */
    /**
     * Issue #135 -- the listing and the detail page must agree.
     *
     * show() has always refused a guest a problem whose contest is not
     * public; index() listed the same problems to the same guest. is_public
     * defaults to false, so the listing was the more permissive of the two
     * by accident, and a running private contest published its problem list
     * to anyone who asked.
     */
    public function test_a_guest_is_not_shown_a_private_contests_problems(): void
    {
        $contest = Contest::factory()->create(['is_active' => true, 'is_public' => false]);
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id, 'short_name' => 'A', 'name' => 'SEGREDO',
        ]);

        $response = $this->get('/exercises');

        $response->assertStatus(200);
        $response->assertViewHas('problems', fn ($problems) => count($problems) === 0);
        $response->assertDontSee('SEGREDO');

        // The detail page already behaved this way; this is the pair.
        $this->get("/exercise/{$problem->id}")->assertNotFound();
    }

    /**
     * And a team still sees its own contest, public or not -- the fix must
     * not lock competitors out of the competition they are in.
     */
    public function test_a_team_still_sees_its_own_private_contest(): void
    {
        $contest = Contest::factory()->create(['is_active' => true, 'is_public' => false]);
        Problem::factory()->create(['contest_id' => $contest->id, 'short_name' => 'A', 'name' => 'P1']);
        $team = $this->createTestUser(['contest_id' => $contest->id, 'user_type' => 'team']);

        $response = $this->actingAs($team)->get('/exercises');

        $response->assertStatus(200);
        $response->assertViewHas('problems', fn ($problems) => count($problems) === 1);
    }

    public function test_exercise_index_page_loads_and_displays_exercises()
    {
        // 1. Arrange
        // is_public explicit: ContestFactory draws it from faker->boolean(50),
        // and this test acts as a GUEST, who sees a contest's problems only
        // when it is public (#135). Left to chance it passed half the time.
        $contest = Contest::factory()->create(['is_active' => true, 'is_public' => true]);
        Problem::factory()->create(['contest_id' => $contest->id, 'short_name' => 'A', 'name' => 'P1']);
        Problem::factory()->create(['contest_id' => $contest->id, 'short_name' => 'B', 'name' => 'P2']);

        // 2. Act
        $response = $this->get('/exercises');

        // 3. Assert
        $response->assertStatus(200);
        $response->assertViewIs('exercises.index');
        $response->assertViewHas('problems', function ($problems) {
            return count($problems) === 2;
        });
        $response->assertSeeText('P1');
        $response->assertSeeText('P2');
    }

    /**
     * Test the problem show page loads for a valid problem.
     *
     * @return void
     */
    public function test_exercise_show_page_loads_for_valid_exercise()
    {
        // 1. Arrange
        $contest = Contest::factory()->create(['is_active' => true, 'is_public' => true]);
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id,
            'name' => 'Detailed Problem',
        ]);

        // 2. Act
        $response = $this->get('/exercise/'.$problem->id);

        // 3. Assert
        $response->assertStatus(200);
        $response->assertViewIs('exercises.show');
        $response->assertViewHas('problem', function ($viewProblem) use ($problem) {
            return $viewProblem->id === $problem->id;
        });
        $response->assertSeeText('Detailed Problem');
    }

    /**
     * Test the problem show page returns 404 for an invalid problem.
     *
     * @return void
     */
    public function test_exercise_show_page_returns_404_for_invalid_exercise()
    {
        // 2. Act
        $response = $this->get('/exercise/9999');

        // 3. Assert
        $response->assertStatus(404);
    }

    /**
     * A problem belonging to a different, private contest than the one the
     * viewer would see in the /exercises list must not be viewable just by
     * guessing its numeric id -- ProblemController::show() had no
     * authorization check at all before this.
     */
    public function test_exercise_show_page_returns_404_for_a_problem_from_another_private_contest()
    {
        // The contest the anonymous viewer would actually see via resolveContest()
        Contest::factory()->create(['is_active' => true, 'is_public' => true]);

        $otherContest = Contest::factory()->create(['is_active' => false, 'is_public' => false]);
        $otherProblem = Problem::factory()->create(['contest_id' => $otherContest->id]);

        $response = $this->get('/exercise/'.$otherProblem->id);

        $response->assertStatus(404);
    }

    public function test_exercise_show_page_is_visible_for_a_problem_from_a_public_contest_even_if_not_current()
    {
        Contest::factory()->create(['is_active' => true, 'is_public' => true]);

        $publicOldContest = Contest::factory()->create(['is_active' => false, 'is_public' => true]);
        $problem = Problem::factory()->create(['contest_id' => $publicOldContest->id, 'name' => 'Old Public Problem']);

        $response = $this->get('/exercise/'.$problem->id);

        $response->assertStatus(200);
        $response->assertSeeText('Old Public Problem');
    }

    /**
     * Issue #137 -- the statement file, the whole point of the feature: a
     * problem imported from a BOCA package keeps its statement in
     * description/, and the team must be able to open it.
     */
    public function test_a_team_in_the_contest_can_open_the_imported_statement(): void
    {
        $contest = Contest::factory()->create([
            'is_active' => true, 'is_public' => false, 'start_time' => now()->subHour(),
        ]);
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id, 'short_name' => 'A', 'name' => 'Caixas',
            'basename' => 'caixas', 'description_file' => 'enunciado-interno.pdf',
        ]);
        $this->statementFile($problem, 'enunciado-interno.pdf');
        $team = $this->createTestUser(['contest_id' => $contest->id, 'user_type' => 'team']);

        $response = $this->actingAs($team)->get("/exercise/{$problem->id}/enunciado");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        // The name the team sees describes the problem, not the package's
        // internal file name and not the server path it came from.
        $disposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('a-caixas.pdf', $disposition);
        $this->assertStringNotContainsString('enunciado-interno', $disposition);
        $this->assertStringNotContainsString(storage_path(), $disposition);
    }

    /**
     * And the page actually links to it -- the bug in #137 was never the
     * absence of a file, it was that nothing on the team's screen led there.
     * Text and file together: the text is the summary, the file the document.
     */
    public function test_the_problem_page_links_to_the_statement_file(): void
    {
        $contest = Contest::factory()->create([
            'is_active' => true, 'is_public' => true, 'start_time' => now()->subHour(),
        ]);
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id, 'short_name' => 'B', 'name' => 'Pontes',
            'basename' => 'pontes', 'description' => 'Resumo curto do problema.',
            'description_file' => 'pontes.pdf',
        ]);
        $this->statementFile($problem, 'pontes.pdf');

        $response = $this->get("/exercise/{$problem->id}");

        $response->assertOk();
        $response->assertSee(route('exercise.statement', $problem), false);
        $response->assertSeeText('Resumo curto do problema.');
        $response->assertDontSeeText('O enunciado ainda não foi disponibilizado.');
    }

    /**
     * A problem with neither text nor file still says so, instead of offering
     * a link to nothing.
     */
    public function test_a_problem_with_no_statement_at_all_still_says_so(): void
    {
        $contest = Contest::factory()->create([
            'is_active' => true, 'is_public' => true, 'start_time' => now()->subHour(),
        ]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id, 'description' => null]);

        $response = $this->get("/exercise/{$problem->id}");

        $response->assertOk();
        $response->assertSeeText('O enunciado ainda não foi disponibilizado.');
        $response->assertDontSee(route('exercise.statement', $problem), false);
    }

    /**
     * The guard that matters: the statement is the problem. Someone who may
     * not see the problem may not read its statement either -- the file route
     * asks mayList() exactly like the listing and the detail page (#135).
     */
    public function test_a_guest_cannot_open_the_statement_of_a_private_contest(): void
    {
        $contest = Contest::factory()->create([
            'is_active' => true, 'is_public' => false, 'start_time' => now()->subHour(),
        ]);
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id, 'basename' => 'segredo', 'description_file' => 'segredo.pdf',
        ]);
        $this->statementFile($problem, 'segredo.pdf');

        // Note this IS the contest resolveContest() hands a guest -- active
        // and a competition. Being anonymous must not count as being in it.
        $this->get("/exercise/{$problem->id}/enunciado")->assertNotFound();
    }

    /**
     * ...and the same rule the other way: a public contest publishes its
     * statements to anyone, which is what is_public means.
     */
    public function test_a_guest_can_open_the_statement_of_a_public_contest(): void
    {
        $contest = Contest::factory()->create([
            'is_active' => true, 'is_public' => true, 'start_time' => now()->subHour(),
        ]);
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id, 'basename' => 'aberto', 'description_file' => 'aberto.pdf',
        ]);
        $this->statementFile($problem, 'aberto.pdf');

        $response = $this->get("/exercise/{$problem->id}/enunciado");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    /**
     * The clock, not just the visibility rule: a contest may announce its
     * problem list before it starts, but handing out the papers early hands
     * out the competition. Staff are exempt -- they wrote them.
     */
    public function test_the_statement_is_withheld_until_the_contest_starts(): void
    {
        $contest = Contest::factory()->create([
            'is_active' => true, 'is_public' => true, 'start_time' => now()->addHours(2),
        ]);
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id, 'basename' => 'amanha', 'description_file' => 'amanha.pdf',
        ]);
        $this->statementFile($problem, 'amanha.pdf');
        $team = $this->createTestUser(['contest_id' => $contest->id, 'user_type' => 'team']);

        $this->actingAs($team)->get("/exercise/{$problem->id}/enunciado")->assertNotFound();

        $page = $this->actingAs($team)->get("/exercise/{$problem->id}");
        $page->assertOk();
        $page->assertSeeText('O enunciado completo será liberado no início da prova.');
        $page->assertDontSee(route('exercise.statement', $problem), false);

        $this->actingAs($this->createAdminUser())
            ->get("/exercise/{$problem->id}/enunciado")
            ->assertOk();
    }

    /**
     * The column and the disk disagree all the time -- a database restored
     * next to an empty storage/, a package imported on another machine. That
     * is an ordinary state of the world, not a server fault.
     */
    public function test_a_statement_file_missing_from_disk_is_a_404_not_a_500(): void
    {
        $contest = Contest::factory()->create([
            'is_active' => true, 'is_public' => true, 'start_time' => now()->subHour(),
        ]);
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id, 'basename' => 'fantasma', 'description_file' => 'nunca-chegou.pdf',
        ]);

        $this->get("/exercise/{$problem->id}/enunciado")->assertNotFound();

        // ...and the page does not offer a link to a document nobody can open.
        $page = $this->get("/exercise/{$problem->id}");
        $page->assertOk();
        $page->assertDontSee(route('exercise.statement', $problem), false);
    }

    /**
     * description_file is package input (problem.info's `descfile`), and
     * packages arrive by upload and by GitHub import. It must not be able to
     * name a file outside its own description/ directory.
     */
    public function test_a_descfile_cannot_escape_the_package_directory(): void
    {
        $contest = Contest::factory()->create([
            'is_active' => true, 'is_public' => true, 'start_time' => now()->subHour(),
        ]);
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id, 'basename' => 'travessia', 'description_file' => '../input/1',
        ]);
        // A complete package on disk -- description/ has to exist for
        // "description/../input/1" to resolve at all -- plus a real file at
        // the path the traversal aims for, so this fails loudly if the guard
        // ever stops pinning the name inside description/.
        $this->statementFile($problem, 'travessia.pdf');
        $inputDir = storage_path("app/problems/{$contest->id}/travessia/input");
        @mkdir($inputDir, 0755, true);
        file_put_contents($inputDir.'/1', 'segredo do juiz');
        $this->statementDirs[] = $inputDir;

        $this->get("/exercise/{$problem->id}/enunciado")->assertNotFound();
    }
}
