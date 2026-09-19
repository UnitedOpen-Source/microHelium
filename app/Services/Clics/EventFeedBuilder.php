<?php

namespace App\Services\Clics;

use App\Models\Contest;
use App\Models\ContestEvent;
use App\Services\ScoreboardTeams;

/**
 * Issue #219 -- monta as linhas NDJSON do event feed.
 *
 * Separado do controller porque a montagem e testavel sem uma conexao HTTP
 * aberta, e um feed e justamente o tipo de coisa que ninguem testa se testar
 * exigir manter conexao. Quem mantem a conexao e EventFeedStream (#328).
 */
class EventFeedBuilder
{
    /**
     * Os tipos que a spec trata como SINGLETON, e que por isso saem com
     * `id: null`.
     *
     * Contest API 2023-06, "Notification format": «id -- ID ? -- The id of
     * the object that changed, or null for the entire collection/singleton»
     * e «If `type` is `contest`, then `id` must be null». A lista de
     * singletons esta em "Types of endpoints": «Note that `api`, `access`,
     * `account`, `state`, `scoreboard`, and `event-feed` are singular nouns
     * and indeed contain only a single object».
     *
     * Issue #330 -- emitiamos o id do contest nos dois, e o schema oficial
     * (`common.json#/identifierornull` com a regra acima) rejeita.
     */
    private const SINGLETONS = ['contest', 'state'];

    public function __construct(private ClicsPresenter $presenter) {}

    /**
     * A FOTOGRAFIA que abre o feed.
     *
     * Os objetos estaticos -- contest, tipos de veredito, linguagens,
     * grupos, organizacoes, equipes, problemas -- nao tem log de mudancas, e
     * nao precisam: eles sao emitidos a partir do estado atual quando o feed
     * comeca, que e como os feeds de CLICS funcionam. Guardar um log para
     * dados que nao mudam no meio de uma prova seria pagar o preco caro pelo
     * caso que nao acontece.
     *
     * Consequencia que precisa ficar dita: com `since_token`, a fotografia
     * NAO e repetida. Um cliente que volta ja tem os objetos estaticos; o
     * que ele nao tem sao os eventos depois do token.
     *
     * @return list<array<string, mixed>>
     */
    public function snapshot(Contest $contest): array
    {
        $teams = ScoreboardTeams::forContest($contest);

        $lines = [];

        // `contest` no SINGULAR, e nao `contests` (#330): o enum de
        // common.json#/endpointssingularcontest -- o mesmo que o schema do
        // event feed referencia para `type` -- tem `contest`. O ICPC Tools
        // tolera o plural por compatibilidade; o schema oficial nao.
        $lines[] = $this->line('contest', (string) $contest->id, $this->presenter->contest($contest));

        foreach ($this->presenter->judgementTypes($contest) as $type) {
            $lines[] = $this->line('judgement-types', (string) $type['id'], $type);
        }

        foreach ($this->presenter->languages($contest) as $language) {
            $lines[] = $this->line('languages', (string) $language['id'], $language);
        }

        foreach ($this->presenter->groups($contest) as $group) {
            $lines[] = $this->line('groups', (string) $group['id'], $group);
        }

        foreach ($this->presenter->organizations($teams) as $organization) {
            $lines[] = $this->line('organizations', (string) $organization['id'], $organization);
        }

        foreach ($this->presenter->teams($teams) as $team) {
            $lines[] = $this->line('teams', (string) $team['id'], $team);
        }

        foreach ($this->presenter->problems($contest) as $problem) {
            $lines[] = $this->line('problems', (string) $problem['id'], $problem);
        }

        $lines[] = $this->line('state', (string) $contest->id, $this->presenter->state($contest));

        return $lines;
    }

