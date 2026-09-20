<?php

namespace Tests\Feature\Clics;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\ContestTimeAdjustment;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Services\Clics\ContestEventRecorder;
use Helium\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #319 -- o corte do congelamento, na Contest API, e o da SEDE do run.
 *
 * O #276 ligou duracao e congelamento proprios por sede e o #341 consertou o
 * placar. Os tres chamadores CLICS ficaram no corte do contest:
 * `ContestEventRecorder::isAfterFreeze()`, `ContestApiController::judgements()`
 * e `ClicsPresenter::frozenAt()`. Este arquivo mede os tres.
 *
 * ## O cenario, e por que ele tem duas sedes
 *
 * Prova de 300 min com congelamento de 60 -> corte do contest no minuto 240.
 *
 *   sede CURTA   duration 240, freeze 60  -> congela no minuto 180 DELA
 *   sede LONGA   duration 360, freeze 60  -> congela no minuto 300 DELA
 *   sede PADRAO  sem override             -> congela no minuto 240, como antes
 *
 * As duas direcoes, medidas -- e a segunda e tao necessaria quanto a
 * primeira, porque um corte que simplesmente esconde mais passaria na
 * primeira sozinha:
 *
 *   VAZAMENTO      um AC da sede CURTA no minuto 190 saia publico, embora
 *                  aquela sede ja tivesse congelado. E a ultima hora inteira
 *                  dela no telao enquanto ainda competia.
 *   ESCONDIDO      um AC da sede LONGA no minuto 245 era escondido, embora
 *   DEMAIS         aquela sede so congele no 300.
 *
 * ## O controle positivo
 *
 * Dois AC no MESMO minuto (190), variando so a janela da sede: o da CURTA
 * tem de sumir e o da LONGA tem de aparecer. E o que separa "o corte agora e
 * por sede" de "o corte mudou de numero" -- com um corte global qualquer, os
 * dois teriam o mesmo destino.
 */
class CongelamentoPorSedeClicsTest extends TestCase
{
    private Contest $contest;

    private Site $curta;

    private Site $longa;

    private Site $padrao;

    private Problem $problem;

    private Language $language;

    private Answer $yes;

