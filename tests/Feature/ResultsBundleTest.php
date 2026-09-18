<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Organization;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Services\ContestScoreRecomputer;
use App\Services\Results\ResultsBundleBuilder;
use App\Services\Results\ResultsBundleNotReadyException;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Issue #271 -- o pacote de resultados, para alimentar um ranking nacional.
 *
 * O que sustenta a confiança não é o arquivo ser bonito: é **exportar duas
 * vezes a mesma prova finalizada produzir os mesmos bytes**. Com isso o site
 * nacional não precisa confiar no que recebeu -- ele pede a reexportação e
 * compara, e adulteração aparece como divergência, não como suspeita.
 */
class ResultsBundleTest extends TestCase
{
    use RefreshDatabase;

    private Contest $contest;

    private Problem $problemA;

    private Problem $problemB;

    private User $alfa;

    private User $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create([
            'name' => 'Regional Sudeste',
            'edition' => '2026',
            'phase' => 'regional',
            'start_time' => now()->subHours(6),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'is_active' => true,
        ]);

        $site = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'Sede Central']);
        $org = Organization::create([
            'name' => 'UFMG',
            'icpc_id' => 'ufmg',
            'formal_name' => 'Universidade Federal de Minas Gerais',
            'country' => 'BRA',
        ]);

        $this->problemA = Problem::factory()->create([
            'contest_id' => $this->contest->id,
            'short_name' => 'A',
            'sort_order' => 1,
        ]);
        $this->problemB = Problem::factory()->create([
            'contest_id' => $this->contest->id,
            'short_name' => 'B',
            'sort_order' => 2,
        ]);

        $language = Language::factory()->create(['contest_id' => $this->contest->id]);
        $sim = Answer::factory()->create([
            'contest_id' => $this->contest->id,
            'short_name' => 'YES',
            'name' => 'Accepted',
            'is_accepted' => true,
        ]);

        $this->alfa = $this->createTestUser([
            'email' => 'alfa@example.com',
            'username' => 'alfa',
            'fullname' => 'Equipe Alfa',
            'user_type' => User::TYPE_TEAM,
            'contest_id' => $this->contest->id,
            'site_id' => $site->id,
            'organization_id' => $org->id,
            'icpc_id' => 'BR-ALFA',
            // Dado privado, que NAO pode aparecer no pacote (#47).
            'birthdate' => '2010-05-04',
        ]);

        $this->beta = $this->createTestUser([
            'email' => 'beta@example.com',
            'username' => 'beta',
            'fullname' => 'Equipe Beta',
            'user_type' => User::TYPE_TEAM,
            'contest_id' => $this->contest->id,
            'site_id' => $site->id,
            'organization_id' => $org->id,
            'icpc_id' => 'BR-BETA',
        ]);

        // Alfa resolve A aos 30 minutos; Beta resolve A e B.
        foreach ([[$this->alfa, $this->problemA, 30], [$this->beta, $this->problemA, 20], [$this->beta, $this->problemB, 90]] as [$team, $problem, $minuto]) {
            Run::factory()->create([
                'contest_id' => $this->contest->id,
                'problem_id' => $problem->id,
                'user_id' => $team->user_id,
                'language_id' => $language->id,
                'answer_id' => $sim->id,
                'status' => 'judged',
                'contest_time' => $minuto * 60,
            ]);
        }

        // As runs sozinhas nao mexem em `scores`: o placar sai de la, e
        // quem o preenche e o recomputador. Sem isto o pacote sairia com
        // todo mundo em zero, e os testes de fidelidade passariam por
        // comparar zero com zero.
        app(ContestScoreRecomputer::class)->recompute($this->contest);

        $this->contest->update(['finalized_at' => now()]);
        $this->contest->refresh();
    }

    private function builder(): ResultsBundleBuilder
    {
        return app(ResultsBundleBuilder::class);
    }

    /**
     * @return array<string, string>
     */
    private function unzip(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, "nao abriu o pacote em {$path}");

        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $out[$name] = $zip->getFromIndex($i);
        }
        $zip->close();

        return $out;
    }

    private function exportTo(string $suffix, ?string $generatedAt = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mh-test-'.$suffix.'-');
        $this->builder()->zip($this->contest, $path, $generatedAt);

        return $path;
    }

    // ---------------------------------------------------------------
    // 1. O portão de finalização.
    // ---------------------------------------------------------------

    public function test_an_unfinalized_contest_cannot_be_exported(): void
    {
        $this->contest->update(['finalized_at' => null]);

        $this->expectException(ResultsBundleNotReadyException::class);
        $this->expectExceptionMessageMatches('/nao foi finalizada/');

        $this->builder()->files($this->contest->fresh());
    }

    /**
     * O Treino Livre nao e um evento (#43) e nao pode ser alcancado por uma
     * exportacao com forma de evento -- nem mesmo se alguem o finalizasse.
     */
    public function test_the_practice_contest_is_not_an_event(): void
    {
        $this->contest->update(['is_practice' => true]);

        $this->expectException(ResultsBundleNotReadyException::class);
        $this->expectExceptionMessageMatches('/Treino Livre/');

        $this->builder()->files($this->contest->fresh());
    }

    /**
     * O controle positivo do portao: finalizada, exporta. Sem ele, os dois
     * acima passariam igual se o construtor recusasse tudo.
     */
    public function test_a_finalized_contest_produces_the_four_files(): void
    {
        $path = $this->exportTo('ok');
        $entradas = $this->unzip($path);
        @unlink($path);

        $this->assertSame(
            ['manifest.json', 'organizations.json', 'standings.csv', 'standings.json'],
            array_keys($entradas),
            'o pacote nao trouxe exatamente os quatro arquivos, em ordem'
        );
    }

    // ---------------------------------------------------------------
    // 2. Reprodutibilidade.
    // ---------------------------------------------------------------

    /**
     * A propriedade central: duas exportacoes da mesma prova finalizada,
     * identicas byte a byte, exceto `generated_at`.
     */
    public function test_two_exports_are_byte_identical_apart_from_the_timestamp(): void
    {
        $primeiro = $this->unzip($p1 = $this->exportTo('um', '2026-01-01T00:00:00+00:00'));
        // Relogio adiantado entre as duas: se alguma coisa alem do campo
        // isolado lesse now(), apareceria aqui.
        $this->travel(3)->hours();
        $segundo = $this->unzip($p2 = $this->exportTo('dois', '2026-06-06T12:34:56+00:00'));
        @unlink($p1);
        @unlink($p2);

        foreach (['organizations.json', 'standings.csv', 'standings.json'] as $arquivo) {
            $this->assertSame(
                $primeiro[$arquivo],
                $segundo[$arquivo],
                "{$arquivo} mudou entre duas exportacoes da mesma prova"
            );
        }

        $a = json_decode($primeiro['manifest.json'], true);
        $b = json_decode($segundo['manifest.json'], true);

        $this->assertNotSame($a['generated_at'], $b['generated_at'], 'o carimbo de tempo nao mudou');

        unset($a['generated_at'], $b['generated_at']);
        $this->assertSame($a, $b, 'o manifesto mudou em algo alem do carimbo de tempo');
    }

    /**
     * E o contêiner também, porque o ZIP guarda a data de cada entrada.
     *
     * Sem `setMtimeName()` fixo, dois pacotes de conteudo identico diferem
     * nos bytes -- e "idêntico byte a byte" viraria uma promessa que o
     * proprio formato quebra.
     */
    public function test_the_zip_container_itself_is_reproducible(): void
    {
        $p1 = $this->exportTo('cont-um', '2026-01-01T00:00:00+00:00');
        $this->travel(2)->days();
        $p2 = $this->exportTo('cont-dois', '2026-01-01T00:00:00+00:00');

        $a = hash_file('sha256', $p1);
        $b = hash_file('sha256', $p2);
        @unlink($p1);
        @unlink($p2);

        $this->assertSame($a, $b, 'o ZIP difere byte a byte apesar do conteudo identico');
    }

    public function test_the_problems_come_out_in_contest_order(): void
    {
        $path = $this->exportTo('ordem');
        $doc = json_decode($this->unzip($path)['standings.json'], true);
        @unlink($path);

        $this->assertSame(['A', 'B'], array_column($doc['problems'], 'label'));
        $this->assertSame([0, 1], array_column($doc['problems'], 'ordinal'));
    }

    /**
     * Dois problemas com o MESMO `sort_order` saem em ordem estavel.
     *
     * `Contest::problems()` ja ordenava por `sort_order`, entao a lista
     * nunca foi totalmente solta -- e o que faltava eram os desempates.
     * `sort_order` nao e unico, e `ordinal` sai do INDICE da colecao: um
     * empate fazia a posicao PUBLICADA dos dois trocar entre leituras, sem
     * nada ter mudado na prova.
     *
     * Os dois sao inseridos fora de ordem alfabetica de proposito: sem o
     * desempate por `short_name`, o banco devolve na ordem de insercao e
     * este teste cai.
     */
    public function test_problems_tied_on_sort_order_have_a_stable_position(): void
    {
        Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'D', 'sort_order' => 9]);
        Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'C', 'sort_order' => 9]);

        $path = $this->exportTo('empate');
        $doc = json_decode($this->unzip($path)['standings.json'], true);
        @unlink($path);

        $this->assertSame(
            ['A', 'B', 'C', 'D'],
            array_column($doc['problems'], 'label'),
            'problemas empatados em sort_order sairam na ordem de insercao'
        );
        $this->assertSame([0, 1, 2, 3], array_column($doc['problems'], 'ordinal'));
    }

    /**
     * A ordem dos problemas e DECLARADA, e nao herdada do motor.
     *
     * O teste acima verifica o comportamento, e nao guarda a propriedade:
     * medido, sem os desempates o SQLite devolve `A,B,C,D` mesmo assim --
     * por coincidencia do indice que ele escolhe. Num motor que escolhesse
     * outro plano, a posicao publicada trocaria.
     *
     * E a mesma familia do `PRAGMA foreign_keys = 0` (#293): garantia que a
     * plataforma de teste da de graca e a de producao pode nao dar.
     *
     * Entao a garantia e verificada onde ela existe -- no SQL que o escopo
     * produz. Remover qualquer um dos tres `orderBy` derruba isto.
     */
    public function test_the_contest_order_is_declared_in_the_query(): void
    {
        $sql = Problem::query()->inContestOrder()->toSql();

        $this->assertStringContainsString('order by', $sql);

        foreach (['sort_order', 'short_name', 'id'] as $coluna) {
            $this->assertStringContainsString(
                $coluna,
                substr($sql, strpos($sql, 'order by')),
                "a ordem dos problemas nao desempata por {$coluna}"
            );
        }
    }

    /**
     * As colunas de problema de cada equipe tambem saem em ordem declarada.
     *
     * Elas vinham de um `groupBy`, que nao a garante.
     */
    public function test_each_team_row_lists_its_problems_in_order(): void
    {
        $path = $this->exportTo('colunas');
        $doc = json_decode($this->unzip($path)['standings.json'], true);
        @unlink($path);

        $rotulos = array_column($doc['scoreboard'][0]['problems'], 'label');
        $ordenado = $rotulos;
        sort($ordenado);

        $this->assertSame($ordenado, $rotulos, 'as colunas de problema da equipe sairam fora de ordem');
        $this->assertNotEmpty($rotulos, 'a equipe saiu sem coluna de problema nenhuma');
    }

    /**
     * O mtime de cada entrada do ZIP e FIXO.
     *
     * A primeira versao deste teste comparava o hash de dois pacotes
     * exportados com `travel()` entre eles, e nao guardava nada: `travel()`
     * move o Carbon, e nao `time()`. A mutacao que troca a constante por
     * `time()` passava limpa, porque as duas exportacoes caem no mesmo
     * segundo de relogio real.
     *
     * Verificar a propriedade direto na entrada e o que realmente a guarda.
     */
    public function test_every_zip_entry_has_a_fixed_timestamp(): void
    {
        $path = $this->exportTo('mtime');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $mtimes = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $mtimes[$zip->getNameIndex($i)] = $stat['mtime'];
        }
        $zip->close();
        @unlink($path);

        $this->assertCount(4, $mtimes);
        $this->assertSame(
            [946684800],
            array_values(array_unique($mtimes)),
            'as entradas do ZIP nao tem carimbo de tempo fixo, entao dois pacotes iguais diferem nos bytes'
        );
    }

    // ---------------------------------------------------------------
    // 3. Integridade.
    // ---------------------------------------------------------------

    public function test_every_file_hash_matches_the_manifest(): void
    {
        $path = $this->exportTo('hash');
        $entradas = $this->unzip($path);
        @unlink($path);

        $manifest = json_decode($entradas['manifest.json'], true);

        $this->assertNotEmpty($manifest['files'], 'o manifesto saiu sem arquivo nenhum');

        foreach ($manifest['files'] as $nome => $meta) {
            $this->assertArrayHasKey($nome, $entradas, "o manifesto cita {$nome}, que nao esta no pacote");
            $this->assertSame(hash('sha256', $entradas[$nome]), $meta['sha256'], "o hash de {$nome} nao confere");
            $this->assertSame(strlen($entradas[$nome]), $meta['bytes']);
        }
    }

    /**
     * Alterar UM byte tem de derrubar a conferencia -- senao o checksum e
     * decoracao.
     */
    public function test_changing_one_byte_breaks_the_check(): void
    {
        $path = $this->exportTo('adulterado');
        $entradas = $this->unzip($path);
        @unlink($path);

        $manifest = json_decode($entradas['manifest.json'], true);
        $adulterado = $entradas['standings.csv'].' ';

        $this->assertNotSame(
            $manifest['files']['standings.csv']['sha256'],
            hash('sha256', $adulterado),
            'o checksum aceitou conteudo alterado'
        );
    }

    /**
     * O manifesto cobre TODOS os arquivos do pacote, menos ele proprio -- um
     * arquivo sem hash e um arquivo que ninguem confere.
     */
    public function test_the_manifest_covers_every_file_but_itself(): void
    {
        $path = $this->exportTo('cobertura');
        $entradas = $this->unzip($path);
        @unlink($path);

        $manifest = json_decode($entradas['manifest.json'], true);

        $noPacote = array_keys($entradas);
        sort($noPacote);
        $cobertos = array_keys($manifest['files']);
        sort($cobertos);

        $this->assertSame(
            array_values(array_diff($noPacote, ['manifest.json'])),
            $cobertos,
            'ha arquivo no pacote que o manifesto nao confere'
        );
    }

    // ---------------------------------------------------------------
    // 4. Identidade.
    // ---------------------------------------------------------------

    public function test_the_manifest_identifies_the_contest_beyond_this_installation(): void
    {
        $path = $this->exportTo('identidade');
        $manifest = json_decode($this->unzip($path)['manifest.json'], true);
        @unlink($path);

        $c = $manifest['contest'];

        $this->assertNotEmpty($c['uuid']);
        $this->assertSame($this->contest->uuid, $c['uuid']);
        $this->assertSame('Regional Sudeste', $c['name']);
        $this->assertSame('2026', $c['edition']);
        $this->assertSame('regional', $c['phase']);
        $this->assertSame(300, $c['duration_minutes']);
        $this->assertNotNull($c['finalized_at']);
        $this->assertSame(['Sede Central'], array_column($c['sites'], 'name'));
        $this->assertSame('microhelium-results-bundle', $manifest['format']);
    }

    /**
     * O uuid nasce com a prova e nao e reescrito: e o que permite dizer
     * "este pacote e uma nova exportacao daquela prova".
     */
    public function test_the_contest_uuid_is_stable_across_updates(): void
    {
        $antes = $this->contest->uuid;

        $this->contest->update(['name' => 'Outro Nome', 'edition' => '2027']);

        $this->assertSame($antes, $this->contest->fresh()->uuid);
    }

    public function test_two_contests_get_different_uuids(): void
    {
        $outra = Contest::factory()->create();

        $this->assertNotSame($this->contest->uuid, $outra->uuid);
        $this->assertNotEmpty($outra->uuid);
    }

    // ---------------------------------------------------------------
    // 5. Fidelidade.
    // ---------------------------------------------------------------

    /**
     * A classificacao do pacote bate com a da tela: Beta resolveu dois, Alfa
     * um.
     */
    public function test_the_standings_match_the_final_scoreboard(): void
    {
        $path = $this->exportTo('fidelidade');
        $entradas = $this->unzip($path);
        @unlink($path);

        $doc = json_decode($entradas['standings.json'], true);
        $placar = $doc['scoreboard'];

        $this->assertSame(1, $placar[0]['rank']);
        $this->assertSame(2, $placar[0]['problems_solved'], 'o primeiro colocado nao resolveu dois');
        $this->assertSame((string) $this->beta->user_id, $placar[0]['team_id']);
        $this->assertSame(1, $placar[1]['problems_solved']);

        // E o CSV do BOCA carrega a mesma historia.
        $this->assertStringContainsString('BR-BETA', $entradas['standings.csv']);
        $this->assertStringContainsString('BR-ALFA', $entradas['standings.csv']);
    }

    /**
     * O pacote sai DESCONGELADO, e sem alternativa: a prova esta finalizada,
     * entao o que sai aqui e a classificacao final. Um pacote congelado
     * seria um pacote que esconde o resultado, o que nao e resultado nenhum.
     */
    public function test_the_bundle_is_never_frozen(): void
    {
        // Congelamento ainda de pe -- `unfrozen_at` nulo.
        $this->assertNull($this->contest->unfrozen_at);

        $path = $this->exportTo('congelado');
        $doc = json_decode($this->unzip($path)['standings.json'], true);
        @unlink($path);

        $this->assertSame(2, $doc['scoreboard'][0]['problems_solved'], 'o pacote saiu congelado');
    }

    public function test_the_team_identity_needed_for_aggregation_is_present(): void
    {
        $path = $this->exportTo('agregacao');
        $entradas = $this->unzip($path);
        @unlink($path);

        $doc = json_decode($entradas['standings.json'], true);
        $orgs = json_decode($entradas['organizations.json'], true);

        $icpcIds = array_column($doc['teams'], 'icpc_id');
        sort($icpcIds);
        $this->assertSame(['BR-ALFA', 'BR-BETA'], $icpcIds);

        $this->assertSame('ufmg', $orgs[0]['icpc_id']);
        $this->assertSame('Universidade Federal de Minas Gerais', $orgs[0]['formal_name']);
        $this->assertSame('BRA', $orgs[0]['country']);
    }

    /**
     * Quem ficou sem `icpc_id` e nomeado NO PACOTE.
     *
     * O filer precisa saber se o arquivo que vai enviar esta incompleto, e a
     * informacao some se so existir num cabecalho HTTP.
     */
    public function test_teams_missing_the_aggregation_key_are_named_in_the_bundle(): void
    {
        $this->alfa->update(['icpc_id' => null]);

        $path = $this->exportTo('faltando');
        $doc = json_decode($this->unzip($path)['standings.json'], true);
        @unlink($path);

        $this->assertNotEmpty($doc['teams_missing_icpc_id'], 'o pacote nao avisou que ha equipe sem icpc_id');
    }

    // ---------------------------------------------------------------
    // 6. Privacidade -- varre o ZIP inteiro.
    // ---------------------------------------------------------------

    /**
     * Nenhum campo protegido pela ProfilePrivacyPolicy aparece em arquivo
     * nenhum do pacote.
     *
     * A regua: o pacote nao exporta mais do que o placar publico ja mostra,
     * mais a chave de agregacao. Este sistema tem contas gerenciadas de
     * menores com `profile_visibility` privado por padrao (#47), e um pacote
     * que vaze nascimento ou e-mail vaza para um site nacional inteiro.
     */
    public function test_no_protected_field_appears_anywhere_in_the_bundle(): void
    {
        $path = $this->exportTo('privacidade');
        $entradas = $this->unzip($path);
        @unlink($path);

        $tudo = implode("\n", $entradas);

        foreach (['2010-05-04', 'alfa@example.com', 'beta@example.com', 'birthdate', 'password', 'remember_token'] as $proibido) {
            $this->assertStringNotContainsString(
                $proibido,
                $tudo,
                "o pacote vazou '{$proibido}'"
            );
        }
    }

    /**
     * O controle positivo da varredura: ela ENCONTRA o que esta la.
     *
     * Sem isto, o teste acima passaria igual se o pacote saisse vazio, ou se
     * a busca estivesse quebrada -- que e o modo de falha catalogado deste
     * repositorio.
     */
    public function test_the_privacy_sweep_can_actually_find_things(): void
    {
        $path = $this->exportTo('varredura');
        $entradas = $this->unzip($path);
        @unlink($path);

        $tudo = implode("\n", $entradas);

        $this->assertStringContainsString('Equipe Alfa', $tudo, 'a varredura nao acha nem o que DEVE estar la');
        $this->assertStringContainsString('BR-BETA', $tudo);
    }

    // ---------------------------------------------------------------
    // O endpoint.
    // ---------------------------------------------------------------

    private function admin(): User
    {
        return $this->createTestUser([
            'email' => 'admin-pacote@example.com',
            'username' => 'admin-pacote',
            'user_type' => User::TYPE_ADMIN,
        ]);
    }

    public function test_an_admin_downloads_the_bundle(): void
    {
        $resposta = $this->actingAs($this->admin())
            ->get("/api/frontend/contests/{$this->contest->id}/results-bundle");

        $resposta->assertSuccessful()->assertHeader('content-type', 'application/zip');

        // `download()` mescla os cabecalhos dele com os nossos, entao a
        // string exata varia com a versao do framework -- o que importa e
        // que o pacote nao sente em cache compartilhado: ele traz icpc_id,
        // que e identificador pessoal.
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('cache-control'));

        // E o corpo e um ZIP legivel, e nao uma pagina de erro com 200.
        $this->assertStringStartsWith('PK', $resposta->streamedContent());
    }

    public function test_an_unfinalized_contest_is_refused_with_a_readable_reason(): void
    {
        $this->contest->update(['finalized_at' => null]);

        $this->actingAs($this->admin())
            ->getJson("/api/frontend/contests/{$this->contest->id}/results-bundle")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Esta prova ainda nao foi finalizada. Exportar resultado provisorio -- com rejulgamento pendente, ou com o placar ainda congelado -- envenena o agregado nacional. Finalize a prova primeiro.']);
    }

    /**
     * `edition` e `phase` precisam de ESCRITOR, senao nascem mortas -- o
     * mesmo padrao que a #284 e a #276 consertaram em outras colunas.
     */
    public function test_an_admin_can_set_the_contest_edition_and_phase(): void
    {
        $this->actingAs($this->admin())->put("/backend/contest/{$this->contest->id}/update", [
            'name' => 'Regional Sudeste',
            'description' => 'x',
            'start_time' => $this->contest->start_time->format('Y-m-d\TH:i'),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'edition' => '2027',
            'phase' => 'final nacional',
        ])->assertRedirect();

        $fresco = $this->contest->fresh();

        $this->assertSame('2027', $fresco->edition, 'a edicao nao foi gravada');
        $this->assertSame('final nacional', $fresco->phase, 'a fase nao foi gravada');
    }

    /**
     * Campo em branco vira null, e nao string vazia: "" no manifesto e pior
     * que ausente, porque o consumidor teria que tratar dois jeitos de dizer
     * "nao informado".
     *
     * Quem converte e o `ConvertEmptyStringsToNull` do grupo `web`, que roda
     * antes da validacao -- medido. Este teste guarda o RESULTADO, e nao a
     * linha que o produz: se alguem tirar aquele middleware, ou trocar a
     * rota de grupo, ele cai.
     */
    public function test_a_blank_edition_becomes_null_and_not_an_empty_string(): void
    {
        $this->actingAs($this->admin())->put("/backend/contest/{$this->contest->id}/update", [
            'name' => 'Regional Sudeste',
            'description' => 'x',
            'start_time' => $this->contest->start_time->format('Y-m-d\TH:i'),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'edition' => '',
            'phase' => '',
        ])->assertRedirect();

        $fresco = $this->contest->fresh();

        $this->assertNull($fresco->edition);
        $this->assertNull($fresco->phase);

        $fresco->update(['finalized_at' => now()]);
        // `exportTo()` usa `$this->contest`, que ainda traz a edicao antiga
        // em memoria -- sem o refresh este teste leria o objeto, e nao o
        // banco.
        $this->contest->refresh();
        $path = $this->exportTo('vazio');
        $manifest = json_decode($this->unzip($path)['manifest.json'], true);
        @unlink($path);

        $this->assertNull($manifest['contest']['edition'], 'o manifesto trouxe string vazia em vez de null');
    }

    public function test_a_team_cannot_download_the_bundle(): void
    {
        $this->actingAs($this->alfa)
            ->get("/api/frontend/contests/{$this->contest->id}/results-bundle")
            ->assertForbidden();
    }
}
