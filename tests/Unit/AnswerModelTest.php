<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Answer;
use App\Models\Contest;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AnswerModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_answer_belongs_to_a_contest()
    {
        $contest = Contest::factory()->create();
        $answer = Answer::factory()->for($contest)->create();
        $this->assertInstanceOf(Contest::class, $answer->contest);
    }

    public function test_answer_has_many_runs()
    {
        $answer = Answer::factory()->create();
        Run::factory()->for($answer)->count(3)->create();
        $this->assertCount(3, $answer->runs);
    }

    public function test_get_default_answers()
    {
        $defaultAnswers = Answer::getDefaultAnswers();
        $this->assertIsArray($defaultAnswers);
        $this->assertGreaterThan(0, count($defaultAnswers));
        $this->assertArrayHasKey('name', $defaultAnswers[0]);
        $this->assertArrayHasKey('short_name', $defaultAnswers[0]);
        $this->assertArrayHasKey('is_accepted', $defaultAnswers[0]);
        $this->assertArrayHasKey('sort_order', $defaultAnswers[0]);
    }
}