    /**
     * Um LOTE do log, para uma conexao que continua aberta (#328).
     *
     * Devolve tres coisas porque a conexao aberta precisa das tres:
     *
     * - `linhas`: o que emitir agora;
     * - `cursor`: ate onde o log foi LIDO -- e nao ate onde foi emitido. A
     *   diferenca e o que impede a conexao de reler para sempre um evento
     *   que ela nao pode mostrar;
     * - `retidos`: os ids que ficaram de fora pelo congelamento. Quem esta
     *   conectado quando a prova descongela tem de receber aqueles eventos
     *   SEM reconectar, e depois que o cursor passou por cima deles a unica
     *   forma de reencontra-los e esta lista.
     *
     * `$unrestricted` e "esta pessoa pode ver o que o congelamento esconde".
     *
     * @return array{linhas: list<array<string, mixed>>, cursor: int|null, retidos: list<int>}
     */
    public function batch(Contest $contest, ?int $depois, bool $unrestricted): array
    {
        $events = ContestEvent::where('contest_id', $contest->id)
            ->when($depois !== null, fn ($query) => $query->where('id', '>', $depois))
            ->orderBy('id')
            ->get();

        $linhas = [];
        $retidos = [];
        $cursor = $depois;

        foreach ($events as $event) {
            $cursor = (int) $event->id;

            if (! $unrestricted && $event->after_freeze) {
                $retidos[] = (int) $event->id;

                continue;
            }

            $linhas[] = $this->line($event->type, $event->object_id, $event->payload, (string) $event->id);
        }

        return ['linhas' => $linhas, 'cursor' => $cursor, 'retidos' => $retidos];
    }

    /**
     * Os eventos que o congelamento reteve, agora que ele acabou.
     *
     * Saem na ordem do token, que e a ordem em que aconteceram.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function releases(Contest $contest, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ContestEvent::where('contest_id', $contest->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->map(fn (ContestEvent $event) => $this->line(
                $event->type,
                $event->object_id,
                $event->payload,
                (string) $event->id
            ))->all();
    }

    /**
     * O ultimo token que este observador ja viu.
     *
     * Usado para dizer ao cliente onde ele parou. Conta so os eventos que
     * ELE pode ver: entregar um token de um evento escondido faria o cliente
     * pedir "depois de N" e nunca receber o que estava em N.
     */
    public function latestToken(Contest $contest, bool $unrestricted): ?string
    {
        $thawed = $contest->unfrozen_at !== null;

        $id = ContestEvent::where('contest_id', $contest->id)
            ->visibleTo($unrestricted || $thawed)
            ->max('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * O maior token que ESTE contest ja emitiu, sem filtro de visibilidade.
     *
     * Serve a uma pergunta so: o `since_token` que chegou existe? (#330). A
     * spec manda responder 400 a token invalido, e o corte tem de ser o log
     * inteiro -- usar o filtro do congelamento aqui faria um cliente da
     * banca receber 400 por um token que ele mesmo acabou de receber.
     */
    public function highestToken(Contest $contest): int
    {
        return (int) (ContestEvent::where('contest_id', $contest->id)->max('id') ?? 0);
    }

    /**
     * A linha que fecha o feed quando a prova foi finalizada (#202).
     *
     * A spec usa isto para dizer "nao vem mais nada", e so depois de
     * finalizar isso e verdade -- e finalizar e uma checagem de integridade
     * da prova inteira, nao um botao.
     *
     * @return array<string, mixed>|null
     */
    public function endOfUpdates(Contest $contest): ?array
    {
        if (! $contest->isFinalized()) {
            return null;
        }

        return $this->line('state', (string) $contest->id, $this->presenter->state($contest));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function line(string $type, ?string $id, array $data, ?string $token = null): array
    {
        $line = [
            'type' => $type,
            // Issue #330 -- singleton sai com id null, e a normalizacao mora
            // AQUI de proposito: o log grava o id do contest (que e o que
            // identifica a linha no banco), e quem fala a spec e o feed.
            'id' => in_array($type, self::SINGLETONS, true) ? null : $id,
            // Sem `op` (#330). A propriedade existiu ate 2020-03 e foi
            // removida; `event-feed.json` de 2023-06 lista exatamente
            // `type`, `id`, `data` e `token`. E nao era decorativa: o
            // NDJSONFeedParser do ICPC Tools BIFURCA pela presenca de `op`
            // -- com ele, cai no parseOldFormat, que nunca le `token`, e a
            // retomada por since_token vira codigo morto.
            'data' => $data,
        ];

        // A fotografia sai SEM token, de proposito.
        //
        // Um token e uma posicao no log, e a fotografia nao esta no log --
        // ela e reconstruida do estado atual. Dar a ela um token inventado
        // faria um cliente pedir "depois desse" e pular eventos reais que
        // ficaram abaixo dele.
        if ($token !== null) {
            $line['token'] = $token;
        }

        return $line;
    }
}
