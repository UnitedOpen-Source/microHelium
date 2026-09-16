<?php

namespace App\Services\Clics;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Leaderboard;
use App\Models\Organization;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Services\ContestAwards;
use App\Services\FrozenScoreboard;
use Helium\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Issue #195 -- traduz os objetos daqui para o vocabulario da Contest API da
 * ICPC (https://ccs-specs.icpc.io/2026-01/contest_api).
 *
 * Fase 1: so leitura, sem event feed. A propria spec diz onde esta o piso --
 * "The only required endpoints are metadata: `api` and `access`. [...] All
 * other endpoints and properties are optional".
 *
 * Isso tem uma consequencia que precisa ficar escrita e nao entrelinhada:
 * ESTA FASE NAO HABILITA O RESOLVER. O ICPC Tools e explicito -- "the only
 * part of the Contest API that is strictly required is the event feed and
 * any file references that the feed refers to" -- e o event feed e a fase 2,
 * que exige um log de mudancas por objeto que nao existe hoje. O que a fase
 * 1 habilita e o que fala REST: shadow, analisadores, e o Contest Data
 * Server ingerindo os endpoints.
 *
 * Um TRADUTOR, e nao um segundo modelo de dados: tudo aqui deriva do que ja
 * existe. Um `judgements` calculado por caminho proprio poderia discordar do
 * placar da casa, e duas verdades sobre o mesmo veredito e pior do que uma
 * incompleta.
 */
class ClicsPresenter
{
    public function __construct(private ContestAwards $awards) {}

    /**
     * BOCA/microHelium -> Contest API.
     *
     * A spec fixa os IDs e o BOCA usa nomes curtos proprios para as mesmas
     * coisas. Sem esta tabela um consumidor receberia "NO" onde espera "WA"
     * e trataria como veredito desconhecido.
     *
     * `CS` e o veredito que a propria infraestrutura produz ao desistir
     * (#45), e o equivalente padrao e `JE` -- que e exatamente o que o #202
     * exige que nao exista para poder finalizar.
     */
    private const CLICS_VERDICTS = [
        'YES' => 'AC',
        'AC' => 'AC',
        'NO' => 'WA',
        'WA' => 'WA',
        'TLE' => 'TLE',
        'RTE' => 'RTE',
        'RE' => 'RTE',
        'CE' => 'CE',
        'MLE' => 'MLE',
        'OLE' => 'OLE',
        'PE' => 'WA',
        'CS' => 'JE',
        'JE' => 'JE',
        'SV' => 'SV',
    ];

    public static function verdictId(?string $shortName): ?string
    {
        if ($shortName === null || $shortName === '') {
            return null;
        }

        return self::CLICS_VERDICTS[strtoupper($shortName)] ?? strtoupper($shortName);
    }

    /**
     * @return array<string, mixed>
     */
    public function contest(Contest $contest): array
    {
        return [
            'id' => (string) $contest->id,
            'name' => $contest->name,
            'formal_name' => $contest->name,
            'start_time' => $contest->start_time?->toIso8601String(),
            // Duracoes em RELTIME (HH:MM:SS.mmm), o formato da spec -- nao
            // segundos, e nao duracao ISO 8601.
            'duration' => $this->relTime((int) $contest->duration * 60),
            'scoreboard_freeze_duration' => $this->relTime($this->freezeMinutes($contest) * 60),
            'penalty_time' => (int) $contest->penalty,
        ];
    }

    /**
     * O estado da prova, no vocabulario da spec.
     *
     * Depende inteiramente do #189 e do #202: antes deles nao havia `thawed`
     * nem `finalized` para publicar, e o congelamento nem sobrevivia ao fim
     * da prova. Publicar `/state` antes daquilo seria publicar um estado
     * errado, que e pior do que nao publicar nenhum.
     *
     * @return array<string, mixed>
     */
    public function state(Contest $contest): array
    {
        $started = $contest->start_time !== null && now()->gte($contest->start_time);
        $ended = $contest->end_time !== null && now()->gt($contest->end_time);

        return [
            'started' => $started ? $contest->start_time?->toIso8601String() : null,
            'ended' => $ended ? $contest->end_time?->toIso8601String() : null,
            // `frozen` e o INSTANTE em que congelou, e null quando nunca
            // congelou -- nao um booleano. A spec e explicita, e um booleano
            // aqui e o erro que o consumidor nao detecta: ele le "truthy" e
            // segue em frente.
            'frozen' => $this->frozenAt($contest),
            'thawed' => $contest->unfrozen_at?->toIso8601String(),
            'finalized' => $contest->finalized_at?->toIso8601String(),
            // A spec usa este campo para dizer "nao vem mais nada". Enquanto
            // nao ha event feed, finalizar e o unico momento em que isso e
            // verdade.
            'end_of_updates' => $contest->finalized_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function problems(Contest $contest): array
    {
        return $contest->problems()
            ->where('is_fake', false)
            ->get()
            ->values()
            ->map(fn (Problem $problem, int $index) => [
                'id' => (string) $problem->id,
                'label' => $problem->short_name,
                'name' => $problem->name,
                'ordinal' => $index,
                'rgb' => $problem->color_hex,
                'color' => $problem->color_name,
            ])->all();
    }

    /**
     * @param  Collection<int, User>  $teams
     * @return list<array<string, mixed>>
     */
    public function teams(Collection $teams): array
    {
        return $teams->values()->map(fn (User $team) => [
            'id' => (string) $team->user_id,
            'name' => $team->fullname ?? $team->username,
            'display_name' => $team->fullname ?? $team->username,
            // A sede e o grupo: na Contest API "group" e a subdivisao da
            // prova, que e exatamente o que `sites` significa aqui.
            'group_ids' => $team->site_id ? [(string) $team->site_id] : [],
            'organization_id' => $this->organizationIdFor($team),
        ])->all();
    }

    /**
     * As organizacoes das equipes desta prova.
     *
     * Nao existe `users.organization_id`: o vinculo e por
     * OrganizationMembership (#46). Uma equipe sem vinculo sai com
     * `organization_id: null`, que a spec permite -- e o #188 e justamente
     * sobre esse vinculo ser hoje dificil de criar.
     *
     * @param  Collection<int, User>  $teams
     * @return list<array<string, mixed>>
     */
    public function organizations(Collection $teams): array
    {
        $ids = $teams->map(fn (User $team) => $this->organizationIdFor($team))->filter()->unique();

        return Organization::whereIn('id', $ids)->get()->map(fn (Organization $organization) => [
            'id' => (string) $organization->id,
            'name' => $organization->name,
            'formal_name' => $organization->name,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function groups(Contest $contest): array
    {
        return $contest->sites()->get()->map(fn (Site $site) => [
            'id' => (string) $site->id,
            'icpc_id' => null,
            'name' => $site->name,
            'type' => 'site',
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function languages(Contest $contest): array
    {
        return $contest->languages()->get()->map(fn ($language) => [
            'id' => (string) $language->id,
            'name' => $language->name,
            'extensions' => array_values(array_filter([$language->extension])),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function judgementTypes(Contest $contest): array
    {
        return Answer::where('contest_id', $contest->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Answer $answer) => [
                'id' => self::verdictId($answer->short_name),
                'name' => $answer->name,
                'penalty' => ! $answer->is_accepted,
                'solved' => (bool) $answer->is_accepted,
            ])
            ->unique('id')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Run>  $runs
     * @return list<array<string, mixed>>
     */
    public function submissions(Contest $contest, Collection $runs): array
    {
        return $runs->values()->map(fn (Run $run) => [
            'id' => (string) $run->id,
            'language_id' => (string) $run->language_id,
            'problem_id' => (string) $run->problem_id,
            'team_id' => (string) $run->user_id,
            'time' => $contest->start_time?->copy()->addSeconds((int) $run->contest_time)->toIso8601String(),
            'contest_time' => $this->relTime((int) $run->contest_time),
        ])->all();
    }

    /**
     * @param  Collection<int, Run>  $runs
     * @return list<array<string, mixed>>
     */
    public function judgements(Contest $contest, Collection $runs): array
    {
        return $runs->values()->map(fn (Run $run) => [
            // Um julgamento por envio, e o id e o do proprio envio: aqui o
            // veredito mora no run e nao ha historico de julgamentos
            // anteriores para numerar. Inventar um segundo espaco de ids
            // sugeriria um historico que nao existe.
            'id' => (string) $run->id,
            'submission_id' => (string) $run->id,
            'judgement_type_id' => self::verdictId($run->answer?->short_name),
            'start_contest_time' => $this->relTime((int) $run->contest_time),
            'end_contest_time' => $this->relTime((int) ($run->judged_time ?? $run->contest_time)),
            'end_time' => $contest->start_time?->copy()->addSeconds((int) ($run->judged_time ?? 0))->toIso8601String(),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function scoreboard(Contest $contest, bool $frozen): array
    {
        $rows = Leaderboard::getScoreboard($contest->id, $frozen);

        return [
            'time' => now()->toIso8601String(),
            'contest_time' => $this->relTime($contest->getContestTime()),
            'state' => $this->state($contest),
            'rows' => array_map(fn (array $row) => [
                'rank' => $row['rank'],
                'team_id' => (string) $row['user']->user_id,
                'score' => [
                    'num_solved' => $row['problems_solved'],
                    'total_time' => $row['total_time'],
                ],
                'problems' => collect($row['problems'])->map(fn (array $cell) => [
                    'problem_id' => (string) $cell['problem_id'],
                    'num_judged' => $cell['attempts'],
                    // O #211 pos este numero na celula justamente porque a
                    // ICPC mostra as tentativas do congelamento como
                    // pendentes. Aqui ele e o campo que a spec pede.
                    'num_pending' => $cell['pending'] ?? 0,
                    'solved' => $cell['is_solved'],
                    'time' => $cell['is_solved'] ? $cell['solved_time'] : null,
                    'first_to_solve' => $cell['is_first_solver'],
                ])->values()->all(),
            ], $rows),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function awards(Contest $contest): array
    {
        return $this->awards->forContest($contest)['awards'];
    }

    private function organizationIdFor(User $team): ?string
    {
        $membership = OrganizationMembershipLookup::for($team);

        return $membership !== null ? (string) $membership : null;
    }

    private function freezeMinutes(Contest $contest): int
    {
        return (int) ($contest->getAttributes()['freeze_time'] ?? 0);
    }

    /**
     * O instante em que o placar congelou, ou null se ainda nao congelou.
     */
    private function frozenAt(Contest $contest): ?string
    {
        if ($this->freezeMinutes($contest) <= 0 || ! $contest->start_time) {
            return null;
        }

        $moment = $contest->start_time->copy()->addSeconds(FrozenScoreboard::cutoffSeconds($contest));

        return now()->gte($moment) ? Carbon::instance($moment)->toIso8601String() : null;
    }

    /**
     * O RELTIME da spec: HH:MM:SS.mmm, com sinal quando negativo.
     */
    private function relTime(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '';
        $seconds = abs($seconds);

        return sprintf('%s%d:%02d:%02d.000', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
