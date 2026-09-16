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
 * exigir manter conexao.
 */
class EventFeedBuilder
{
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
        $lines[] = $this->line('contests', (string) $contest->id, $this->presenter->contest($contest));

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
     * Os eventos registrados depois de `$sinceToken`.
     *
     * `$unrestricted` e "esta pessoa pode ver o que o congelamento esconde".
     * Para quem nao pode, os julgamentos da janela ficam de fora ATE o
     * descongelamento -- e ai saem na ordem do token, que e a ordem em que
     * aconteceram. E a parte que o REST nao precisava resolver: la basta
     * filtrar uma lista, aqui um evento omitido some para sempre.
     *
     * @return list<array<string, mixed>>
     */
    public function since(Contest $contest, ?int $sinceToken, bool $unrestricted): array
    {
        $thawed = $contest->unfrozen_at !== null;

        $events = ContestEvent::where('contest_id', $contest->id)
            ->when($sinceToken !== null, fn ($query) => $query->where('id', '>', $sinceToken))
            ->visibleTo($unrestricted || $thawed)
            ->orderBy('id')
            ->get();

        return $events->map(fn (ContestEvent $event) => $this->line(
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

        return [
            'type' => 'state',
            'id' => (string) $contest->id,
            'op' => 'update',
            'data' => $this->presenter->state($contest),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function line(string $type, string $id, array $data, ?string $token = null): array
    {
        $line = [
            'type' => $type,
            'id' => $id,
            'op' => 'create',
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
