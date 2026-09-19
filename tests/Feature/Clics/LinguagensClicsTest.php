<?php

namespace Tests\Feature\Clics;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Services\Clics\ClicsLanguageIdentifiers;
use Tests\TestCase;

/**
 * Issue #333 -- o `languages` da Contest API, medido contra o CATALOGO
 * inteiro e contra o schema oficial.
 *
 * Os dois defeitos que esta classe fixa nao apareciam num cenario de uma
 * linguagem so:
 *
 * - o `id` era o autoincremento de `languages`, que e `contest_id`-scoped:
 *   "3" era Java numa prova e Rust na seguinte, e a spec e explicita --
 *   "IDs are assigned by the person or system that is the source of the
 *   object, and must be maintained by downstream systems";
 * - o `extensions` trazia `languages.extension`, que e o SLUG interno
 *   ("cpp_gpp13"), e nao a extensao do arquivo ("cpp"). O proprio modelo tem
 *   getFileExtension() so por causa dessa confusao.
 *
 * Por isso estes testes montam a prova com o catalogo INTEIRO em vez de uma
 * factory: o dano da troca de coluna e proporcional ao catalogo, e o
 * catalogo cresceu de 16 para 50 linguagens ativas com o #305/#310. Medir
 * com uma linguagem inventada mediria a nossa expectativa, nao o catalogo.
 */
class LinguagensClicsTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function catalogoAtivo(): array
    {
        return array_values(array_filter(
            Language::getDefaultLanguages(),
            fn (array $linguagem) => $linguagem['is_active']
        ));
    }

    private function provaComCatalogo(): Contest
    {
        $contest = Contest::factory()->create(['is_public' => true, 'is_active' => true]);

        foreach ($this->catalogoAtivo() as $linguagem) {
            Language::create([
                'contest_id' => $contest->id,
                'name' => $linguagem['name'],
                'extension' => $linguagem['extension'],
                'compile_command' => $linguagem['compile_command'],
                'run_command' => $linguagem['run_command'],
                'is_active' => true,
            ]);
        }

        return $contest->fresh();
    }

    /**
     * Controle positivo: o catalogo existe e e do tamanho que esta issue
     * descreve. Sem isto, tudo abaixo passaria sobre uma lista vazia.
     */
    public function test_o_catalogo_ativo_nao_encolheu(): void
    {
        $ativas = $this->catalogoAtivo();

        $this->assertGreaterThanOrEqual(
            50,
            count($ativas),
            'o catalogo de linguagens ativas encolheu: confira se esta medicao ainda e sobre o mesmo catalogo'
        );

        $trocadas = array_filter($ativas, fn (array $l) => $l['extension'] !== $l['file_ext']);

        $this->assertGreaterThan(
            0,
            count($trocadas),
            'nenhuma linguagem ativa tem slug diferente da extensao: este teste deixaria de medir a troca de coluna'
        );
    }

    /**
     * "In case multiple versions of a language are provided, those must have
     * separate, unique identifiers." (Known languages)
     *
     * Dois ids iguais fariam duas linguagens diferentes virarem a mesma no
     * consumidor -- e a tabela tem sete pares que so se distinguem pela
     * versao (C++ do G++ e do Clang, Java 25 e 21, dois Common Lisp, dois
     * Prolog, dois Pascal, duas versoes de Fortran).
     */
    public function test_nenhum_identificador_clics_se_repete(): void
    {
        $tabela = ClicsLanguageIdentifiers::tabela();
        $repetidos = array_keys(array_filter(array_count_values($tabela), fn (int $n) => $n > 1));

        $this->assertSame([], $repetidos, 'identificador CLICS repetido: '.implode(', ', $repetidos));
    }

    /**
     * Toda linguagem ATIVA tem identificador deliberado.
     *
     * O fallback (o proprio slug) existe para a instalacao que criou uma
     * linguagem propria, e nao para o catalogo de fabrica: uma linguagem que
     * chega numa leva nova e sai no feed como `cpp_gpp13` seria justamente o
     * defeito que esta issue corrige, voltando pela porta dos fundos.
     */
    public function test_toda_linguagem_ativa_do_catalogo_tem_identificador_declarado(): void
    {
        $tabela = ClicsLanguageIdentifiers::tabela();
        $faltando = [];

        foreach ($this->catalogoAtivo() as $linguagem) {
            if (! array_key_exists($linguagem['extension'], $tabela)) {
                $faltando[] = $linguagem['extension'];
            }
        }

        $this->assertSame(
            [],
            $faltando,
            'linguagem ativa sem identificador CLICS (acrescente em ClicsLanguageIdentifiers): '.implode(', ', $faltando)
        );
    }

    /**
     * O `pattern` de `common.json#/identifier`, lido do schema oficial e nao
     * copiado para ca.
     */
    public function test_os_identificadores_batem_com_o_pattern_do_schema_oficial(): void
    {
        $common = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/clics/json-schema/common.json'),
            true
        );

        $pattern = $common['identifier']['pattern'] ?? null;

        $this->assertNotNull($pattern, 'o pattern de identifier sumiu de common.json: esta checagem nao checaria nada');

        foreach (ClicsLanguageIdentifiers::tabela() as $slug => $id) {
            $this->assertMatchesRegularExpression(
                '/'.str_replace('/', '\/', $pattern).'/',
                $id,
                "o identificador de {$slug} nao e um ID valido para a spec"
            );
        }
    }

    /**
     * O defeito 1 medido no endpoint: nenhum `id` e o autoincremento.
     */
    public function test_o_id_emitido_e_o_identificador_clics_e_nao_o_autoincremento(): void
    {
        $contest = $this->provaComCatalogo();

        $emitidas = $this->getJson("/api/clics/contests/{$contest->id}/languages")->assertStatus(200)->json();

        $this->assertCount(count($this->catalogoAtivo()), $emitidas);

        $porNome = collect($emitidas)->keyBy('name');

        foreach (Language::where('contest_id', $contest->id)->get() as $linha) {
            $emitida = $porNome[$linha->name];

            $this->assertSame(
                ClicsLanguageIdentifiers::para($linha->extension),
                $emitida['id'],
                "{$linha->name} nao saiu com o identificador CLICS"
            );

            $this->assertNotSame(
                (string) $linha->id,
                $emitida['id'],
                "{$linha->name} saiu com o autoincremento do banco como id: ele muda de prova para prova"
            );
        }

        // E os ids sao unicos DENTRO da prova -- dois iguais colapsariam duas
        // linguagens numa so no consumidor.
        $ids = array_column($emitidas, 'id');
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    /**
     * O defeito 2, medido onde ele doi: das linguagens ativas, as que tem
     * slug diferente da extensao emitiam uma extensao que nao existe.
     */
    public function test_extensions_traz_a_extensao_do_arquivo_e_nao_o_slug(): void
    {
        $contest = $this->provaComCatalogo();

        $emitidas = collect($this->getJson("/api/clics/contests/{$contest->id}/languages")->assertStatus(200)->json())
            ->keyBy('name');

        $conferidas = 0;

        foreach ($this->catalogoAtivo() as $linguagem) {
            $emitida = $emitidas[$linguagem['name']];

            $this->assertSame(
                [$linguagem['file_ext']],
                $emitida['extensions'],
                "{$linguagem['name']} emitiu a extensao errada"
            );

            $conferidas += $linguagem['extension'] !== $linguagem['file_ext'] ? 1 : 0;
        }

        $this->assertGreaterThanOrEqual(
            20,
            $conferidas,
            'o cenario deixou de conter linguagens cujo slug difere da extensao: seria um verde sobre nada'
        );
    }

    /**
     * `language.json` tem "required": ["id","name","entry_point_required",
     * "extensions"], e o ramo `else` do if/then/else proibe
     * `entry_point_name` quando `entry_point_required` e falso.
     */
    public function test_entry_point_required_sai_em_toda_linguagem(): void
    {
        $contest = $this->provaComCatalogo();

        foreach ($this->getJson("/api/clics/contests/{$contest->id}/languages")->assertStatus(200)->json() as $linguagem) {
            $this->assertArrayHasKey('entry_point_required', $linguagem);
            $this->assertIsBool($linguagem['entry_point_required']);
            $this->assertArrayNotHasKey(
                'entry_point_name',
                $linguagem,
                'com entry_point_required=false o schema proibe entry_point_name'
            );
        }
    }

    /**
     * A metade que impede o conserto de virar um defeito pior: o
     * `language_id` do envio tem de ser o MESMO id que /languages publica.
     */
    public function test_o_language_id_do_envio_casa_com_o_id_publicado(): void
    {
        $contest = $this->provaComCatalogo();
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $problema = Problem::factory()->create(['contest_id' => $contest->id, 'short_name' => 'A']);
        Answer::factory()->create(['contest_id' => $contest->id, 'short_name' => 'YES', 'is_accepted' => true]);

        $equipe = $this->createTestUser([
            'fullname' => 'Equipe Alfa',
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ]);

        $linguagem = Language::where('contest_id', $contest->id)->where('extension', 'cpp_gpp13')->firstOrFail();

        Run::factory()->create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $equipe->user_id,
            'problem_id' => $problema->id,
            'language_id' => $linguagem->id,
        ]);

        $envios = $this->getJson("/api/clics/contests/{$contest->id}/submissions")->assertStatus(200)->json();
        $publicados = array_column(
            $this->getJson("/api/clics/contests/{$contest->id}/languages")->assertStatus(200)->json(),
            'id'
        );

        $this->assertNotEmpty($envios);
        $this->assertSame('cpp', $envios[0]['language_id'], 'o envio em C++ (G++) tem de sair como cpp');
        $this->assertContains($envios[0]['language_id'], $publicados);
    }
}
