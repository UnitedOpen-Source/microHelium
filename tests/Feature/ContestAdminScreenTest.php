<?php

namespace Tests\Feature;

use App\Models\Contest;
use Helium\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #190 -- the admin contest screen ran on the legacy `hackathons`
 * table.
 *
 * #33 moved the wizard's WRITE onto `contests` and stopped there. The read
 * path stayed on the 2017 table, so a contest created by any other route --
 * `POST /api/contests`, the declarative event importer (#147), a seeder,
 * the practice contest (#43) -- had a `contests` row, no `hackathons` row,
 * and was therefore invisible on the listing and unreachable for editing.
 *
 * The two tables were also only in step by auto-increment coincidence:
 * everything downstream assumed `hackathons.hackathon_id == contests.id`,
 * and the activate route already operated on `contests` with an id taken
 * from the `hackathons` listing.
 */
class ContestAdminScreenTest extends TestCase
{
    private function admin(): User
    {
        return $this->createTestUser(['user_type' => 'admin']);
    }

    /**
     * The reproduction from the issue, as a test.
     */
    public function test_a_contest_created_outside_the_wizard_is_listed_and_editable(): void
    {
        $contest = Contest::factory()->create(['name' => 'Regional 2026 via API']);

        $this->assertSame(
            0,
            DB::table('hackathons')->count(),
            'the fixture is meant to have no legacy row -- that is the whole point'
        );

        $this->actingAs($this->admin())
            ->get('/backend/configurations')
            ->assertStatus(200)
            ->assertSee('Regional 2026 via API');

        $this->actingAs($this->admin())
            ->get("/backend/contest/{$contest->id}/edit")
            ->assertStatus(200)
            ->assertSee('Regional 2026 via API');
    }

    public function test_editing_writes_the_contest_and_leaves_no_legacy_row_behind(): void
    {
        $contest = Contest::factory()->create(['name' => 'Nome antigo', 'duration' => 300]);

        $this->actingAs($this->admin())
            ->put("/backend/contest/{$contest->id}/update", [
                'name' => 'Nome novo',
                'description' => 'Descricao',
                'start_time' => now()->addDay()->format('Y-m-d\TH:i'),
                'duration' => 300,
                'penalty' => 20,
                'freeze_time' => 60,
                'max_file_size' => 100,
            ])
            ->assertRedirect(route('backend.configurations'));

        $this->assertSame('Nome novo', $contest->fresh()->name);
        $this->assertSame(0, DB::table('hackathons')->count());
    }

    /**
     * The uniqueness check used to run against `hackathons`, which has no
     * row for a contest created by the API -- so two contests could end up
     * sharing a name with nobody warned.
     */
    public function test_a_duplicate_name_is_refused_against_contests_not_the_legacy_table(): void
    {
        $existing = Contest::factory()->create(['name' => 'Regional 2026']);
        $editing = Contest::factory()->create(['name' => 'Outro nome']);

        $this->actingAs($this->admin())
            ->put("/backend/contest/{$editing->id}/update", [
                'name' => 'Regional 2026',
                'start_time' => now()->addDay()->format('Y-m-d\TH:i'),
                'duration' => 300,
            ])
            ->assertSessionHas('error');

        $this->assertSame('Outro nome', $editing->fresh()->name);
        $this->assertSame('Regional 2026', $existing->fresh()->name);
    }

    public function test_deleting_removes_the_contest(): void
    {
        $contest = Contest::factory()->create();

        $this->actingAs($this->admin())
            ->delete("/backend/contest/{$contest->id}/delete")
            ->assertRedirect(route('backend.configurations'));

        $this->assertDatabaseMissing('contests', ['id' => $contest->id]);
    }

    public function test_an_unknown_contest_still_says_so_instead_of_rendering_an_empty_form(): void
    {
        $this->actingAs($this->admin())
            ->get('/backend/contest/999999/edit')
            ->assertRedirect(route('backend.configurations'))
            ->assertSessionHas('error');
    }

    /**
     * The wizard is the one path that used to write both tables. It must
     * now write exactly one, and the contest it creates must be visible on
     * the screen it came from.
     */
    public function test_the_wizard_creates_a_contest_and_no_legacy_row(): void
    {
        $this->actingAs($this->admin())
            ->post('/backend/contest-wizard', [
                'name' => 'Seletiva interna',
                'description' => 'Treino',
                'start_time' => now()->addDay()->format('Y-m-d\TH:i'),
                'duration' => 300,
                'freeze_time' => 60,
                'penalty' => 20,
                'max_file_size' => 100,
            ]);

        $this->assertDatabaseHas('contests', ['name' => 'Seletiva interna']);
        $this->assertSame(0, DB::table('hackathons')->count(), 'the wizard still wrote the legacy table');

        $this->actingAs($this->admin())
            ->get('/backend/configurations')
            ->assertSee('Seletiva interna');
    }
}
