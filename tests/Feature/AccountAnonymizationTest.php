<?php

namespace Tests\Feature;

use App\Models\AccountActivation;
use App\Models\Answer;
use App\Models\Backup;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Leaderboard;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use Helium\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Issue #395 -- "excluir" uma conta passa a ser anonimiza-la.
 *
 * Antes, `$user->delete()` era um DELETE de verdade e as chaves estrangeiras
 * com `cascadeOnDelete` levavam `runs`, `scores` e `leaderboard` junto:
 * atender um pedido de exclusao tirava a equipe do placar de uma prova ja
 * finalizada. Ver docs/specs/395-anonimizar-em-vez-de-apagar.md.
 */
class AccountAnonymizationTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problemA;

    private Problem $problemB;

    private Answer $yes;

    private Answer $no;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->contest = Contest::factory()->finished()->create(['unfrozen_at' => now()]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problemA = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'A']);
        $this->problemB = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'B']);
        $this->yes = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);
        $this->no = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'NO', 'is_accepted' => false]);
        $this->admin = $this->createAdminUser();
    }

    private function team(string $name, array $attributes = []): User
    {
        return $this->createTestUser(array_merge([
            'fullname' => $name,
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
        ], $attributes));
    }

    private function judged(User $team, Problem $problem, int $minute, Answer $answer): Run
    {
        $path = "runs/{$this->contest->id}/{$team->user_id}/run_{$minute}_{$problem->short_name}_joao_silva.cpp";
        Storage::disk('local')->put($path, "int main() { return 0; } // {$team->fullname}");

        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'status' => 'judged',
            'answer_id' => $answer->id,
            'contest_time' => $minute * 60,
            'judged_time' => $minute * 60,
            'filename' => 'joao_silva.cpp',
            'source_file' => $path,
        ]);

        Score::updateScore($run);

        return $run;
    }

    /**
     * O que o placar mostra, sem o objeto User (que e justamente o que muda).
     *
     * @return list<array<string, mixed>>
     */
    private function standings(): array
    {
        return array_map(fn (array $row) => [
            'rank' => $row['rank'],
            'user_id' => $row['user_id'],
            'problems_solved' => $row['problems_solved'],
            'total_time' => $row['total_time'],
            'problems' => collect($row['problems'])->map(fn ($cell) => [
                $cell['short_name'], $cell['attempts'], (bool) $cell['is_solved'], $cell['solved_time'], $cell['penalty_time'],
            ])->all(),
        ], Leaderboard::getScoreboard($this->contest->id));
    }

    /** @return array{0: User, 1: User, 2: User} */
    private function finalizedContestWithThreeTeams(): array
    {
        $first = $this->team('Maria Aluna da Silva', [
            'email' => 'maria@escola.example',
            'birthdate' => '2012-03-04',
            'icpc_id' => 'ICPC-777',
            'last_ip' => '10.0.0.7',
        ]);
        $second = $this->team('Equipe Dois');
        // Nenhum envio julgado: nao tem linha em `leaderboard`, entra no
        // placar so por ser equipe habilitada da prova.
        $third = $this->team('Equipe Sem Envio');

        $this->judged($first, $this->problemA, 10, $this->no);
        $this->judged($first, $this->problemA, 20, $this->yes);
        $this->judged($first, $this->problemB, 40, $this->yes);
        $this->judged($second, $this->problemA, 50, $this->yes);

        $this->contest->update(['finalized_at' => now(), 'finalized_by' => $this->admin->user_id]);

        return [$first, $second, $third];
    }

    // -- o defeito ----------------------------------------------------------

    public function test_excluir_a_primeira_colocada_nao_muda_o_placar_de_prova_finalizada(): void
    {
        [$first, , $third] = $this->finalizedContestWithThreeTeams();
        $before = $this->standings();

        $this->assertSame($first->user_id, $before[0]['user_id'], 'pre-condicao: ela e a primeira');
        $this->assertCount(3, $before, 'pre-condicao: a equipe sem envio aparece');

        $this->actingAs($this->admin)
            ->delete(route('backend.users.destroy', $first->user_id))
            ->assertRedirect(route('backend.users'));

        $this->assertSame($before, $this->standings());

        // A que nao tinha envio tambem nao some quando e ela a anonimizada.
        $this->actingAs($this->admin)->delete(route('backend.users.destroy', $third->user_id));
        $this->assertSame($before, $this->standings());
    }

    public function test_a_linha_fica_e_os_dados_pessoais_saem(): void
    {
        [$first] = $this->finalizedContestWithThreeTeams();
        $oldPassword = $first->password;

        $this->actingAs($this->admin)->delete(route('backend.users.destroy', $first->user_id));

        $user = User::find($first->user_id);
        $this->assertNotNull($user, 'a linha de users precisa continuar existindo');
        $this->assertSame("Participante {$first->user_id}", $user->fullname);
        $this->assertSame("anonimo-{$first->user_id}", $user->username);
        $this->assertNull($user->email);
        $this->assertNull($user->birthdate);
        $this->assertNull($user->icpc_id);
        $this->assertNull($user->last_ip);
        $this->assertNull($user->remember_token);
        $this->assertNotSame($oldPassword, $user->password);
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertFalse($user->is_enabled);
        $this->assertNotNull($user->anonymized_at);
        $this->assertSame($this->admin->user_id, $user->anonymized_by);
        // O que poe a linha no placar continua.
        $this->assertSame($this->contest->id, $user->contest_id);
        $this->assertSame($this->site->id, $user->site_id);
        $this->assertSame(User::TYPE_TEAM, $user->user_type);
    }

    public function test_o_codigo_fonte_sai_do_disco_e_o_envio_fica(): void
    {
        [$first] = $this->finalizedContestWithThreeTeams();
        $runs = Run::where('user_id', $first->user_id)->get();
        $paths = $runs->pluck('source_file')->all();

        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }

        $this->actingAs($this->admin)->delete(route('backend.users.destroy', $first->user_id));

        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }

        $after = Run::where('user_id', $first->user_id)->orderBy('id')->get();
        $this->assertCount(3, $after);
        foreach ($after as $i => $run) {
            $this->assertSame('', $run->source_file);
            $this->assertSame('submission.cpp', $run->filename);
            $this->assertSame($runs[$i]->answer_id, $run->answer_id);
            $this->assertSame($runs[$i]->source_hash, $run->source_hash);
        }
    }

    public function test_credenciais_e_vinculos_somem(): void
    {
        [$first] = $this->finalizedContestWithThreeTeams();
        $first->createToken('cli');
        AccountActivation::create([
            'user_id' => $first->user_id,
            'token_hash' => hash('sha256', 'x'),
            'expires_at' => now()->addDay(),
        ]);
        $org = Organization::create(['name' => 'Escola']);
        OrganizationMembership::create(['organization_id' => $org->id, 'user_id' => $first->user_id, 'role' => 'editor']);

        $this->actingAs($this->admin)->delete(route('backend.users.destroy', $first->user_id));

        $this->assertSame(0, $first->tokens()->count());
        $this->assertSame(0, AccountActivation::where('user_id', $first->user_id)->count());
        $this->assertSame(0, OrganizationMembership::where('user_id', $first->user_id)->count());
    }

    public function test_a_anonimizacao_fica_registrada_no_log_da_prova(): void
    {
        [$first] = $this->finalizedContestWithThreeTeams();

        $this->actingAs($this->admin)->delete(route('backend.users.destroy', $first->user_id));

        $entry = ContestLog::where('contest_id', $this->contest->id)
            ->where('message', 'like', '%anonimizada%')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->admin->user_id, $entry->user_id);
        $this->assertSame($first->user_id, $entry->context['anonymized_user_id']);
        $this->assertStringNotContainsString('Maria', $entry->message);
    }

    public function test_conta_anonimizada_nao_volta_a_ser_de_alguem(): void
    {
        [$first] = $this->finalizedContestWithThreeTeams();
        $this->actingAs($this->admin)->delete(route('backend.users.destroy', $first->user_id));
        $user = User::find($first->user_id);

        $this->actingAs($this->admin)
            ->post(route('backend.users.reset-link', $user->user_id))
            ->assertSessionHasErrors('user');
        $this->assertSame(0, AccountActivation::where('user_id', $user->user_id)->count());

        $this->actingAs($this->admin)
            ->put(route('backend.users.update', $user->user_id), [
                'fullname' => 'Maria Aluna da Silva',
                'username' => 'maria',
                'email' => 'maria@escola.example',
                'user_type' => 'team',
                'is_enabled' => '1',
            ])
            ->assertSessionHasErrors('user');
        $this->assertSame("Participante {$user->user_id}", $user->fresh()->fullname);

        // Anonimizar de novo nao quebra nem muda nada.
        $this->actingAs($this->admin)
            ->delete(route('backend.users.destroy', $user->user_id))
            ->assertRedirect(route('backend.users'));
        $this->assertSame("Participante {$user->user_id}", $user->fresh()->fullname);
    }

    public function test_administrador_anonimizado_nao_conta_como_administrador(): void
    {
        $other = $this->createAdminUser();

        $this->actingAs($this->admin)->delete(route('backend.users.destroy', $other->user_id))->assertRedirect();

        // So resta $this->admin de verdade: rebaixar-se trancaria a instalacao.
        $this->actingAs($this->admin)
            ->put(route('backend.users.update', $this->admin->user_id), [
                'fullname' => $this->admin->fullname,
                'username' => $this->admin->username,
                'email' => $this->admin->email,
                'user_type' => 'team',
                'is_enabled' => '1',
            ])
            ->assertSessionHasErrors('user_type');
    }

    public function test_as_copias_de_seguranca_de_um_administrador_anonimizado_ficam(): void
    {
        $other = $this->createAdminUser();
        $backup = Backup::create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $other->user_id,
            'backup_number' => 1,
            'filename' => 'backup_1.zip',
            'file_path' => 'backups/backup_1.zip',
            'file_size' => 10,
        ]);

        $this->actingAs($this->admin)->delete(route('backend.users.destroy', $other->user_id));

        $this->assertNotNull(Backup::find($backup->id));
    }
}
