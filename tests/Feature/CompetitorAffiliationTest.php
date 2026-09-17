<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\Clics\TeamAffiliation;
use App\Services\CompetitorAffiliationBackfill;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Issue #270 -- afiliacao propria, e o relatorio do que ficou sem.
 *
 * A Contest API inferia a instituicao de uma equipe lendo
 * `organization_memberships`, a tabela que o #46 criou para responder
 * "quem pode editar as etiquetas e o dono dos problemas desta organizacao".
 * Permissao sobre acervo, lida como afiliacao de competidor.
 *
 * Os testes da traducao em si estao em tests/Feature/Clics/ContestApiTest.php.
 * Este arquivo cobre a MIGRACAO e o relatorio, que sao a metade que uma
 * instalacao existente precisa.
 */
class CompetitorAffiliationTest extends TestCase
{
    use RefreshDatabase;

    private Contest $contest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create(['is_active' => true]);
    }

    private function equipe(string $email, ?int $organizationId = null): User
    {
        return $this->createTestUser([
            'email' => $email,
            'username' => str_replace(['@', '.'], '-', $email),
            'user_type' => User::TYPE_TEAM,
            'contest_id' => $this->contest->id,
            'organization_id' => $organizationId,
        ]);
    }

    // ---------------------------------------------------------------
    // A coluna, e o que ela não é.
    // ---------------------------------------------------------------

    /**
     * A afiliacao nao e a governanca, e as duas coexistem sem se falar.
     */
    public function test_affiliation_and_bank_governance_are_independent(): void
    {
        $acervo = Organization::create(['name' => 'Onde Edita']);
        $compete = Organization::create(['name' => 'Onde Compete']);

        $equipe = $this->equipe('dupla@example.com', $compete->id);

        OrganizationMembership::create([
            'organization_id' => $acervo->id,
            'user_id' => $equipe->user_id,
            'role' => OrganizationMembership::ROLE_EDITOR,
        ]);

        $this->assertSame($compete->id, (int) $equipe->fresh()->organization_id);
        $this->assertSame($acervo->id, (int) OrganizationMembership::where('user_id', $equipe->user_id)->value('organization_id'));
    }

    public function test_the_relation_resolves_the_organization(): void
    {
        $org = Organization::create(['name' => 'Instituto Exemplo']);
        $equipe = $this->equipe('rel@example.com', $org->id);

        $this->assertSame('Instituto Exemplo', $equipe->fresh()->organization?->name);
    }

    /**
     * Apagar a organizacao nao apaga a equipe.
     */
    public function test_deleting_the_organization_leaves_the_team_alone(): void
    {
        $org = Organization::create(['name' => 'Some']);
        $equipe = $this->equipe('orfa@example.com', $org->id);

        $org->delete();

        $this->assertNotNull($equipe->fresh(), 'a equipe foi apagada com a instituicao');
    }

    /**
     * Uma instituicao apagada NAO pode sair na Contest API.
     *
     * A coluna tem `nullOnDelete()`, e isso deveria bastar. Nao basta:
     * medido nesta suite, `PRAGMA foreign_keys` vem **0** na conexao SQLite,
     * ou seja as chaves estrangeiras estao declaradas e nao sao aplicadas.
     * Numa instalacao SQLite, `users.organization_id` fica apontando para
     * linha que nao existe.
     *
     * E o efeito nao seria um campo estranho: `teams` citaria uma
     * `organization_id` que `/organizations` nao lista, quebrando a
     * integridade referencial que o #195 estabeleceu como o UNICO requisito
     * duro da fase 1 -- e quebrando em silencio, como uma linha faltando num
     * painel.
     *
     * Por isso a resolucao confere a existencia em vez de confiar no banco.
     */
    public function test_a_dangling_affiliation_never_reaches_the_contest_api(): void
    {
        $org = Organization::create(['name' => 'Vai Sumir']);
        $equipe = $this->equipe('pendente@example.com', $org->id);

        $org->delete();
        TeamAffiliation::flush();

        $this->assertNull(
            TeamAffiliation::for($equipe->fresh()),
            'uma instituicao inexistente saiu como afiliacao'
        );
    }

    /**
     * O controle positivo do mesmo mecanismo: uma instituicao que EXISTE sai
     * normalmente. Sem ele, o teste acima passaria igual se a resolucao
     * passasse a devolver null sempre.
     */
    public function test_an_existing_affiliation_does_reach_the_contest_api(): void
    {
        $org = Organization::create(['name' => 'Continua Existindo']);
        $equipe = $this->equipe('presente@example.com', $org->id);

        TeamAffiliation::flush();

        $this->assertSame($org->id, TeamAffiliation::for($equipe->fresh()));
    }

    // ---------------------------------------------------------------
    // O relatório, que aponta em vez de inventar.
    // ---------------------------------------------------------------

    public function test_the_report_lists_a_team_with_no_affiliation_at_all(): void
    {
        $this->equipe('sem@example.com');

        $codigo = Artisan::call('affiliations:report', ['--json' => true]);
        $documento = json_decode(Artisan::output(), true);

        $this->assertSame(1, $codigo, 'o relatorio passou com afiliacao faltando');
        $this->assertSame(1, $documento['without_affiliation']);
        $this->assertSame([], $documento['ambiguous'], 'quem nunca tocou o banco apareceu como ambigua');
        $this->assertCount(1, $documento['no_clue']);
    }

    /**
     * O caso que a migracao nao pode decidir: duas organizacoes na
     * governanca, e escolher por ordem de id e exatamente o defeito.
     */
    public function test_the_report_separates_the_cases_it_cannot_decide(): void
    {
        $equipe = $this->equipe('ambigua@example.com');

        foreach (['Alfa', 'Beta'] as $nome) {
            OrganizationMembership::create([
                'organization_id' => Organization::create(['name' => $nome])->id,
                'user_id' => $equipe->user_id,
                'role' => OrganizationMembership::ROLE_EDITOR,
            ]);
        }

        Artisan::call('affiliations:report', ['--json' => true]);
        $documento = json_decode(Artisan::output(), true);

        $this->assertCount(1, $documento['ambiguous']);
        $this->assertSame(
            ['Alfa', 'Beta'],
            $documento['ambiguous'][0]['candidates'],
            'as candidatas nao saem ordenadas e completas'
        );
        $this->assertSame([], $documento['no_clue'], 'uma equipe com candidatas entrou em "sem pista"');
    }

    /**
     * O controle positivo: sem ele, tudo acima passaria igual se o relatorio
     * reportasse todo mundo como faltando.
     */
    public function test_the_report_is_quiet_when_every_team_has_an_affiliation(): void
    {
        $org = Organization::create(['name' => 'Todos Aqui']);
        $this->equipe('a@example.com', $org->id);
        $this->equipe('b@example.com', $org->id);

        $codigo = Artisan::call('affiliations:report', ['--json' => true]);
        $documento = json_decode(Artisan::output(), true);

        $this->assertSame(0, $codigo, 'o relatorio falhou com tudo em ordem');
        $this->assertSame(2, $documento['with_affiliation']);
        $this->assertSame(0, $documento['without_affiliation']);
    }

    /**
     * Uma equipe de OUTRA prova nao entra na conta: `users.contest_id` e o
     * escopo, e o relatorio serve a um evento.
     */
    public function test_the_report_only_looks_at_this_contest(): void
    {
        $outra = Contest::factory()->create();

        $this->createTestUser([
            'email' => 'outra@example.com',
            'user_type' => User::TYPE_TEAM,
            'contest_id' => $outra->id,
            'organization_id' => null,
        ]);

        $org = Organization::create(['name' => 'Desta Prova']);
        $this->equipe('desta@example.com', $org->id);

        $codigo = Artisan::call('affiliations:report', ['--json' => true]);
        $documento = json_decode(Artisan::output(), true);

        $this->assertSame(0, $codigo, 'a equipe de outra prova entrou na conta');
        $this->assertSame(1, $documento['teams']);
    }

    // ---------------------------------------------------------------
    // O escritor -- sem ele a coluna nasce morta, como a #276.
    // ---------------------------------------------------------------

    public function test_an_admin_can_set_a_team_affiliation(): void
    {
        $org = Organization::create(['name' => 'Universidade Escolhida']);
        $equipe = $this->equipe('escolher@example.com');

        $this->actingAs($this->admin())->put("/backend/users/{$equipe->user_id}", [
            'fullname' => $equipe->fullname,
            'username' => $equipe->username,
            'email' => $equipe->email,
            'user_type' => User::TYPE_TEAM,
            'is_enabled' => 1,
            'organization_id' => $org->id,
        ])->assertRedirect();

        $this->assertSame($org->id, (int) $equipe->fresh()->organization_id, 'a instituicao nao foi gravada');
    }

    /**
     * Select vazio quer dizer "sem instituicao", e nao zero.
     *
     * Um `(int)` cru na string vazia daria ZERO, que e id de instituicao que
     * nao existe -- e a Contest API passaria a citar em `teams` uma
     * `organization_id` que `/organizations` nao lista.
     */
    public function test_an_empty_choice_clears_the_affiliation_instead_of_zeroing_it(): void
    {
        $org = Organization::create(['name' => 'Vai Sair']);
        $equipe = $this->equipe('limpar@example.com', $org->id);

        $this->actingAs($this->admin())->put("/backend/users/{$equipe->user_id}", [
            'fullname' => $equipe->fullname,
            'username' => $equipe->username,
            'email' => $equipe->email,
            'user_type' => User::TYPE_TEAM,
            'is_enabled' => 1,
            'organization_id' => '',
        ])->assertRedirect();

        $this->assertNull($equipe->fresh()->organization_id, 'o campo vazio virou zero em vez de nulo');
    }

    /**
     * Instituicao arquivada nao pode ser escolhida como afiliacao nova: o
     * #46 criou `archived_at` para preservar historico de acervo.
     *
     * `Rule::exists` consulta a tabela crua, entao sem o `whereNull`
     * explicito uma arquivada passaria.
     */
    public function test_an_archived_organization_cannot_be_chosen(): void
    {
        $org = Organization::create(['name' => 'Arquivada', 'archived_at' => now()]);
        $equipe = $this->equipe('arquivada@example.com');

        $this->actingAs($this->admin())
            ->from("/backend/users/{$equipe->user_id}/edit")
            ->put("/backend/users/{$equipe->user_id}", [
                'fullname' => $equipe->fullname,
                'username' => $equipe->username,
                'email' => $equipe->email,
                'user_type' => User::TYPE_TEAM,
                'is_enabled' => 1,
                'organization_id' => $org->id,
            ])->assertSessionHasErrors('organization_id');

        $this->assertNull($equipe->fresh()->organization_id);
    }

    // ---------------------------------------------------------------
    // A derivação, que é o caminho de quem já rodava com a inferência.
    // ---------------------------------------------------------------

    /**
     * Uma organizacao so: a inferencia antiga e a afiliacao coincidem, e nao
     * ha o que escolher.
     */
    public function test_the_backfill_derives_an_unambiguous_affiliation(): void
    {
        $org = Organization::create(['name' => 'Unica']);
        $equipe = $this->equipe('unica@example.com');

        OrganizationMembership::create([
            'organization_id' => $org->id,
            'user_id' => $equipe->user_id,
            'role' => OrganizationMembership::ROLE_EDITOR,
        ]);

        $resultado = app(CompetitorAffiliationBackfill::class)->run();

        $this->assertSame(1, $resultado['derived']);
        $this->assertSame($org->id, (int) $equipe->fresh()->organization_id);
    }

    /**
     * Duas organizacoes: fica NULO de proposito. Escolher pelo organizador e
     * o defeito que a issue conserta.
     */
    public function test_the_backfill_refuses_to_decide_an_ambiguous_case(): void
    {
        $equipe = $this->equipe('duas@example.com');

        foreach (['Alfa', 'Beta'] as $nome) {
            OrganizationMembership::create([
                'organization_id' => Organization::create(['name' => $nome])->id,
                'user_id' => $equipe->user_id,
                'role' => OrganizationMembership::ROLE_EDITOR,
            ]);
        }

        $resultado = app(CompetitorAffiliationBackfill::class)->run();

        $this->assertSame(0, $resultado['derived']);
        $this->assertSame(1, $resultado['ambiguous']);
        $this->assertNull(
            $equipe->fresh()->organization_id,
            'a derivacao escolheu uma das duas, que e exatamente o defeito do #270'
        );
    }

    /**
     * Rodar de novo nao desfaz o trabalho de quem resolveu a mao.
     *
     * Sem o `whereNull`, uma segunda execucao sobrescreveria a escolha do
     * organizador pela organizacao de menor id -- o defeito voltando pela
     * porta da manutencao.
     */
    public function test_the_backfill_does_not_overwrite_a_decision_already_made(): void
    {
        $governanca = Organization::create(['name' => 'Onde Edita']);
        $escolhida = Organization::create(['name' => 'Escolhida a Mao']);

        $equipe = $this->equipe('resolvida@example.com', $escolhida->id);

        OrganizationMembership::create([
            'organization_id' => $governanca->id,
            'user_id' => $equipe->user_id,
            'role' => OrganizationMembership::ROLE_EDITOR,
        ]);

        $resultado = app(CompetitorAffiliationBackfill::class)->run();

        $this->assertSame(0, $resultado['derived']);
        $this->assertSame(
            $escolhida->id,
            (int) $equipe->fresh()->organization_id,
            'a reexecucao sobrescreveu a escolha do organizador'
        );
    }

    /**
     * Linha de governanca apontando para organizacao que nao existe mais nao
     * vira afiliacao pendurada.
     */
    public function test_the_backfill_skips_a_membership_whose_organization_is_gone(): void
    {
        $org = Organization::create(['name' => 'Sumiu']);
        $equipe = $this->equipe('pendurada@example.com');

        OrganizationMembership::create([
            'organization_id' => $org->id,
            'user_id' => $equipe->user_id,
            'role' => OrganizationMembership::ROLE_EDITOR,
        ]);

        // As FKs nao sao aplicadas nesta conexao (PRAGMA foreign_keys = 0),
        // entao a linha de governanca sobrevive a organizacao -- que e
        // justamente o caso que a derivacao tem que ignorar.
        $org->delete();

        $resultado = app(CompetitorAffiliationBackfill::class)->run();

        $this->assertSame(0, $resultado['derived']);
        $this->assertSame(1, $resultado['skipped_missing_organization']);
        $this->assertNull($equipe->fresh()->organization_id);
    }

    private function admin(): User
    {
        return $this->createTestUser([
            'email' => 'admin-afiliacao@example.com',
            'username' => 'admin-afiliacao',
            'user_type' => User::TYPE_ADMIN,
        ]);
    }

    public function test_the_report_refuses_without_a_contest(): void
    {
        $this->contest->update(['is_active' => false]);

        $this->artisan('affiliations:report')
            ->expectsOutputToContain('Nenhum contest ativo')
            ->assertFailed();
    }
}
