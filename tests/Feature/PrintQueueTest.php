<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Site;
use App\Models\Task;
use Helium\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Issue #94 -- a team sends a file, the staff at their site pick it up.
 *
 * The other half of #87: tasks.filename and tasks.file_path existed from
 * the original migration with no producer at all.
 */
class PrintQueueTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create([
            'start_time' => now()->subMinutes(30),
            'duration' => 300,
            'is_active' => true,
        ]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
    }

    private function team(?Site $site = null): User
    {
        return User::factory()->create([
            'user_type' => 'team',
            'contest_id' => $this->contest->id,
            'site_id' => ($site ?? $this->site)->id,
        ]);
    }

    private function staff(?Site $site = null): User
    {
        return User::factory()->create([
            'user_type' => 'staff',
            'contest_id' => $this->contest->id,
            'site_id' => ($site ?? $this->site)->id,
        ]);
    }

    private function send(User $team, ?UploadedFile $file = null, array $extra = [])
    {
        return $this->actingAs($team)->post('/print', array_merge([
            'file' => $file ?? UploadedFile::fake()->createWithContent('solucao.cpp', "int main(){}\n"),
        ], $extra));
    }

    public function test_a_team_sends_a_file_and_it_lands_in_their_sites_queue(): void
    {
        Storage::fake('local');
        $team = $this->team();

        $this->send($team, extra: ['description' => 'frente e verso'])->assertRedirect(route('print.create'));

        $task = Task::firstOrFail();
        $this->assertFalse($task->is_system);
        $this->assertSame($this->site->id, $task->site_id);
        $this->assertSame($team->user_id, $task->user_id);
        $this->assertSame('solucao.cpp', $task->filename);
        $this->assertNotNull($task->file_path);
        $this->assertSame('pending', $task->status);
        $this->assertNull($task->problem_id);
        $this->assertStringContainsString('frente e verso', $task->description);
        Storage::disk('local')->assertExists($task->file_path);
    }

    public function test_only_a_team_can_send(): void
    {
        $this->post('/print')->assertRedirect(route('login'));

        $this->actingAs($this->staff());
        $this->post('/print')->assertForbidden();
    }

    public function test_nothing_queues_outside_a_running_contest(): void
    {
        Storage::fake('local');
        $this->contest->update(['is_active' => false]);

        $this->send($this->team())->assertSessionHasErrors('file');
        $this->assertSame(0, Task::count());
    }

    public function test_an_oversized_file_is_refused(): void
    {
        Storage::fake('local');
        config(['printing.max_file_size_kb' => 4]);

        $this->send($this->team(), UploadedFile::fake()->create('grande.txt', 64))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Task::count());
    }

    public function test_an_extension_outside_the_allowlist_is_refused(): void
    {
        Storage::fake('local');

        // "Print this" is not a reason to accept an arbitrary binary onto
        // the staff machine that opens it.
        $this->send($this->team(), UploadedFile::fake()->create('payload.exe', 8))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Task::count());
    }

    public function test_a_traversing_filename_cannot_escape_the_storage_directory(): void
    {
        Storage::fake('local');
        $team = $this->team();

        $this->send($team, UploadedFile::fake()->createWithContent('../../../../etc/cron.d/evil.txt', 'x'));

        $task = Task::firstOrFail();
        $this->assertStringNotContainsString('..', $task->file_path);
        $this->assertStringStartsWith("prints/{$this->contest->id}/{$team->user_id}/", $task->file_path);
    }

    public function test_print_and_balloon_tasks_share_one_numbering_per_site(): void
    {
        Storage::fake('local');

        $this->send($this->team())->assertRedirect();
        $this->send($this->team())->assertRedirect();

        $this->assertSame([1, 2], Task::orderBy('id')->pluck('task_number')->all());
    }

    // --- the staff side ---------------------------------------------------

    public function test_staff_download_the_file_as_an_attachment(): void
    {
        Storage::fake('local');
        $this->send($this->team(), UploadedFile::fake()->createWithContent('codigo.py', "print(1)\n"));
        $task = Task::firstOrFail();

        $response = $this->actingAs($this->staff())
            ->get(route('staff.tasks.file', $task))
            ->assertOk();

        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame("print(1)\n", $response->streamedContent());
    }

    public function test_staff_from_another_site_cannot_download_it(): void
    {
        Storage::fake('local');
        $otherSite = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->send($this->team($otherSite));
        $task = Task::firstOrFail();

        // The file is a competitor's source during a live contest.
        $this->actingAs($this->staff())
            ->get(route('staff.tasks.file', $task))
            ->assertForbidden();
    }

    public function test_a_team_cannot_download_from_the_staff_route(): void
    {
        Storage::fake('local');
        $this->send($this->team());
        $task = Task::firstOrFail();

        $this->actingAs($this->team())
            ->get(route('staff.tasks.file', $task))
            ->assertForbidden();
    }

    public function test_a_balloon_task_has_no_file_to_download(): void
    {
        $balloon = Task::create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $this->team()->user_id,
            'task_number' => 1,
            'description' => 'Balao do problema A',
            'contest_time' => 0,
            'status' => 'pending',
            'is_system' => true,
        ]);

        $this->actingAs($this->staff())
            ->get(route('staff.tasks.file', $balloon))
            ->assertNotFound();
    }

    public function test_a_team_sees_their_own_requests_and_not_another_teams(): void
    {
        Storage::fake('local');
        $mine = $this->team();
        $theirs = $this->team();

        $this->send($mine, UploadedFile::fake()->createWithContent('meu.txt', 'a'));
        $this->send($theirs, UploadedFile::fake()->createWithContent('deles.txt', 'b'));

        $response = $this->actingAs($mine)->get('/print')->assertOk();
        $response->assertSeeText('meu.txt');
        $response->assertDontSeeText('deles.txt');
    }
}
