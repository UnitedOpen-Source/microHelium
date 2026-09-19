<?php

namespace Tests\Feature\Clics;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Organization;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Services\Clics\ContestEventRecorder;
use Tests\TestCase;

/**
 * Confere o que servimos contra os JSON Schemas OFICIAIS do ICPC.
 *
 * A diferenca para `EventFeedTest` e `ContestApiTest` e a fonte da verdade.
 * La as assercoes sao nossas: alguem leu a spec, entendeu, e escreveu um
 * `assertSame`. Um erro de leitura vira um teste verde sobre um feed errado,
 * que e exatamente o modo de falha que este repositorio ja catalogou. Aqui a
 * referencia e o arquivo publicado pelo ICPC, copiado em
 * `tests/Fixtures/clics/json-schema/` -- ver PROVENIENCIA.md ao lado.
 *
 * O QUE ESTE TESTE VERIFICA, e so isto: as palavras-chave `required` e
 * `enum` dos schemas oficiais, mais campos nao-anulaveis servidos como
 * `null`. Nao e um validador de JSON Schema completo, e nao finge ser --
 * `pattern`, `oneOf`, `if/then` e `$ref` aninhado ficam de fora. Escolhido
 * assim porque essas tres perguntas cobrem toda divergencia que a auditoria
 * de conformidade CLICS encontrou (#328, #330, #332, #333), e porque um
 * validador completo em PHP puro seria mais codigo nosso -- de novo --
 * entre a spec e a medicao.
 *
 * ## A lista de divergencias conhecidas
 *
 * Este teste NAO exige conformidade total: hoje nao ha. Ele exige que o
 * conjunto de violacoes seja EXATAMENTE `DIVERGENCIAS_CONHECIDAS`, e cada
 * entrada aponta a issue que a resolve. Isso faz a lista falhar dos dois
 * lados:
 *
 * - uma violacao NOVA quebra a suite (regressao);
 * - uma violacao que deixou de acontecer TAMBEM quebra a suite, com a
 *   mensagem dizendo qual issue fechar e qual linha apagar.
 *
 * O segundo e o que impede a lista de apodrecer em "exceções que ninguem
 * lembra por que estao ali".
 */
class ConformidadeClicsTest extends TestCase
{
    /**
     * Violacao => issue que a resolve.
     *
     * @var array<string, string>
     */
    private const DIVERGENCIAS_CONHECIDAS = [
        // Issue #330 -- a linha do feed ainda esta no formato anterior a
        // 2020-03. `op` sumiu da spec em 2023-06, que e a versao que o nosso
        // proprio endpoint `api` declara.
        'event-feed: propriedade "op" nao existe na linha de evento' => '#330',
        'event-feed: type "contests" fora do enum de common.json#/endpointssingularcontest' => '#330',
        'event-feed: id nao-nulo no singleton "state"' => '#330',

        // Issue #332 -- objetos obrigatorios que nao emitimos.
        'languages[0]: falta "entry_point_required"' => '#332 (e #333)',
        'judgements[0]: falta "start_time"' => '#332',
        'submissions[0]: falta "files"' => '#332',
        'teams[0]: falta "label"' => '#332',
        'problems[0]: falta "test_data_count"' => '#332',
        'problems[0]: "rgb" e null num campo nao-anulavel' => '#332',
        'problems[0]: "color" e null num campo nao-anulavel' => '#332',
        'contests[0]: falta "scoreboard_type"' => '#332',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];

