<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Services\Practice\PracticeContest;
use Tests\TestCase;

/**
 * Every verdict AutoJudgeService can reach needs a row in `answers`, or
 * updateRunWithResult() sets answer_id to null and the run ends up judged
 * with no verdict anyone can see.
 *
 * This is not hypothetical. Writing the MLE verdict (#86) it happened
 * immediately: a hand-written answer fixture had no MLE row, the run came
 * back with the right message and a null answer, and the failure read as
 * "nothing happened" rather than "you forgot a row". Three places seeded
 * this list and two of them were hand-written copies.
 */
class DefaultAnswersTest extends TestCase
{
    /**
     * Read back from the service rather than written down here on purpose.
     * A hardcoded expectation would need updating by the same person who
     * forgot the answer row, which is exactly the failure this guards.
     *
     * @return list<string>
     */
    private function verdictsTheJudgeCanEmit(): array
    {
        $source = file_get_contents(app_path('Services/AutoJudgeService.php'));

        preg_match_all("/'verdict'\s*=>\s*'([A-Z]+)'/", (string) $source, $matches);

        $verdicts = array_values(array_unique($matches[1]));
        sort($verdicts);

        $this->assertNotEmpty($verdicts, 'could not read any verdict out of AutoJudgeService');

        return $verdicts;
    }

    public function test_the_default_answers_cover_every_verdict_the_judge_can_emit(): void
    {
        $seeded = collect(Answer::getDefaultAnswers())->pluck('short_name')->all();

        foreach ($this->verdictsTheJudgeCanEmit() as $verdict) {
            $this->assertContains(
                $verdict,
                $seeded,
                "AutoJudgeService can return '{$verdict}' and Answer::getDefaultAnswers() has no row for it -- "
                .'a run earning that verdict would be saved with a null answer_id.'
            );
        }
    }

    public function test_exactly_one_default_answer_is_the_accepted_one(): void
    {
        $accepted = collect(Answer::getDefaultAnswers())->where('is_accepted', true);

        // Score::updateScore() and Leaderboard both key off is_accepted; two
        // accepted verdicts would quietly double-count a solve.
        $this->assertCount(1, $accepted);
        $this->assertSame('AC', $accepted->first()['short_name']);
    }

    public function test_a_contest_created_through_the_wizard_gets_all_of_them(): void
    {
        $this->actingAs($this->createAdminUser())
            ->post('/backend/contest-wizard', [
                'name' => 'Maratona de Teste',
                'start_time' => now()->addHour()->format('Y-m-d H:i:s'),
                'duration' => 300,
                'freeze_time' => 60,
                'penalty' => 20,
                'max_file_size' => 100,
                'problems' => [],
            ])->assertSessionHasNoErrors();

        $contest = Contest::query()->competition()->latest('id')->firstOrFail();

        $this->assertSame(
            collect(Answer::getDefaultAnswers())->pluck('short_name')->sort()->values()->all(),
            $contest->answers()->pluck('short_name')->sort()->values()->all()
        );
    }

    public function test_the_practice_contest_gets_all_of_them_too(): void
    {
        // Practice reuses the judging pipeline (#43), so it needs every
        // verdict the pipeline can produce just as much as an event does.
        $practice = app(PracticeContest::class)->contest();

        $this->assertSame(
            collect(Answer::getDefaultAnswers())->pluck('short_name')->sort()->values()->all(),
            $practice->answers()->pluck('short_name')->sort()->values()->all()
        );
    }
}