    /** @var array<string, User> */
    private array $teams = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Minuto 250 de prova: a sede CURTA ja acabou (240) e continua
        // congelada, porque `unfrozen_at` e do contest e ninguem revelou.
        $this->contest = Contest::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'start_time' => now()->subMinutes(250),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'unfrozen_at' => null,
        ]);

        $this->curta = Site::factory()->create([
            'contest_id' => $this->contest->id,
            'duration' => 240,
            'freeze_time' => 60,
        ]);

        $this->longa = Site::factory()->create([
            'contest_id' => $this->contest->id,
            'duration' => 360,
            'freeze_time' => 60,
        ]);

        // Sem override: e a sede de toda prova existente, e o que garante
        // que esta leva nao mudou o comportamento de quem nao configurou
        // nada.
        $this->padrao = Site::factory()->create(['contest_id' => $this->contest->id]);

        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'A']);
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);
        $this->yes = Answer::factory()->create([
            'contest_id' => $this->contest->id,
            'short_name' => 'YES',
            'is_accepted' => true,
        ]);

        foreach (['curta' => $this->curta, 'longa' => $this->longa, 'padrao' => $this->padrao] as $nome => $site) {
            $this->teams[$nome] = $this->createTestUser([
                'fullname' => 'Equipe '.$nome,
                'contest_id' => $this->contest->id,
                'site_id' => $site->id,
            ]);
        }
    }

    // -- o event feed -------------------------------------------------------

    /**
     * O VAZAMENTO, e o controle positivo ao lado.
     *
     * Os dois AC estao no mesmo minuto. So a janela da sede difere.
     */
    public function test_o_feed_publico_esconde_o_julgamento_da_sede_que_ja_congelou(): void
    {
        $vazamento = $this->julga($this->envia('curta', 190));
        $controle = $this->julga($this->envia('longa', 190));

        $julgados = $this->julgamentosDoFeed();

        $this->assertNotContains(
            (string) $vazamento->id,
            $julgados,
            'a sede CURTA congelou no minuto 180 dela e o feed entregou um julgamento do minuto 190'
        );

        $this->assertContains(
            (string) $controle->id,
            $julgados,
            'controle positivo: o MESMO minuto, numa sede que ainda nao congelou, tem de aparecer'
        );
    }

    /**
     * A direcao oposta: nao esconder o que a sede nao precisa esconder.
     *
     * Menos grave que revelar, e o mesmo defeito -- e sem esta medicao um
     * corte que apenas escondesse mais passaria pelo teste de cima.
     */
    public function test_o_feed_publico_nao_esconde_o_julgamento_da_sede_com_janela_mais_longa(): void
    {
        $escondidoDemais = $this->julga($this->envia('longa', 245));

        $this->assertContains(
            (string) $escondidoDemais->id,
            $this->julgamentosDoFeed(),
            'a sede LONGA so congela no minuto 300 dela e o feed escondeu um julgamento do minuto 245'
        );
    }

    /**
     * A sede sem override continua exatamente como antes.
     *
     * Regressao mais provavel desta leva: trocar o corte por sede e mudar,
     * de tabela, o comportamento de toda prova que nunca configurou sede
     * nenhuma.
     */
    public function test_a_sede_sem_override_continua_no_corte_do_contest(): void
    {
        $antes = $this->julga($this->envia('padrao', 239));
        $depois = $this->julga($this->envia('padrao', 241));

        $julgados = $this->julgamentosDoFeed();

        $this->assertContains((string) $antes->id, $julgados);
        $this->assertNotContains((string) $depois->id, $julgados);
    }

    /**
     * Zero quer dizer "sem congelamento" POR SEDE (#276): "uma prova sem
     * congelamento cuja sede define trinta minutos congela naquela sede, e
     * so nela".
     *
     * A leitura antiga perguntava `contests.freeze_time` e devolvia
     * "nunca esconde" para aquela sede -- guarda que desligava o mecanismo
     * inteiro no caso em que ele era a unica protecao.
     */
    public function test_a_sede_que_congela_numa_prova_que_nao_congela_tambem_esconde(): void
    {
        $this->contest->update(['freeze_time' => 0]);
        $this->curta->update(['duration' => 240, 'freeze_time' => 30]);

        // Minuto 215: dentro dos trinta minutos finais da sede (210..240).
        $durante = $this->julga($this->envia('curta', 215));

        $this->assertNotContains(
            (string) $durante->id,
            $this->julgamentosDoFeed(),
            'a sede define congelamento de 30 min e o feed entregou um julgamento de dentro da janela dela'
        );
    }

    /**
     * Issue #198 -- o corte e comparado em tempo AJUSTADO, e nao cru.
     *
     * `cutoffSeconds()` esta em tempo QUE CONTA (`duration - freeze`).
     * Comparar `contest_time` cru com ele mede duas grandezas diferentes: com
     * uma hora removida da prova, o congelamento comecaria uma hora cedo
     * demais no feed -- e o placar, que ja compara ajustado desde o #211/#198,
     * discordaria dele. Duas verdades sobre o mesmo veredito.
     */
    public function test_o_corte_do_feed_conta_o_intervalo_removido_como_o_placar_conta(): void
    {
        // A prova inteira empurrada para tras: com a hora removida, a sede
        // PADRAO acaba em 360 de parede e congela em 300 de parede.
        $this->contest->update(['start_time' => now()->subMinutes(310)]);
        $this->contest->refresh();

        ContestTimeAdjustment::create([
            'contest_id' => $this->contest->id,
            'site_id' => null,
            'starts_at' => $this->contest->start_time->copy()->addMinutes(100),
            'ends_at' => $this->contest->start_time->copy()->addMinutes(160),
            'reason' => 'queda de energia',
            'created_by' => $this->staff()->user_id,
        ]);

        // Minuto 250 de parede = minuto 190 DE PROVA, e o corte da sede
        // PADRAO e 240 de prova. Tem de aparecer.
        $run = $this->julga($this->envia('padrao', 250));

        $this->assertContains(
            (string) $run->id,
            $this->julgamentosDoFeed(),
            'o feed escondeu um julgamento que, descontado o intervalo removido, e anterior ao congelamento'
        );
    }

    // -- /judgements --------------------------------------------------------

    /**
     * A mesma pergunta pela porta REST. Sao dois caminhos diferentes --
     * o feed decide na GRAVACAO e o /judgements filtra na LEITURA -- e por
     * isso a medicao e feita nos dois.
     */
    public function test_o_judgements_publico_mede_as_duas_direcoes_por_sede(): void
    {
        $vazamento = $this->julga($this->envia('curta', 190));
        $controle = $this->julga($this->envia('longa', 190));
        $escondidoDemais = $this->julga($this->envia('longa', 245));

        $ids = array_column($this->judgements(), 'id');

        $this->assertNotContains((string) $vazamento->id, $ids, 'vazou o julgamento de uma sede ja congelada');
        $this->assertContains((string) $controle->id, $ids, 'controle positivo: mesmo minuto, sede que nao congelou');
        $this->assertContains((string) $escondidoDemais->id, $ids, 'escondeu o que a sede LONGA nao precisa esconder');
    }

    /**
     * A banca continua vendo tudo: o corte por sede esconde do PUBLICO, e
     * nao de quem julga.
     */
    public function test_a_banca_ve_o_julgamento_que_o_publico_nao_ve(): void
    {
        $vazamento = $this->julga($this->envia('curta', 190));

        $ids = array_column($this->judgements($this->staff()), 'id');

        $this->assertContains((string) $vazamento->id, $ids);
    }

    /**
     * E no descongelamento o evento retido sai.
     *
     * Sem isto a correcao seria pior que o defeito: no feed um evento omitido
     * some para sempre, e o resolver nunca veria aquele julgamento.
     */
    public function test_o_descongelamento_libera_o_julgamento_retido_da_sede_curta(): void
    {
        $vazamento = $this->julga($this->envia('curta', 190));

        $this->contest->update(['unfrozen_at' => now()]);

        $this->assertContains((string) $vazamento->id, $this->julgamentosDoFeed());
    }

    // -- state.frozen -------------------------------------------------------

    /**
     * `state.frozen` e "Time when the scoreboard was frozen" (Contest API
     * 2023-06, Contest state), e e por CONTEST -- `state` e singleton na
     * spec. Com janelas por sede a pergunta vira QUAL instante publicar, e a
     * resposta e o PRIMEIRO: e quando este placar -- o unico que esta API
     * serve -- passou a esconder.
     *
     * O cenario e o intervalo em que a API se contradizia. Minuto 200 de
     * prova: a sede CURTA congelou no 180 e o /judgements ja filtra, mas o
     * corte do contest so chega no 240 -- e `frozen` saia NULL. O consumidor
     * recebia dado escondido com "nada esta congelado" ao lado.
     */
    public function test_state_frozen_e_o_instante_da_primeira_sede_a_congelar(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(200)]);
        $this->contest->refresh();

        $vazamento = $this->julga($this->envia('curta', 190));

        $state = $this->getJson("/api/clics/contests/{$this->contest->id}/state")
            ->assertStatus(200)->json();

        $this->assertNotContains(
            (string) $vazamento->id,
            array_column($this->judgements(), 'id'),
            'o cenario exige que a API ja esteja escondendo alguma coisa'
        );

        $this->assertNotNull(
            $state['frozen'],
            'a API ja esconde julgamentos e o state dizia que nada estava congelado'
        );

        $this->assertSame(
            $this->contest->start_time->copy()->addMinutes(180)->toIso8601String(),
            $state['frozen'],
            'o instante publicado tem de ser o congelamento da sede CURTA, que foi a primeira'
        );
    }

    /**
     * Antes de QUALQUER sede congelar, `frozen` continua null.
     *
     * A armadilha de sinal oposto, a mesma que o #189 fechou no predicado:
     * uma implementacao que sempre publicasse o instante passaria no teste
     * de cima e diria "congelado" desde o primeiro minuto da prova.
     */
    public function test_state_frozen_e_null_antes_de_a_primeira_sede_congelar(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(10)]);

        $state = $this->getJson("/api/clics/contests/{$this->contest->id}/state")
            ->assertStatus(200)->json();

        $this->assertNotNull($state['started']);
        $this->assertNull($state['frozen']);
    }

    /**
     * Prova sem congelamento nenhum, em sede nenhuma: `frozen` e null mesmo
     * depois de a prova acabar -- e nao o instante do fim.
     */
    public function test_state_frozen_e_null_quando_nenhuma_sede_congela(): void
    {
        $this->contest->update(['freeze_time' => 0, 'start_time' => now()->subMinutes(400)]);
        $this->curta->update(['freeze_time' => 0]);
        $this->longa->update(['freeze_time' => 0]);

        $state = $this->getJson("/api/clics/contests/{$this->contest->id}/state")
            ->assertStatus(200)->json();

        $this->assertNotNull($state['ended'], 'o cenario exige uma prova ja terminada');
        $this->assertNull($state['frozen']);
    }

    // -- apoio --------------------------------------------------------------

    private function envia(string $sede, int $minuto): Run
    {
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->{$sede}->id,
            'user_id' => $this->teams[$sede]->user_id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
        ])->fresh();

        $run->update(['contest_time' => $minuto * 60]);

        app(ContestEventRecorder::class)->submissionCreated($run->fresh());

        return $run->fresh();
    }

    private function julga(Run $run): Run
    {
        $run->update([
            'status' => 'judged',
            'answer_id' => $this->yes->id,
            'judged_time' => $run->contest_time,
        ]);

        Score::updateScore($run->fresh());
        app(ContestEventRecorder::class)->judgementRecorded($run->fresh());

        return $run->fresh();
    }

    /**
     * Os ids de julgamento que o feed ANONIMO entrega.
     *
     * @return list<string>
     */
    private function julgamentosDoFeed(): array
    {
        $corpo = $this->get("/api/clics/contests/{$this->contest->id}/event-feed")
            ->assertStatus(200)->streamedContent();

        $ids = [];

        foreach (explode("\n", trim($corpo)) as $linha) {
            if ($linha === '') {
                continue;
            }

            $evento = json_decode($linha, true);

            if (($evento['type'] ?? null) === 'judgements') {
                $ids[] = (string) $evento['id'];
            }
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function judgements(?User $as = null): array
    {
        if ($as !== null) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($as);
        }

        return $this->getJson("/api/clics/contests/{$this->contest->id}/judgements")
            ->assertStatus(200)->json();
    }

    private function staff(): User
    {
        return $this->createTestUser(['user_type' => 'judge', 'contest_id' => $this->contest->id]);
    }
}
