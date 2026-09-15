<?php

namespace Tests\Feature;

use App\Models\Clarification;
use App\Models\Contest;
use App\Models\Problem;
use App\Models\Site;
use Helium\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #197 -- which desk a clarification belongs to.
 *
 * "My keyboard is broken" and "the statement of problem C is ambiguous" go
 * to different people. Until now both landed in one queue, with a null
 * `problem_id` as the only hint that the first was not about a problem at
 * all.
 *
 * The CCS requirements name the set: General, SysOps, Operations, "and one
 * category per problem". The per-problem half already existed as
 * `problem_id`; these three are the rest. They also ask for predefined
 * answers, "including 'No comment, read problem statement.'" -- most
 * contest questions are answered with that sentence, and typing it forty
 * times by hand is how the queue falls behind.
 */
class ClarificationCategoryTest extends TestCase
{
    private Contest $contest;

    private Problem $problem;

    private User $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create([
            'is_active' => true,
            'start_time' => now()->subHour(),
            'duration' => 300,
        ]);
        $site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'C']);
        $this->team = $this->createTestUser([
            'user_type' => 'team',
            'contest_id' => $this->contest->id,
            'site_id' => $site->id,
        ]);
    }

    private function ask(array $payload): int
    {
        Sanctum::actingAs($this->team);

        return (int) $this->postJson('/api/clarifications', array_merge([
            'contest_id' => $this->contest->id,
            'question' => 'Uma pergunta.',
        ], $payload))->assertStatus(201)->json('id');
    }

    public function test_a_question_carries_the_desk_it_is_addressed_to(): void
    {
        $id = $this->ask(['category' => Clarification::CATEGORY_SYSOPS, 'question' => 'O teclado quebrou.']);

        $this->assertSame(Clarification::CATEGORY_SYSOPS, Clarification::find($id)->category);
    }

    /**
     * Every row answers the question, including one asked without saying.
     * A nullable category would mean "old" in some places and "general" in
     * others, and the filter would have to know which.
     */
    public function test_a_question_without_a_category_is_general(): void
    {
        $id = $this->ask([]);

        $this->assertSame(Clarification::CATEGORY_GENERAL, Clarification::find($id)->category);
    }

    public function test_an_unknown_category_is_refused(): void
    {
        Sanctum::actingAs($this->team);

        $this->postJson('/api/clarifications', [
            'contest_id' => $this->contest->id,
            'question' => 'Uma pergunta.',
            'category' => 'mesa-inventada',
        ])->assertStatus(422);
    }

    public function test_the_jury_queue_filters_by_desk(): void
    {
        $this->ask(['category' => Clarification::CATEGORY_SYSOPS, 'question' => 'O teclado quebrou.']);
        $this->ask(['category' => Clarification::CATEGORY_GENERAL, 'question' => 'Que horas acaba?']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->createTestUser(['user_type' => 'judge', 'contest_id' => $this->contest->id]));

        $sysops = $this->getJson('/api/clarifications/pending?category=sysops')->assertStatus(200)->json('data');

        $this->assertCount(1, $sysops);
        $this->assertSame('O teclado quebrou.', $sysops[0]['question']);
    }

    /**
     * A question about a problem is categorised by that problem -- the
     * "one category per problem" half of the requirement, which is what
     * problem_id has always been.
     */
    public function test_the_jury_queue_filters_by_problem(): void
    {
        $this->ask(['problem_id' => $this->problem->id, 'question' => 'O enunciado do C e ambiguo.']);
        $this->ask(['question' => 'Que horas acaba?']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->createTestUser(['user_type' => 'judge', 'contest_id' => $this->contest->id]));

        $aboutC = $this->getJson("/api/clarifications/pending?problem_id={$this->problem->id}")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $aboutC);
        $this->assertSame('O enunciado do C e ambiguo.', $aboutC[0]['question']);
    }

    /**
     * The shortcuts ride with the queue rather than on a route of their
     * own: whoever opens the queue is exactly who uses them.
     */
    public function test_the_queue_carries_the_predefined_answers(): void
    {
        Sanctum::actingAs($this->createTestUser(['user_type' => 'judge', 'contest_id' => $this->contest->id]));

        $body = $this->getJson('/api/clarifications/pending')->assertStatus(200)->json();

        $this->assertNotEmpty($body['predefined_answers']);
        $this->assertSame(
            'Sem comentarios. Leia o enunciado.',
            $body['predefined_answers'][0],
            'the answer the CCS requirements name by example is not the first shortcut'
        );
        $this->assertSame(Clarification::CATEGORIES, $body['categories']);
    }
}
