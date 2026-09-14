<?php

namespace Tests\Feature\Api;

use App\Models\Contest;
use App\Models\Problem;
use App\Models\TestCase as ProblemTestCase;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #153 -- GET /api/problems/{problem} must not hand a competitor an
 * oracle for the hidden test data.
 *
 * The question this file asks is deliberately not "does the response have a
 * testCases key". A serializer change, a resource class, an accessor, an
 * appended attribute or a future eager load elsewhere could all put the
 * digests back under some other name, and an assertJsonMissing('testCases')
 * would stay green through every one of them. So the assertion is made
 * against the raw response body and against the values themselves: the
 * sha256 of the hidden input and the sha256 of the expected output are
 * generated here with contents only this test knows, and the claim is that
 * neither string appears anywhere in what the team is handed -- nor do the
 * paths that say where the files live.
 *
 * Why a digest is worth this much care: it is not the file, but it verifies
 * a guess at the file. Blind guessing of a hidden input is hopeless;
 * checking candidate after candidate against a known sha256 is a loop, and
 * on a problem whose input format is tight that loop finishes. output_hash
 * is worse in the same direction -- it confirms the expected answer without
 * solving anything. #134 moved /problems/{problem}/export to the staff side
 * because a package carries input/ and output/ outright; this is the same
 * disclosure compressed to 64 hex characters.
 *
 * Mutation-checked: restoring `load(['contest', 'testCases'])` in
 * Api\ProblemController::show() turns
 * test_a_competing_team_is_never_handed_a_test_case_digest red on the
 * input_hash assertion.
 */
class ProblemTestCaseDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private Contest $contest;

    private Problem $problem;

    /** @var array<string, string> the two digests and the two paths, by name */
    private array $secrets;

    protected function setUp(): void
    {
        parent::setUp();

        // Private and running: the live-contest case, where the team is a
        // legitimate member and the problem is one it is meant to be
        // reading. Nothing about visibility is what should be withholding
        // the digests here -- the team may absolutely see this problem.
        $this->contest = Contest::factory()->create([
            'is_public' => false,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
        ]);

        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id]);

        $hiddenInput = "1000000007 42\n";
        $expectedOutput = "13\n";

        $case = ProblemTestCase::factory()->create([
            'problem_id' => $this->problem->id,
            'number' => 1,
            'is_sample' => false,
            'input_file' => "problems/{$this->contest->id}/{$this->problem->basename}/input/1",
            'output_file' => "problems/{$this->contest->id}/{$this->problem->basename}/output/1",
            'input_hash' => hash('sha256', $hiddenInput),
            'output_hash' => hash('sha256', $expectedOutput),
        ]);

        // A second case, so that test_cases_count has something to be wrong
        // about: a hard-coded 1 would pass against a single row.
        ProblemTestCase::factory()->create([
            'problem_id' => $this->problem->id,
            'number' => 2,
            'is_sample' => false,
        ]);

        $this->secrets = [
            'input_hash' => $case->input_hash,
            'output_hash' => $case->output_hash,
            'input_file' => $case->input_file,
            'output_file' => $case->output_file,
        ];
    }

    public function test_a_competing_team_is_never_handed_a_test_case_digest(): void
    {
        Sanctum::actingAs($this->competitor());

        $response = $this->getJson("/api/problems/{$this->problem->id}");

        // The team is in the contest, so this is a 200 and not a 404 -- the
        // point is what a legitimate read contains, not that the read is
        // refused.
        $response->assertStatus(200);

        $body = $response->getContent();

        foreach ($this->secrets as $name => $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $body,
                "GET /api/problems/{id} handed a competing team the {$name} of a hidden test case. "
                .'A sha256 of the hidden input lets a guess be verified instead of the problem being solved '
                .'(issue #153); the file paths say where the data lives.'
            );
        }
    }

    public function test_the_problem_still_tells_a_team_how_many_test_cases_there_are(): void
    {
        Sanctum::actingAs($this->competitor());

        // The count is legitimate -- it is how long a judgement runs, and
        // JudgeWorkQueue::workPayload() already publishes exactly this to
        // the judgehosts with no digest attached. Removing the relation
        // must not take it away, or the fix has cost the team something it
        // was entitled to.
        $this->getJson("/api/problems/{$this->problem->id}")
            ->assertStatus(200)
            ->assertJsonPath('test_cases_count', 2);
    }

    public function test_a_competing_team_cannot_reach_the_staff_test_case_route(): void
    {
        Sanctum::actingAs($this->competitor());

        // Moving the material behind role:judge,admin is only a fix if the
        // new door is shut: 403 for the same team that may read the
        // problem itself.
        $this->getJson("/api/problems/{$this->problem->id}/test-cases")
            ->assertStatus(403);
    }

    public function test_a_judge_can_still_read_the_full_test_case_detail(): void
    {
        Sanctum::actingAs($this->createTestUser([
            'user_type' => 'judge',
            'contest_id' => $this->contest->id,
        ]));

        $response = $this->getJson("/api/problems/{$this->problem->id}/test-cases");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.input_hash', $this->secrets['input_hash'])
            ->assertJsonPath('data.0.output_hash', $this->secrets['output_hash']);
    }

    /**
     * A team registered in the contest that owns the problem -- the case
     * #134 deliberately opened up, and therefore the case where the
     * disclosure was real.
     */
    private function competitor(): User
    {
        return $this->createTestUser([
            'user_type' => 'team',
            'contest_id' => $this->contest->id,
        ]);
    }
}
