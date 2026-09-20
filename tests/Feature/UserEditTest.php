<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Site;
use Helium\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Issue #100 -- editing an account was not possible at all: UserController
 * had store() and destroy() and nothing in between, so the only way to fix
 * a typo was to delete the account and take its runs, scores, tasks and
 * logs with it.
 */
class UserEditTest extends TestCase
{
    private function target(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'user_type' => 'team',
            'fullname' => 'Nome Errado',
            'is_enabled' => true,
        ], $attributes));
    }

    private function payload(User $user, array $overrides = []): array
    {
        return array_merge([
            'fullname' => $user->fullname,
            'username' => $user->username,
            'email' => $user->email,
            'user_type' => $user->user_type,
            'site_id' => $user->site_id,
            'icpc_id' => $user->icpc_id,
            'label' => $user->label,
            'is_enabled' => $user->is_enabled ? '1' : '0',
        ], $overrides);
    }

    public function test_only_an_admin_reaches_the_edit_screen(): void
    {
        $user = $this->target();

        $this->get(route('backend.users.edit', $user->user_id))->assertRedirect();

        $this->actingAs($this->target());
        $this->get(route('backend.users.edit', $user->user_id))->assertForbidden();
    }

    public function test_an_admin_fixes_a_name_without_deleting_the_account(): void
    {
        $user = $this->target();

        $this->actingAs($this->createAdminUser())
            ->put(route('backend.users.update', $user->user_id), $this->payload($user, ['fullname' => 'Nome Certo']))
            ->assertRedirect(route('backend.users'));

        $this->assertSame('Nome Certo', $user->fresh()->fullname);
        // The account is the same row: everything pointing at it survives.
        $this->assertSame($user->user_id, $user->fresh()->user_id);
    }

    public function test_saving_without_changing_the_username_does_not_collide_with_itself(): void
    {
        $user = $this->target();

        // Rule::unique() without ignore() would fail here on the user's own
        // record -- the mistake this test exists to catch.
        $this->actingAs($this->createAdminUser())
            ->put(route('backend.users.update', $user->user_id), $this->payload($user, ['fullname' => 'Outro Nome']))
            ->assertSessionHasNoErrors();
    }

    public function test_another_accounts_username_is_still_refused(): void
    {
        $taken = $this->target();
        $user = $this->target();

        $this->actingAs($this->createAdminUser())
            ->put(route('backend.users.update', $user->user_id), $this->payload($user, ['username' => $taken->username]))
            ->assertSessionHasErrors('username');
    }

    public function test_an_empty_password_leaves_the_current_one_alone(): void
    {
        $user = $this->target(['password' => Hash::make('senha-original')]);
        $before = $user->password;

        $this->actingAs($this->createAdminUser())
            ->put(route('backend.users.update', $user->user_id), $this->payload($user, ['password' => '']));

        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_a_new_password_is_hashed(): void
    {
        $user = $this->target();

        $this->actingAs($this->createAdminUser())
            ->put(route('backend.users.update', $user->user_id), $this->payload($user, ['password' => 'senha-nova-123']));

        $this->assertTrue(Hash::check('senha-nova-123', $user->fresh()->password));
    }

    public function test_the_icpc_id_can_finally_be_filled_in_for_an_existing_account(): void
    {
        // #89 added the field to the create form; an account that already
        // existed had no way to receive one, which is exactly the column the
        // ICPC report needs filled on every team.
        $user = $this->target(['icpc_id' => null]);

        $this->actingAs($this->createAdminUser())
            ->put(route('backend.users.update', $user->user_id), $this->payload($user, ['icpc_id' => 'ICPC-4242']));

        $this->assertSame('ICPC-4242', $user->fresh()->icpc_id);
    }

    /**
     * Issue #332 -- `teams.label` da Contest API precisa de um caminho de
     * entrada, ou a coluna e um mecanismo que nao pode funcionar e o schema
     * fica satisfeito por um padrao que ninguem escolheu.
     *
     * O rotulo vai ao telao da cerimonia. Quem o digita e a banca, aqui.
     */
    public function test_the_team_label_reaches_the_column_through_the_form(): void
    {
        $user = $this->target(['label' => null]);

        $this->actingAs($this->createAdminUser())
            ->put(route('backend.users.update', $user->user_id), $this->payload($user, ['label' => 'B12']));

        $this->assertSame('B12', $user->fresh()->label);
    }

    /**
     * E o campo deixado em branco grava NULO, e nao string vazia: e o nulo
     * que faz a Contest API cair no padrao.
     */
    public function test_an_empty_label_field_stores_null(): void
    {
        $user = $this->target(['label' => 'B12']);

        $this->actingAs($this->createAdminUser())
            ->put(route('backend.users.update', $user->user_id), $this->payload($user, ['label' => '']));

        $this->assertNull($user->fresh()->label);
    }

    public function test_the_contest_follows_the_chosen_site(): void
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $user = $this->target();

        $this->actingAs($this->createAdminUser())
            ->put(route('backend.users.update', $user->user_id), $this->payload($user, ['site_id' => $site->id]));

        $this->assertSame($site->id, $user->fresh()->site_id);
        $this->assertSame($contest->id, $user->fresh()->contest_id);
    }

    public function test_an_account_can_be_disabled_instead_of_deleted(): void
    {
        $user = $this->target(['is_enabled' => true]);

        $this->actingAs($this->createAdminUser())
            ->put(route('backend.users.update', $user->user_id), $this->payload($user, ['is_enabled' => '0']));

        $this->assertFalse((bool) $user->fresh()->is_enabled);
        $this->assertNotNull(User::find($user->user_id), 'disabling must not delete');
    }

    // --- the two ways to lock yourself out --------------------------------

    public function test_an_admin_cannot_disable_their_own_account(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->put(route('backend.users.update', $admin->user_id), $this->payload($admin, ['is_enabled' => '0']))
            ->assertSessionHasErrors('is_enabled');

        $this->assertTrue((bool) $admin->fresh()->is_enabled);
    }

    public function test_the_last_admin_cannot_be_demoted(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->put(route('backend.users.update', $admin->user_id), $this->payload($admin, ['user_type' => 'team']))
            ->assertSessionHasErrors('user_type');

        $this->assertSame('admin', $admin->fresh()->user_type);
    }

    public function test_an_admin_can_be_demoted_while_another_one_exists(): void
    {
        $admin = $this->createAdminUser();
        $other = $this->createAdminUser();

        $this->actingAs($other)
            ->put(route('backend.users.update', $admin->user_id), $this->payload($admin, ['user_type' => 'judge']))
            ->assertSessionHasNoErrors();

        $this->assertSame('judge', $admin->fresh()->user_type);
    }

    public function test_the_last_admin_cannot_be_deleted_either(): void
    {
        $admin = $this->createAdminUser();
        $other = $this->createAdminUser();

        // Deleting cascades to runs, scores, tasks and logs, so the same two
        // guards matter more here than on edit.
        $this->actingAs($other)->delete("/backend/users/{$other->user_id}")->assertSessionHasErrors('user');
        $this->assertNotNull(User::find($other->user_id));

        $this->actingAs($other)->delete("/backend/users/{$admin->user_id}")->assertRedirect();
        $this->assertNull(User::find($admin->user_id));

        $this->actingAs($other);
        $remaining = $this->createAdminUser();
        $this->actingAs($remaining)->delete("/backend/users/{$other->user_id}")->assertRedirect();
    }
}
