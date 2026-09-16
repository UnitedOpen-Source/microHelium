<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProblemBank;
use Helium\User;
use Tests\TestCase;

/**
 * Issue #188 -- a fase que o #46 deixou explicitamente para depois.
 *
 * O #46 entregou "um problema pertence a uma organizacao, e quem e editor
 * dela pode edita-lo", e nao entregou nenhum jeito de criar uma
 * organizacao: `Organization::create()` nao era chamado em app/ nem em
 * routes/. A tela /backend/bank-governance abria com o seletor de
 * proprietario VAZIO em qualquer instalacao real, e metade do #46 so era
 * alcancavel por `php artisan tinker`.
 *
 * O que estes testes fixam, alem do CRUD: as regras de arquivamento, que a
 * issue pede que estejam escritas e nao inferidas.
 */
class OrganizationManagementTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createTestUser(['user_type' => 'admin']);
    }

    private function asAdmin(): self
    {
        $this->actingAs($this->admin);

        return $this;
    }

    /**
     * Uma Idempotency-Key NOVA a cada chamada.
     *
     * O cabecalho e obrigatorio nesta superficie (docs/specs/README.md), e a
     * chave precisa ser diferente entre chamadas que sao acoes diferentes:
     * repetir a MESMA chave devolve a resposta guardada sem rodar nada, que
     * e o ponto do mecanismo -- e faria um teste de "clicou duas vezes"
     * medir o cache em vez do comportamento.
     *
     * @return array<string, string>
     */
    private function key(): array
    {
        static $n = 0;

        return ['Idempotency-Key' => 'teste-'.(++$n)];
    }

    // -- o buraco que a issue descreve --------------------------------------

    public function test_an_organization_can_finally_be_created_through_the_api(): void
    {
        $this->asAdmin()
            ->postJson('/api/frontend/organizations', ['name' => 'Universidade Exemplo'], $this->key())
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Universidade Exemplo');

        $this->assertSame(1, Organization::count());
    }

    public function test_two_organizations_cannot_share_a_name(): void
    {
        Organization::create(['name' => 'Universidade Exemplo']);

        $this->asAdmin()
            ->postJson('/api/frontend/organizations', ['name' => 'Universidade Exemplo'], $this->key())
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_an_organization_can_be_renamed(): void
    {
        $org = Organization::create(['name' => 'Nome Antigo']);

        $this->asAdmin()
            ->patchJson("/api/frontend/organizations/{$org->id}", ['name' => 'Nome Novo'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Nome Novo');
    }

    public function test_members_can_be_added_and_removed(): void
    {
        $org = Organization::create(['name' => 'Universidade Exemplo']);
        $editor = $this->createTestUser(['fullname' => 'Professora']);

        $this->asAdmin()
            ->postJson("/api/frontend/organizations/{$org->id}/members", ['user_id' => $editor->user_id], $this->key())
            ->assertStatus(201)
            ->assertJsonPath('data.members.0.role', OrganizationMembership::ROLE_EDITOR);

        $this->asAdmin()
            ->deleteJson("/api/frontend/organizations/{$org->id}/members/{$editor->user_id}")
            ->assertStatus(200)
            ->assertJsonPath('data.members', []);
    }

    /**
     * Adicionar duas vezes e um clique repetido, nao um erro -- e uma
     * segunda linha daria ao membro dois vinculos que a remocao teria que
     * apagar em duplicata.
     */
    public function test_adding_the_same_member_twice_does_not_duplicate_the_membership(): void
    {
        $org = Organization::create(['name' => 'Universidade Exemplo']);
        $editor = $this->createTestUser();

        // Duas chaves DIFERENTES: e uma pessoa clicando duas vezes, em duas
        // requisicoes. Com a mesma chave o teste mediria o cache de
        // idempotencia e nao o firstOrCreate.
        foreach ([1, 2] as $ignored) {
            $this->asAdmin()->postJson(
                "/api/frontend/organizations/{$org->id}/members",
                ['user_id' => $editor->user_id],
                $this->key()
            )->assertStatus(201);
        }

        $this->assertSame(1, OrganizationMembership::where('organization_id', $org->id)->count());
    }

    // -- arquivar, e o que ele NAO faz --------------------------------------

    /**
     * Arquivar NAO orfana nada: os problemas continuam apontando para a
     * organizacao. Uma limpeza que zerasse owning_org_id transformaria "esta
     * instituicao saiu" em "estes 200 problemas nao sao de ninguem", e o #46
     * trata problema sem dono como caso legado que so admin ve.
     */
    public function test_archiving_does_not_orphan_the_problems(): void
    {
        $org = Organization::create(['name' => 'Universidade Exemplo']);
        $bank = ProblemBank::factory()->create(['owning_org_id' => $org->id]);

        $this->asAdmin()
            ->patchJson("/api/frontend/organizations/{$org->id}", ['archived' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.archived', true);

        $this->assertSame($org->id, $bank->fresh()->owning_org_id);
    }

    /**
     * O que arquivar significa: some do seletor de proprietario.
     */
    public function test_an_archived_organization_leaves_the_owner_selector(): void
    {
        $ativa = Organization::create(['name' => 'Ativa']);
        $arquivada = Organization::create(['name' => 'Arquivada', 'archived_at' => now()]);

        $nomes = collect(
            $this->asAdmin()->getJson('/api/frontend/bank-governance')->assertStatus(200)->json('data.organizations')
        )->pluck('name');

        $this->assertContains($ativa->name, $nomes);
        $this->assertNotContains($arquivada->name, $nomes);
    }

    /**
     * Sumir do seletor nao basta: o seletor e uma dica de apresentacao, e o
     * endpoint aceita o id que o cliente mandar. Sem a recusa na escrita,
     * "arquivada" seria uma sugestao.
     */
    public function test_an_archived_organization_cannot_receive_new_problems(): void
    {
        $arquivada = Organization::create(['name' => 'Arquivada', 'archived_at' => now()]);
        $bank = ProblemBank::factory()->create(['owning_org_id' => null]);

        $this->asAdmin()->patchJson("/api/frontend/bank-governance/{$bank->id}", [
            'version' => (string) $bank->version,
            'owning_org_id' => $arquivada->id,
            'tags' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('owning_org_id');

        $this->assertNull($bank->fresh()->owning_org_id);
    }

    /**
     * Mas um problema que JA pertence a uma organizacao arquivada continua
     * salvavel: editar as etiquetas dele nao pode exigir transferi-lo
     * primeiro, ou arquivar congelaria o acervo inteiro daquela
     * instituicao.
     */
    public function test_a_problem_already_owned_by_an_archived_organization_stays_editable(): void
    {
        $arquivada = Organization::create(['name' => 'Arquivada', 'archived_at' => now()]);
        $bank = ProblemBank::factory()->create(['owning_org_id' => $arquivada->id]);

        $this->asAdmin()->patchJson("/api/frontend/bank-governance/{$bank->id}", [
            'version' => (string) $bank->version,
            'owning_org_id' => $arquivada->id,
            'tags' => ['dp'],
        ])->assertStatus(200);

        $this->assertSame(['dp'], $bank->fresh()->tags);
    }

    /**
     * E os membros dela continuam podendo editar. Tirar isso deixaria
     * problemas que ninguem alcanca -- o mesmo orfanato, por outro caminho.
     */
    public function test_an_editor_of_an_archived_organization_can_still_edit_its_problems(): void
    {
        $arquivada = Organization::create(['name' => 'Arquivada', 'archived_at' => now()]);
        $editor = $this->createTestUser();
        OrganizationMembership::create([
            'organization_id' => $arquivada->id,
            'user_id' => $editor->user_id,
            'role' => OrganizationMembership::ROLE_EDITOR,
        ]);
        $bank = ProblemBank::factory()->create(['owning_org_id' => $arquivada->id]);

        $this->actingAs($editor)->patchJson("/api/frontend/bank-governance/{$bank->id}", [
            'version' => (string) $bank->version,
            'owning_org_id' => $arquivada->id,
            'tags' => ['grafos'],
        ])->assertStatus(200);

        $this->assertSame(['grafos'], $bank->fresh()->tags);
    }

    /**
     * Arquivar e um estado e nao uma exclusao: a coluna e um timestamp
     * anulavel, e uma instituicao que volta no ano seguinte nao deveria
     * precisar de uma organizacao nova com o mesmo nome.
     */
    public function test_archiving_can_be_undone(): void
    {
        $org = Organization::create(['name' => 'Universidade Exemplo', 'archived_at' => now()]);

        $this->asAdmin()
            ->patchJson("/api/frontend/organizations/{$org->id}", ['archived' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.archived', false);

        $nomes = collect(
            $this->asAdmin()->getJson('/api/frontend/bank-governance')->json('data.organizations')
        )->pluck('name');

        $this->assertContains('Universidade Exemplo', $nomes);
    }

    // -- quem pode ----------------------------------------------------------

    /**
     * Admin e nao so `auth`, ao contrario do vizinho bank-governance. La a
     * autorizacao e por politica porque um editor mexe nos problemas DA SUA
     * organizacao; aqui o assunto e QUEM E EDITOR, e deixar um editor
     * gerenciar a propria membership seria deixar que ele se promova.
     */
    public function test_a_mere_editor_cannot_manage_memberships(): void
    {
        $org = Organization::create(['name' => 'Universidade Exemplo']);
        $editor = $this->createTestUser();
        OrganizationMembership::create([
            'organization_id' => $org->id,
            'user_id' => $editor->user_id,
            'role' => OrganizationMembership::ROLE_EDITOR,
        ]);

        $this->actingAs($editor)
            ->postJson("/api/frontend/organizations/{$org->id}/members", ['user_id' => $this->admin->user_id], $this->key())
            ->assertStatus(403);

        $this->actingAs($editor)
            ->postJson('/api/frontend/organizations', ['name' => 'Minha Propria'], $this->key())
            ->assertStatus(403);
    }

    public function test_a_guest_reaches_nothing(): void
    {
        $this->getJson('/api/frontend/organizations')->assertStatus(401);
    }

    // -- a listagem ---------------------------------------------------------

    /**
     * O numero de problemas aparece porque e o que decide se arquivar e uma
     * decisao inocente ou nao.
     */
    public function test_the_listing_says_how_many_problems_each_organization_holds(): void
    {
        $org = Organization::create(['name' => 'Universidade Exemplo']);
        ProblemBank::factory()->count(3)->create(['owning_org_id' => $org->id]);
        ProblemBank::factory()->create(['owning_org_id' => null]);

        $linha = collect($this->asAdmin()->getJson('/api/frontend/organizations')->assertStatus(200)->json('data.organizations'))
            ->firstWhere('id', $org->id);

        $this->assertSame(3, $linha['problem_count']);
    }

    public function test_the_listing_includes_archived_organizations(): void
    {
        Organization::create(['name' => 'Arquivada', 'archived_at' => now()]);

        $linhas = collect($this->asAdmin()->getJson('/api/frontend/organizations')->json('data.organizations'));

        // Ao contrario do seletor: esta tela e onde se DESARQUIVA, entao
        // esconder as arquivadas aqui tornaria a acao inalcancavel.
        $this->assertSame('Arquivada', $linhas->firstWhere('name', 'Arquivada')['name']);
        $this->assertTrue($linhas->firstWhere('name', 'Arquivada')['archived']);
    }
}