    private Contest $contest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carregarSchemas();
    }

    /**
     * Controle positivo.
     *
     * Sem isto, um diretorio de fixtures que sumisse de lugar faria todo o
     * resto passar sobre zero schemas -- guarda que nao guarda. Foi a licao
     * que `EventFeedStreamingContractTest::nginxConf()` ja tinha escrito
     * para os arquivos do nginx.
     */
    public function test_os_schemas_oficiais_estao_no_lugar_e_tem_conteudo(): void
    {
        $this->assertGreaterThanOrEqual(30, count($this->schemas), 'a copia dos schemas do ICPC encolheu ou mudou de lugar');

        foreach (['common.json', 'event-feed.json', 'language.json', 'judgement.json', 'submission.json', 'team.json', 'problem.json', 'contest.json', 'state.json'] as $arquivo) {
            $this->assertArrayHasKey($arquivo, $this->schemas, "falta o schema oficial {$arquivo}");
        }

        $this->assertNotEmpty(
            $this->schemas['common.json']['endpointssingularcontest']['enum'] ?? [],
            'o enum de tipos de evento sumiu de common.json: as checagens de type passariam a nao checar nada'
        );

        $this->assertContains('contest', $this->schemas['common.json']['endpointssingularcontest']['enum']);
    }

    public function test_o_feed_e_os_endpoints_rest_batem_com_os_schemas_do_icpc(): void
    {
        $violacoes = $this->medirViolacoes();

        $conhecidas = array_keys(self::DIVERGENCIAS_CONHECIDAS);

        $novas = array_values(array_diff($violacoes, $conhecidas));
        $sumiram = array_values(array_diff($conhecidas, $violacoes));

        $this->assertSame([], $novas, "divergencia NOVA contra o JSON Schema oficial do ICPC:\n  - ".implode("\n  - ", $novas));

        $this->assertSame(
            [],
            $sumiram,
            "divergencia registrada em DIVERGENCIAS_CONHECIDAS nao acontece mais.\n".
            "Apague a linha e feche a issue correspondente:\n  - ".
            implode("\n  - ", array_map(fn (string $v) => $v.'  ('.self::DIVERGENCIAS_CONHECIDAS[$v].')', $sumiram))
        );
    }

    /**
     * @return list<string>
     */
    private function medirViolacoes(): array
    {
        $this->semearProva();

        $violacoes = [];

        // --- event feed ---
        $corpo = $this->get("/api/clics/contests/{$this->contest->id}/event-feed")
            ->assertStatus(200)->streamedContent();

        $envelope = ['id', 'type', 'data', 'token'];
        $tipos = $this->schemas['common.json']['endpointssingularcontest']['enum'];
        $singletons = ['state'];

        $n = 0;
        foreach (explode("\n", trim($corpo)) as $linha) {
            if ($linha === '') {
                continue;
            }
            $n++;
            $evento = json_decode($linha, true);
            $this->assertIsArray($evento, "linha {$n} do feed nao e JSON valido");

            // Sem numero de linha de proposito: a violacao e do FORMATO, e
            // nao da linha. Chavear por numero faria a lista abaixo virar
            // vermelha toda vez que alguem acrescentasse um envio ao
            // cenario, que nao e o que se quer saber.
            foreach (array_diff(array_keys($evento), $envelope) as $extra) {
                $violacoes[] = "event-feed: propriedade \"{$extra}\" nao existe na linha de evento";
            }

            foreach ($this->schemas['event-feed.json']['required'] as $obrigatoria) {
                if (! array_key_exists($obrigatoria, $evento)) {
                    $violacoes[] = "event-feed: falta \"{$obrigatoria}\" na linha de evento";
                }
            }

            $tipo = $evento['type'] ?? null;

            if (! in_array($tipo, $tipos, true)) {
                $violacoes[] = "event-feed: type \"{$tipo}\" fora do enum de common.json#/endpointssingularcontest";
            }

            if (in_array($tipo, $singletons, true) && ($evento['id'] ?? null) !== null) {
                $violacoes[] = "event-feed: id nao-nulo no singleton \"{$tipo}\"";
            }
        }

        $this->assertGreaterThan(5, $n, 'o feed veio vazio: nao haveria o que conferir');

        // --- REST ---
        foreach ($this->endpointsRest() as $nome => [$url, $schema, $lista]) {
            $corpo = json_decode($this->get($url)->assertStatus(200)->getContent(), true);
            $itens = $lista ? $corpo : [$corpo];

            foreach ($itens as $i => $item) {
                foreach ($this->violacoesDe($item, $schema) as $v) {
                    $violacoes[] = "{$nome}[{$i}]: {$v}";
                }
            }
        }

        sort($violacoes);

        return array_values(array_unique($violacoes));
    }

    /**
     * `required` ausente, e campo nao-anulavel servido como `null`.
     *
     * O segundo vem de "Must only have `null` values if the type of the
     * property is `<type> ?`" (Contest API, Table column description). Um
     * `null` num campo que a spec tipa como `string` e o erro que o
     * consumidor descobre quando tenta usar o valor, e nao quando o recebe.
     *
     * @param  array<string, mixed>  $objeto
     * @return list<string>
     */
    private function violacoesDe(array $objeto, string $schema): array
    {
        $def = $this->schemas[$schema];
        $violacoes = [];

        foreach ($def['required'] ?? [] as $obrigatoria) {
            // Ausente, e nao "ausente ou null": ha obrigatorias anulaveis
            // -- `state.ended` e `TIME ?` e e null antes da prova acabar.
            // `null` onde a spec NAO permite e a checagem seguinte.
            if (! array_key_exists($obrigatoria, $objeto)) {
                $violacoes[] = "falta \"{$obrigatoria}\"";
            }
        }

        foreach ($def['properties'] ?? [] as $nome => $tipo) {
            // `array_key_exists` e nao `??`: o operador dispara em null, que
            // e justamente o valor que esta checagem existe para encontrar.
            if (! array_key_exists($nome, $objeto) || $objeto[$nome] !== null) {
                continue;
            }

            // Anulavel de tres jeitos nos schemas do ICPC: `"type": [...,
            // "null"]`, `oneOf` com `{"type":"null"}`, ou um `$ref` para
            // um `...ornull` de common.json.
            $anulavel = is_array($tipo['type'] ?? null)
                || isset($tipo['oneOf'])
                || str_contains((string) ($tipo['$ref'] ?? ''), 'ornull');

            if (! $anulavel && isset($tipo['type'])) {
                $violacoes[] = "\"{$nome}\" e null num campo nao-anulavel";
            }
        }

        return $violacoes;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    private function endpointsRest(): array
    {
        $id = $this->contest->id;

        return [
            'contests' => ['/api/clics/contests', 'contest.json', true],
            'problems' => ["/api/clics/contests/{$id}/problems", 'problem.json', true],
            'teams' => ["/api/clics/contests/{$id}/teams", 'team.json', true],
            'organizations' => ["/api/clics/contests/{$id}/organizations", 'organization.json', true],
            'groups' => ["/api/clics/contests/{$id}/groups", 'group.json', true],
            'languages' => ["/api/clics/contests/{$id}/languages", 'language.json', true],
            'judgement-types' => ["/api/clics/contests/{$id}/judgement-types", 'judgement-type.json', true],
            'submissions' => ["/api/clics/contests/{$id}/submissions", 'submission.json', true],
            'judgements' => ["/api/clics/contests/{$id}/judgements", 'judgement.json', true],
            'state' => ["/api/clics/contests/{$id}/state", 'state.json', false],
        ];
    }

    private function carregarSchemas(): void
    {
        $dir = dirname(__DIR__, 2).'/Fixtures/clics/json-schema';

        foreach (glob($dir.'/*.json') ?: [] as $arquivo) {
            $this->schemas[basename($arquivo)] = json_decode((string) file_get_contents($arquivo), true);
        }
    }

    /**
     * Uma prova pequena, mas com um objeto de cada tipo que o feed emite:
     * conferir `required` exige que o objeto exista.
     */
    private function semearProva(): void
    {
        $this->contest = Contest::factory()->frozen()->create(['is_public' => true]);
        $site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $organizacao = Organization::create([
            'name' => 'Universidade Teste',
            'formal_name' => 'Universidade Teste',
            'country' => 'BRA',
            'icpc_id' => 'INST-1',
        ]);
        $problema = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'A']);
        $linguagem = Language::factory()->create(['contest_id' => $this->contest->id, 'name' => 'C++', 'extension' => 'cpp']);
        $certo = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);

        $equipe = $this->createTestUser([
            'fullname' => 'Equipe Alfa',
            'contest_id' => $this->contest->id,
            'site_id' => $site->id,
            'organization_id' => $organizacao->id,
            'icpc_id' => '20260001',
        ]);

        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $site->id,
            'user_id' => $equipe->user_id,
            'problem_id' => $problema->id,
            'language_id' => $linguagem->id,
        ])->fresh();

        $run->update(['contest_time' => 600]);
        app(ContestEventRecorder::class)->submissionCreated($run->fresh());

        $run->update(['status' => 'judged', 'answer_id' => $certo->id, 'judged_time' => 605]);
        Score::updateScore($run->fresh());
        app(ContestEventRecorder::class)->judgementRecorded($run->fresh());

        app(ContestEventRecorder::class)->stateChanged($this->contest->fresh());
    }
}
