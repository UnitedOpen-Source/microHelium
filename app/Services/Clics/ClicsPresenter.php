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
use App\Services\ContestClock;
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
            // Issue #332 -- `contest.json` de 2023-06 tem
            // "required": ["id","name","duration","scoreboard_type"], e o
            // enum e ["pass-fail","score"]. O nosso modelo de placar e
            // pass-fail: um problema esta resolvido ou nao, e o desempate e
            // por tempo com penalidade -- que e exatamente o ramo do schema
            // que EXIGE `penalty_time` junto (allOf/if/then).
            'scoreboard_type' => 'pass-fail',
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
            // Issue #332 -- `test_data_count` e obrigatorio em
            // `problem.json`, e a spec o define como "Number of test data
            // sets". `withCount` e nao `->testCases()->count()` dentro do
            // map: um problema por consulta transformaria a fotografia do
            // event feed num N+1 no momento em que o resolver conecta.
            ->withCount('testCases')
            // Issue #271 -- a ordem tem um nome, e mora em
            // Problem::scopeInContestOrder(). Ver o docblock de la para por
            // que `orderBy('sort_order')` sozinho nao bastava.
            ->inContestOrder()
            ->get()
            ->values()
            ->map(function (Problem $problem, int $index) {
                $objeto = [
                    'id' => (string) $problem->id,
                    'label' => $problem->short_name,
                    'name' => $problem->name,
                    'ordinal' => $index,
                    'test_data_count' => (int) $problem->test_cases_count,
                ];

                // Issue #332 -- OMITIR, e nao emitir null.
                //
                // `problem.json` tipa `rgb` e `color` como `string` sem
                // `null`, e a spec e explicita nos dois lados: "Must only
                // have null values if the type of the property is <type> ?"
                // (Table column description) e "a property with value null
                // may be left out by the server" (Extensibility). Um
                // problema sem cor cadastrada nao tem cor -- e isso se diz
                // nao dizendo, nao dizendo "null".
                if ($problem->color_hex !== null && $problem->color_hex !== '') {
                    $objeto['rgb'] = $problem->color_hex;
                }

                if ($problem->color_name !== null && $problem->color_name !== '') {
                    $objeto['color'] = $problem->color_name;
                }

                return $objeto;
            })->all();
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
            // Issue #332 -- obrigatorio em `team.json`
            // ("required": ["id","name","label"]).
            'label' => $this->teamLabel($team),
            'display_name' => $team->fullname ?? $team->username,
            // A sede e o grupo: na Contest API "group" e a subdivisao da
            // prova, que e exatamente o que `sites` significa aqui.
            'group_ids' => $team->site_id ? [(string) $team->site_id] : [],
            'organization_id' => $this->organizationIdFor($team),
            // Issue #270 -- `users.icpc_id` existe desde o #89 e a Contest
            // API define `icpc_id` no recurso de equipe, mas este metodo
            // nao o emitia. Sem ele o consumidor nao consegue casar a
            // equipe com o cadastro nacional, que e o ponto inteiro de o
            // campo existir.
            'icpc_id' => $team->icpc_id !== null && $team->icpc_id !== ''
                ? (string) $team->icpc_id
                : null,
        ])->all();
    }

    /**
     * As organizacoes das equipes desta prova.
     *
     * Issue #270 -- o vinculo mora em `users.organization_id`, e nao mais na
     * governanca do banco de problemas. Uma equipe sem vinculo sai com
     * `organization_id: null`, que a spec permite.
     *
     * Ordenado por id de proposito: esta lista alimenta o pacote de
     * resultados (#271), que exige saida identica byte a byte em duas
     * exportacoes do mesmo contest. Ordem de iteracao nao deterministica e
     * a primeira coisa que quebra essa propriedade.
     *
     * @param  Collection<int, User>  $teams
     * @return list<array<string, mixed>>
     */
    public function organizations(Collection $teams): array
    {
        $ids = $teams->map(fn (User $team) => $this->organizationIdFor($team))->filter()->unique();

        return Organization::whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->map(fn (Organization $organization) => [
                'id' => (string) $organization->id,
                'icpc_id' => $organization->icpc_id !== null && $organization->icpc_id !== ''
                    ? (string) $organization->icpc_id
                    : null,
                'name' => $organization->name,
                // Nulo quer dizer "use o `name`", que e o que este metodo
                // fazia por nao ter onde ler. Assim uma instalacao existente
                // continua respondendo exatamente o mesmo sem preencher
                // nada.
                'formal_name' => $organization->formal_name ?: $organization->name,
                'country' => $organization->country ?: null,
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
            // Issue #333 -- o identificador CLICS, e nao o autoincremento.
            //
            // "IDs are assigned by the person or system that is the source
            // of the object, and must be maintained by downstream systems"
            // (JSON property types). `languages.id` e `contest_id`-scoped:
            // "3" era Java numa prova e Rust na seguinte, e nenhum consumidor
            // conseguia comparar duas provas. Ver ClicsLanguageIdentifiers.
            'id' => ClicsLanguageIdentifiers::para($language->extension),
            'name' => $language->name,
            // Issue #332/#333 -- obrigatorio em `language.json`. Hoje
            // nenhuma linguagem do catalogo exige ponto de entrada
            // declarado (o {classname} do Java sai do proprio fonte), entao
            // `false` explicito e a verdade -- e o schema so pede
            // `entry_point_name` no ramo `true`.
            'entry_point_required' => false,
            // Issue #333 -- a EXTENSAO do arquivo, e nao o slug interno.
            //
            // `languages.extension` e a chave interna ("cpp_gpp13"), e o
            // proprio modelo tem getFileExtension() so por causa dessa
            // confusao. Das 50 linguagens ativas do catalogo, 22 emitiam uma
            // extensao que nao existe.
            'extensions' => array_values(array_filter([$language->getFileExtension()])),
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
            // Issue #333 -- o MESMO identificador que /languages emite.
            //
            // Trocar o id la e nao aqui seria pior que o defeito: o
            // consumidor casa `submissions.language_id` com `languages.id`,
            // e duas numeracoes diferentes deixariam todo envio apontando
            // para uma linguagem que nao existe.
            'language_id' => $run->language !== null
                ? ClicsLanguageIdentifiers::para($run->language->extension)
                : (string) $run->language_id,
            'problem_id' => (string) $run->problem_id,
            'team_id' => (string) $run->user_id,
            'time' => $contest->start_time?->copy()->addSeconds((int) $run->contest_time)->toIso8601String(),
            'contest_time' => $this->relTime((int) $run->contest_time),
            // Issue #332 -- obrigatorio em `submission.json`
            // (`common.json#/filerefs`, sem minItems).
            //
            // Vazio e a resposta HONESTA: a Contest API do microHelium e
            // anonima, e publicar o fonte por href nela seria publicar o
            // codigo de todas as equipes durante a prova. O que falta para
            // preencher isto nao e traducao, e uma rota autenticada que
            // sirva o zip -- decisao de produto, registrada na #332.
            'files' => [],
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
            // Issue #332 -- obrigatorio em `judgement.json`
            // ("required": ["id","submission_id","start_time",
            // "start_contest_time"]), e e a mesma conta que o `end_time`
            // duas linhas abaixo ja fazia. O resolver ordena e anima os
            // julgamentos por tempo ABSOLUTO; sem `start_time` o objeto e
            // rejeitado por qualquer validador.
            'start_time' => $contest->start_time?->copy()->addSeconds((int) $run->contest_time)->toIso8601String(),
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

    /**
     * Issue #332 -- o rotulo que vai ao telao.
     *
     * A spec: "Label of the team, at WFs normally the team seat number"
     * (Contest API 2023-06, Teams). E obrigatorio, entao a pergunta nao e
     * "emitir ou nao", e "o que emitir quando ninguem cadastrou".
     *
     * `users.label` e a resposta cadastrada -- o numero do crachá, que a
     * banca digita na tela de edicao da equipe. Quando esta vazio, o padrao
     * e o `user_id`.
     *
     * O padrao NAO finge ser um numero de assento, e essa e a escolha:
     * emitir o id e dizer a verdade ("nao ha rotulo cadastrado, aqui esta o
     * unico identificador estavel que existe"), e e exatamente o que o
     * consumidor ja faz hoje quando o campo falta -- a #332 descreve o
     * sintoma: "Sem ele o resolver mostra o `id` interno do nosso banco".
     * Com isto ele passa a mostrar a mesma coisa, mas o campo existe, o
     * objeto valida, e ha onde a banca por o numero certo.
     *
     * A alternativa DESCARTADA foi derivar um ordinal bonitinho (1..N por
     * ordem de inscricao). Ele muda quando uma equipe e removida: o rotulo
     * de todas as seguintes anda um, e ninguem percebe -- num telao de
     * cerimonia o locutor chama a equipe errada. Um rotulo que vai a
     * cerimonia nao pode ser derivado de uma contagem que se mexe.
     */
    private function teamLabel(User $team): string
    {
        $label = trim((string) ($team->label ?? ''));

        return $label !== '' ? $label : (string) $team->user_id;
    }

    private function organizationIdFor(User $team): ?string
    {
        // Issue #270 -- a afiliacao propria, e nao a governanca do banco.
        $affiliation = TeamAffiliation::for($team);

        return $affiliation !== null ? (string) $affiliation : null;
    }

    private function freezeMinutes(Contest $contest): int
    {
        return (int) ($contest->getAttributes()['freeze_time'] ?? 0);
    }

    /**
     * O instante em que o placar congelou, ou null se ainda nao congelou.
     *
     * ## Issue #319 -- o PRIMEIRO congelamento, e nao o do contest
     *
     * `state.frozen` e "Time when the scoreboard was frozen" (Contest API
     * 2023-06, secao "Contest state"), e e por CONTEST na spec: o objeto
     * `state` e singleton, nao ha um por sede. Com janelas por sede (#276)
     * nao existe instante unico "o congelamento", e a pergunta vira qual dos
     * instantes publicar.
     *
     * Era o corte do CONTEST, e isso fazia esta API se contradizer. Quem
     * decide se o visitante anonimo recebe a versao congelada e
     * `frozenFor()`, que cai em `ContestClock::isFrozenForAnyone()` -- ou
     * seja, a partir do congelamento da PRIMEIRA sede o /judgements ja
     * filtra e o /scoreboard ja mostra celulas pendentes. Numa prova de
     * 300/60 com uma sede de 240/60, isso comeca no minuto 180 e o corte do
     * contest so chega no 240: por uma hora o consumidor recebia dado
     * escondido com `frozen: null` ao lado, isto e, "nada esta congelado".
     *
     * Publicar o primeiro instante nao e escolher uma sede: e dizer quando
     * ESTE placar -- o unico que esta API serve -- passou a esconder. E
     * continua sendo um instante unico por contest, que e o que a spec pede.
     *
     * Conservador na mesma direcao do #276 ("conservador e a escolha certa
     * aqui porque o congelamento existe para esconder"): declarar o
     * congelamento cedo demais nunca revela nada; declarar tarde demais
     * autoriza o consumidor a tratar como definitivo um quadro que ja esta
     * incompleto.
     */
    private function frozenAt(Contest $contest): ?string
    {
        if (! $contest->start_time) {
            return null;
        }

        $primeiro = $this->firstFreezeStart($contest);

        return $primeiro !== null && now()->gte($primeiro)
            ? Carbon::instance($primeiro)->toIso8601String()
            : null;
    }

    /**
     * O mais cedo dos inicios de congelamento das sedes desta prova.
     *
     * Null quando nenhuma sede congela -- que e diferente de "ainda nao
     * congelou", e e por isso que `freezeStartFor()` ja devolve null nesse
     * caso em vez de um instante impossivel.
     *
     * Sem sede nenhuma cai no calculo do proprio contest, exatamente como
     * `ContestClock::isFrozenForAnyone()` faz -- as duas respondem sobre o
     * mesmo conjunto, e uma discordancia entre elas seria o defeito de novo.
     */
    private function firstFreezeStart(Contest $contest): ?Carbon
    {
        $clock = app(ContestClock::class);
        $sites = $contest->sites()->get();

        $ids = $sites->isEmpty() ? [null] : $sites->map(fn (Site $site) => (int) $site->id)->all();

        $primeiro = null;

        foreach ($ids as $id) {
            $inicio = $clock->freezeStartFor($contest, $id);

            if ($inicio !== null && ($primeiro === null || $inicio->lt($primeiro))) {
                $primeiro = $inicio;
            }
        }

        return $primeiro;
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
