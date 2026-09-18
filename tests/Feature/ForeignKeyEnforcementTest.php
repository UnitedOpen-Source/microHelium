<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Organization;
use App\Models\Site;
use Helium\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #293 -- as chaves estrangeiras passam a ser APLICADAS.
 *
 * A conexão SQLite não trazia `foreign_key_constraints`, e o SQLite desliga
 * as chaves por padrão. Medido dentro desta suíte, `PRAGMA foreign_keys`
 * vinha **0**: o esquema inteiro declarava `cascadeOnDelete()` e
 * `nullOnDelete()` que **não aconteciam** -- nem nos testes, nem numa
 * instalação SQLite de produção.
 *
 * O repositório já sabia que referência pendurada derruba tela. O
 * `Backend\SiteController::destroy()` limpa à mão e diz por quê:
 *
 *   "Site uses SoftDeletes, so the users.site_id / site_judging_routes
 *    foreign keys' nullOnDelete()/cascadeOnDelete() never fire (...) without
 *    this, users and judging routes are left pointing at a now-trashed site,
 *    which crashes JudgeController the next time that judge loads
 *    /judge/runs."
 *
 * O que ninguém sabia é que, no SQLite, a chave também não disparava no
 * delete **real**.
 *
 * Este arquivo é o controle positivo do conserto: sem ele, "a suíte passa"
 * significaria "as chaves continuam desligadas", que é exatamente o modo de
 * falha que este repositório catalogou.
 */
class ForeignKeyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O controle positivo de tudo o mais neste arquivo.
     */
    public function test_the_connection_enforces_foreign_keys(): void
    {
        $pragma = DB::select('PRAGMA foreign_keys');

        $this->assertSame(
            1,
            (int) ($pragma[0]->foreign_keys ?? 0),
            'as chaves estrangeiras estao desligadas: nada abaixo prova coisa nenhuma'
        );
    }

    /**
     * Uma referência a linha que não existe é RECUSADA.
     */
    public function test_a_reference_to_a_row_that_does_not_exist_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/FOREIGN KEY constraint failed/');

        DB::table('sites')->insert([
            'contest_id' => 999999,
            'name' => 'Sede de prova inexistente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * `nullOnDelete()` dispara de verdade.
     *
     * Era a garantia que o #270 não pôde verificar aqui, e por isso
     * `TeamAffiliation` confere a existência em código. A conferência fica
     * -- uma instalação que rodou sem as chaves pode ter a referência
     * pendurada gravada --, mas agora o banco também segura.
     */
    public function test_deleting_an_organization_nulls_the_affiliation(): void
    {
        $org = Organization::create(['name' => 'Vai Sumir']);

        $team = $this->createTestUser([
            'email' => 'afiliada@example.com',
            'username' => 'afiliada',
            'organization_id' => $org->id,
        ]);

        $org->delete();

        $this->assertNotNull($team->fresh(), 'a equipe foi apagada com a instituicao');
        $this->assertNull(
            $team->fresh()->organization_id,
            'nullOnDelete nao disparou: a equipe ficou apontando para instituicao inexistente'
        );
    }

    /**
     * `cascadeOnDelete()` também.
     *
     * Uma sede orfã de prova é o caso que o `SiteController` descreve como
     * derrubando a tela do juiz.
     */
    public function test_deleting_a_contest_takes_its_sites_with_it(): void
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);

        $contest->forceDelete();

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }

    /**
     * E a conta de quem estava naquela sede sobrevive, sem apontar para ela.
     *
     * Sem este caso, o teste acima passaria igual se o cascade levasse
     * usuário junto -- e perder conta de equipe porque alguém apagou a prova
     * do ano passado seria pior que a referência pendurada.
     */
    public function test_deleting_a_contest_does_not_delete_the_people_in_it(): void
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);

        $team = $this->createTestUser([
            'email' => 'sobrevivente@example.com',
            'username' => 'sobrevivente',
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ]);

        $contest->forceDelete();

        $viva = User::where('email', 'sobrevivente@example.com')->first();

        $this->assertNotNull($viva, 'a conta foi apagada junto com a prova');
        $this->assertNull($viva->contest_id);
        $this->assertNull($viva->site_id);
    }

    /**
     * A porta de fuga existe e está documentada.
     *
     * `DB_FOREIGN_KEYS=false` devolve o comportamento antigo, para quem
     * tenha um banco com referência pendurada gravada e precise subir a
     * aplicação antes de limpar. Sem isso, atualizar quebraria a instalação
     * em vez de avisá-la.
     */
    public function test_the_escape_hatch_is_a_real_configuration_key(): void
    {
        $this->assertTrue(
            config('database.connections.sqlite.foreign_key_constraints'),
            'o padrao precisa ser LIGADO: uma garantia opcional por omissao nao e garantia'
        );
    }
}
