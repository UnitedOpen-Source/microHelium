<?php

namespace Tests\Integration\Api;

use App\Models\Clarification;
use App\Models\Contest;
use App\Models\Problem;
use App\Models\Site;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClarificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_for_admin()
    {
        $contest = Contest::factory()->create();
        $admin = User::factory()->create(['user_type' => 'admin']);
        Clarification::factory()->count(5)->create(['contest_id' => $contest->id]);
        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/clarifications?contest_id={$contest->id}");
        $response->assertStatus(200)->assertJsonCount(5, 'data');
    }

    public function test_index_for_user()
    {
        $contest = Contest::factory()->create();
        // Member of the contest: since issue #134 a team reads broadcasts
        // only from contests it may see, and ContestFactory's is_public is
        // not something this test should be depending on either way.
        $user = User::factory()->create(['contest_id' => $contest->id]);
        Clarification::factory()->count(3)->create(['contest_id' => $contest->id, 'user_id' => $user->user_id]);
        Clarification::factory()->count(2)->create(['contest_id' => $contest->id, 'status' => 'broadcast_all']);
        Clarification::factory()->count(1)->create(['contest_id' => $contest->id]); // Private one

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/clarifications?contest_id={$contest->id}");
        $response->assertStatus(200)->assertJsonCount(5, 'data'); // 3 own + 2 broadcast
    }

    /**
     * Issue #65: despite the name, broadcast_site was visible contest-wide,
     * identical to broadcast_all -- it must only be visible to teams at the
     * same site the clarification was answered for.
     */
    public function test_broadcast_site_clarification_is_only_visible_to_teams_at_the_same_site()
    {
        $contest = Contest::factory()->create();
        $siteA = Site::factory()->create(['contest_id' => $contest->id]);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);
        $teamAtSiteA = User::factory()->create(['site_id' => $siteA->id]);
        $teamAtSiteB = User::factory()->create(['site_id' => $siteB->id]);

        Clarification::factory()->create([
            'contest_id' => $contest->id,
            'site_id' => $siteA->id,
            'status' => 'broadcast_site',
        ]);

        Sanctum::actingAs($teamAtSiteA);
        $this->getJson("/api/clarifications?contest_id={$contest->id}")
            ->assertStatus(200)->assertJsonCount(1, 'data');

        Sanctum::actingAs($teamAtSiteB);
        $this->getJson("/api/clarifications?contest_id={$contest->id}")
            ->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_broadcast_all_clarification_is_visible_regardless_of_site()
    {
        $contest = Contest::factory()->create();
        $siteA = Site::factory()->create(['contest_id' => $contest->id]);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);
        $teamAtSiteB = User::factory()->create(['site_id' => $siteB->id]);

        Clarification::factory()->create([
            'contest_id' => $contest->id,
            'site_id' => $siteA->id,
            'status' => 'broadcast_all',
        ]);

        Sanctum::actingAs($teamAtSiteB);
        $this->getJson("/api/clarifications?contest_id={$contest->id}")
            ->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_store_clarification()
    {
        $contest = Contest::factory()->has(Site::factory())->create(['is_active' => true, 'start_time' => now()]);
        $user = User::factory()->create(['site_id' => $contest->sites()->first()->id]);
        Sanctum::actingAs($user);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $data = [
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'question' => 'This is a test question.',
        ];
        $response = $this->postJson('/api/clarifications', $data);
        $response->assertStatus(201)->assertJsonPath('question', $data['question']);
    }

    /**
     * A pergunta que nao e sobre problema nenhum -- "meu teclado quebrou",
     * "que horas acaba" -- respondia 500.
     *
     * `problem_id` e `nullable`, e uma regra `nullable` cujo campo nao foi
     * enviado nao entra em $validated; o controller lia a chave direto.
     * O teste de sucesso acima sempre mandou problem_id e o de contest
     * fechado para no 422 antes de chegar la, entao o caminho ficou sem
     * cobertura desde que existe. Ver issue #197.
     */
    public function test_store_clarification_without_a_problem()
    {
        $contest = Contest::factory()->has(Site::factory())->create(['is_active' => true, 'start_time' => now()]);
        $user = User::factory()->create(['site_id' => $contest->sites()->first()->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/clarifications', [
            'contest_id' => $contest->id,
            'question' => 'Meu teclado parou de funcionar.',
        ])->assertStatus(201)->assertJsonPath('problem_id', null);
    }

    public function test_store_fails_if_contest_not_running()
    {
        $contest = Contest::factory()->create(['is_active' => false]);
        // A member, so that the refusal under test is the closed contest
        // and not issue #134's "you do not compete here".
        $user = User::factory()->create(['contest_id' => $contest->id]);
        Sanctum::actingAs($user);
        $data = ['contest_id' => $contest->id, 'question' => 'wont work'];
        $response = $this->postJson('/api/clarifications', $data);
        $response->assertStatus(422);
    }

    public function test_show_clarification_for_admin()
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $clarification = Clarification::factory()->create();
        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/clarifications/{$clarification->id}");
        $response->assertStatus(200)->assertJsonPath('id', $clarification->id);
    }

    public function test_show_clarification_for_owner()
    {
        $user = User::factory()->create();
        $clarification = Clarification::factory()->create(['user_id' => $user->user_id]);
        Sanctum::actingAs($user);
        $response = $this->getJson("/api/clarifications/{$clarification->id}");
        $response->assertStatus(200);
    }

    public function test_show_clarification_unauthorized()
    {
        $user = User::factory()->create();
        $clarification = Clarification::factory()->create(); // Belongs to another user
        Sanctum::actingAs($user);
        $response = $this->getJson("/api/clarifications/{$clarification->id}");
        $response->assertStatus(403);
    }

    public function test_answer_clarification()
    {
        $contest = Contest::factory()->create();
        $judge = User::factory()->create(['user_type' => 'judge', 'contest_id' => $contest->id]);
        $clarification = Clarification::factory()->create(['contest_id' => $contest->id]);
        Sanctum::actingAs($judge);
        $data = ['answer' => 'This is the answer.'];
        $response = $this->putJson("/api/clarifications/{$clarification->id}/answer", $data);
        $response->assertStatus(200)->assertJsonPath('answer', $data['answer']);
    }

    /**
     * Issue #64/#66: answer() now enforces the same contest-scoping as the
     * web UI's equivalent -- a judge assigned to a different contest can't
     * answer this clarification just by guessing/incrementing its id.
     */
    public function test_answer_clarification_forbidden_for_judge_in_another_contest()
    {
        $judge = User::factory()->create(['user_type' => 'judge', 'contest_id' => Contest::factory()]);
        $clarification = Clarification::factory()->create();
        Sanctum::actingAs($judge);
        $data = ['answer' => 'Wrong contest.'];
        $response = $this->putJson("/api/clarifications/{$clarification->id}/answer", $data);
        $response->assertStatus(403);
    }

    public function test_answer_clarification_with_broadcast()
    {
        $contest = Contest::factory()->create();
        $judge = User::factory()->create(['user_type' => 'judge', 'contest_id' => $contest->id]);
        Sanctum::actingAs($judge);

        // Test site broadcast
        $clarification1 = Clarification::factory()->create(['contest_id' => $contest->id]);
        $data1 = ['answer' => 'Answer 1', 'broadcast' => 'site'];
        $this->putJson("/api/clarifications/{$clarification1->id}/answer", $data1)
            ->assertStatus(200)
            ->assertJsonPath('status', 'broadcast_site');

        // Test all broadcast
        $clarification2 = Clarification::factory()->create(['contest_id' => $contest->id]);
        $data2 = ['answer' => 'Answer 2', 'broadcast' => 'all'];
        $this->putJson("/api/clarifications/{$clarification2->id}/answer", $data2)
            ->assertStatus(200)
            ->assertJsonPath('status', 'broadcast_all');
    }

    /**
     * Issue #64: answer() previously enforced no role check beyond
     * auth:sanctum, so any authenticated team could answer any clarification.
     */
    public function test_answer_clarification_forbidden_for_team()
    {
        $team = User::factory()->create(['user_type' => 'team']);
        $clarification = Clarification::factory()->create();
        Sanctum::actingAs($team);
        $data = ['answer' => 'A team should not be able to do this.'];
        $response = $this->putJson("/api/clarifications/{$clarification->id}/answer", $data);
        $response->assertStatus(403);
    }

    public function test_destroy_clarification()
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $clarification = Clarification::factory()->create();
        Sanctum::actingAs($admin);
        $response = $this->deleteJson("/api/clarifications/{$clarification->id}");
        $response->assertStatus(204);
        $this->assertSoftDeleted('clarifications', ['id' => $clarification->id]);
    }

    public function test_pending_clarifications()
    {
        $contest = Contest::factory()->create();
        $judge = User::factory()->create(['user_type' => 'judge']);
        Clarification::factory()->count(3)->create(['contest_id' => $contest->id, 'status' => 'pending']);
        Clarification::factory()->count(2)->create(['contest_id' => $contest->id, 'status' => 'answered']);
        Sanctum::actingAs($judge);
        $response = $this->getJson("/api/clarifications/pending?contest_id={$contest->id}");
        // 'data', e nao a raiz. A raiz passou a ser um envelope com tres
        // chaves (data, predefined_answers, categories) na issue #197, e
        // assertJsonCount(3) sobre ela continuou verde contando o envelope
        // -- tres chaves, tres pendentes, mesmo numero por coincidencia.
        // Passaria com zero clarificacoes no banco.
        $response->assertStatus(200)->assertJsonCount(3, 'data');
    }
}
