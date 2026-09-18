<?php

namespace App\Models;

use App\Services\ContestClock;
use Helium\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Contest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        // Issue #271 -- identidade do evento para um agregado nacional. O
        // `uuid` NAO entra aqui de proposito: ele e gerado uma vez no
        // `creating` e nao deve ser reescrito por atribuicao em massa, senao
        // a propriedade que ele existe para ter -- "este pacote e uma nova
        // exportacao daquela prova" -- some.
        'edition',
        'phase',
        'start_time',
        'duration',
        'freeze_time',
        'unfrozen_at',
        'penalty',
        'max_file_size',
        'is_active',
        'is_public',
        'is_practice',
        'verification_required',
        'unlock_key',
        'finalized_at',
        'finalized_by',
        'rank_median_cut',
        'medal_gold',
        'medal_silver',
        'medal_bronze',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'unfrozen_at' => 'datetime',
        'finalized_at' => 'datetime',
        'rank_median_cut' => 'boolean',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'is_practice' => 'boolean',
        // Issue #138 -- DOMjudge's "Is manual verification of judgings by
        // jury required before publication?", per contest rather than per
        // installation (see the migration for why).
        'verification_required' => 'boolean',
    ];

    /**
     * Issue #271 -- o `uuid` nasce com a prova, e uma vez so.
     *
     * `contests.id` e auto-incremento local: a prova 12 de uma instalacao e
     * a prova 12 de outra colidem, e um site nacional recebendo trinta
     * pacotes nao distinguiria uma da outra. O `uuid` e o que permite dizer
     * "este pacote e uma nova exportacao daquela prova" em vez de "este e
     * outro evento" -- e por isso ele e imutavel, e fica fora do
     * `$fillable`.
     */
    protected static function booted(): void
    {
        static::creating(function (self $contest) {
            $contest->uuid ??= (string) \Illuminate\Support\Str::uuid();
        });
    }

    /**
     * Issue #43 -- everything that means "a competition" must exclude the
     * technical practice contest: active-contest selection, the public
     * selector, the clock, global activation, event CSV, tasks/balloons and
     * event ranking (docs/specs/43-practice.md).
     *
     * Deliberately a scope rather than a global scope: a global one would
     * also apply to $run->contest, and AutoJudgeService needs that
     * relationship to resolve for practice runs too.
     */
    public function scopeCompetition($query)
    {
        return $query->where('is_practice', false);
    }

    public function scopePractice($query)
    {
        return $query->where('is_practice', true);
    }

    /**
     * Issue #134 -- may this viewer see this contest at all?
     *
     * The same rule issue #135 landed for the web side in
     * App\Http\Controllers\ProblemController::mayList(), in the same order:
     * staff always; then someone who belongs to this contest; then everyone,
     * but only when the contest is marked public. It lives on the model
     * because the API asks it of four different controllers (contest,
     * problem, scoreboard, clarification) -- one rule with one home, so the
     * listing and the detail endpoint cannot drift apart the way #135 found
     * them drifted on the web side.
     *
     * The gate matters most before an event opens: is_public defaults to
     * false, and a contest that has not started still has its whole problem
     * set loaded.
     */
    public function isVisibleTo(?User $user): bool
    {
        if ($user?->isAdmin() || $user?->isJudge()) {
            return true;
        }

        if (in_array((int) $this->id, self::memberContestIds($user), true)) {
            return true;
        }

        return (bool) $this->is_public;
    }

    /**
     * The row-set half of isVisibleTo(), for the listings.
     *
     * Kept beside it on purpose: a listing that filters by a different rule
     * than the one the detail endpoint enforces is exactly the bug #135
     * describes, where /exercises listed problems whose own page answered
     * 404.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if ($user?->isAdmin() || $user?->isJudge()) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->where('is_public', true)
                ->orWhereIn('id', self::memberContestIds($user));
        });
    }

    /**
     * Which contests this user belongs to.
     *
     * Broader than #135's mayList(), which asks about users.contest_id
     * alone, and deliberately so: in the BOCA schema a user reaches a
     * contest through either column, and the API's own writers already treat
     * the site as authoritative when the direct link is absent
     * (`$user->site_id ?? $contest->sites()->first()->id` in
     * Api\RunController::store() and Api\ClarificationController::store()).
     * Reading users.contest_id only would lock a legitimately registered
     * team out of its own contest whenever registration filled in the site.
     *
     * @return list<int>
     */
    private static function memberContestIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return array_values(array_unique(array_filter([
            (int) $user->contest_id,
            (int) $user->site?->contest_id,
        ])));
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** @return HasMany<Language, $this> */
    public function languages(): HasMany
    {
        return $this->hasMany(Language::class);
    }

    /** @return HasMany<Answer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    /** @return HasMany<Problem, $this> */
    public function problems(): HasMany
    {
        return $this->hasMany(Problem::class)->orderBy('sort_order');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Run, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /** @return HasMany<Clarification, $this> */
    public function clarifications(): HasMany
    {
        return $this->hasMany(Clarification::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<ContestLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(ContestLog::class);
    }

    /**
     * Carbon and not \DateTime: `start_time` has a `datetime` cast, so what
     * comes back is a Carbon, and this method's own body calls ->copy(),
     * which \DateTime does not have -- the declared type promised less than
     * the code relied on, and PHPStan is what noticed. Narrowing is safe
     * for every caller, because Carbon IS a \DateTime.
     *
     * The Carbon::instance() wrapper is not decoration and not a cast to
     * silence anything. `Illuminate\Support\Carbon` is genuinely what comes
     * out of here -- verified by running it, get_class() says so -- but
     * Carbon's own stubs type addMinutes() as returning the parent
     * `Carbon\Carbon`, so at level 3 PHPStan can only prove the parent. The
     * two ways out were widening the declared type to something less true
     * than reality, or converting explicitly to the class this really
     * returns. This is the second. At runtime it is a no-op on a value that
     * is already an Illuminate Carbon.
     */
    public function getEndTimeAttribute(): ?Carbon
    {
        if (! $this->start_time) {
            return null;
        }

        // Issue #198 -- os intervalos removidos DA PROVA INTEIRA empurram o
        // fim para frente. A prova acaba quando as equipes tiverem vivido
        // `duration` de tempo que CONTA, e nao de relogio de parede.
        //
        // So os globais (site_id nulo). Uma extensao de uma sede so nao pode
        // mexer no fim das outras, e quem precisa do fim DAQUELA sede
        // pergunta a ContestClock::endTimeFor() -- ver a limitacao anotada
        // em docs/specs/198-intervalos-removidos.md.
        $extension = app(ContestClock::class)->extensionSeconds($this, null);

        return Carbon::instance($this->start_time->copy()->addMinutes($this->duration)->addSeconds($extension));
    }

    public function getFreezeTimeAttribute(): ?Carbon
    {
        if (! $this->start_time) {
            return null;
        }

        return Carbon::instance($this->end_time->copy()->subMinutes($this->attributes['freeze_time']));
    }

    public function isRunning(): bool
    {
        if (! $this->is_active || ! $this->start_time) {
            return false;
        }
        $now = now();

        return $now->gte($this->start_time) && $now->lte($this->end_time);
    }

    /**
     * Issue #189 -- the freeze outlives the contest, and ends when somebody
     * says so.
     *
     * This used to begin `if (! $this->isRunning()) return false;`, and
     * isRunning() requires now() <= end_time. The freeze therefore expired
     * with the contest: the full final standings, including the last hour
     * the freeze exists to hide, went public at the exact second the clock
     * ran out -- to teams and to anonymous visitors, while the teams were
     * still leaving the room.
     *
     * In ICPC the freeze surviving the end IS the ceremony. So the three
     * conditions are now: the contest has started, the freeze window has
     * begun, and nobody has released the standings yet.
     *
     * `freeze_time` of 0 means "no freeze at all" and must keep meaning
     * that. Without the guard below, the accessor resolves the freeze
     * moment to end_time itself, and a contest configured with no freeze
     * would become frozen the instant it ended and stay that way for ever
     * -- the same defect, wearing the opposite sign.
     */
    public function isFrozen(): bool
    {
        // Issue #225 -- `is_active` SAIU desta condicao, e a remocao e o
        // conserto de um vazamento.
        //
        // Enquanto ela estava aqui, desativar um contest o descongelava: o
        // atalho legado `POST /backend/contest/end` grava
        // `is_active = false`, e medido ponta a ponta o placar passava de
        // esconder o solve da janela para MOSTRA-LO. Encerrar publicava a
        // classificacao -- exatamente o vazamento que o #189 fechou, por
        // outra porta.
        //
        // Estar ativo e "este e o evento corrente", e nao "a classificacao
        // ja foi liberada". A unica coisa que termina um congelamento e
        // alguem revelar, e isso e `unfrozen_at`.
        // Issue #276 -- "alguma sede desta prova ainda esta congelada?".
        //
        // A pergunta mudou porque `sites.freeze_time` passou a ser honrada, e
        // sedes com duracao propria entram na janela de congelamento em
        // instantes diferentes. Os chamadores deste metodo -- finalizacao,
        // tela de operacoes, rejulgamento, a listagem do admin -- nao tem um
        // espectador em maos, e para eles a resposta conservadora e a certa:
        // se UMA sede ainda esconde, revelar o quadro inteiro entrega o que
        // ela esconde.
        //
        // Para uma prova cujas sedes nao tem override -- toda prova existente
        // -- a resposta e IDENTICA a de antes, porque todas compartilham a
        // janela do contest. O #189 (o congelamento sobrevive ao fim), o #225
        // (`is_active` fora da condicao) e o "zero quer dizer sem
        // congelamento" continuam valendo, agora dentro de
        // ContestClock::isFrozenFor().
        //
        // Quem TEM um espectador em maos -- as tres telas de placar --
        // pergunta `ContestClock::isFrozenFor($contest, $siteId)`, que e a
        // resposta daquela sede.
        return app(ContestClock::class)->isFrozenForAnyone($this);
    }

    /**
     * Has the freeze already been lifted for this contest?
     *
     * Distinct from `! isFrozen()`, which is also true before the window
     * opens. This is specifically "somebody released the standings", which
     * is what a scoreboard screen needs in order to say so.
     */
    public function isUnfrozen(): bool
    {
        return $this->unfrozen_at !== null;
    }

    /**
     * Issue #202 -- a prova foi declarada final?
     *
     * Distinto de "acabou": acabar e o relogio, finalizar e a organizacao
     * afirmando que nao sobrou nada pendente que pudesse mudar a
     * classificacao. Ver App\Services\ContestFinalizer para a lista do que
     * impede chegar aqui.
     */
    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    /**
     * Issue #284 -- o limite de tamanho do fonte desta prova, em KB.
     *
     * A coluna existe desde a migracao inicial, e ate aqui cinco caminhos a
     * gravavam (assistente, edicao, as duas rotas da API e o importador de
     * evento) sem que nenhum a lesse: os tres pontos de submissao liam a
     * constante global de config/autojudge.php. A tela prometia um numero e
     * o envio aplicava outro -- o mesmo padrao que a #50 consertou em
     * Site.ip_address.
     *
     * Zero ou negativo cai no padrao em vez de recusar tudo: uma linha
     * antiga, ou um importador que tenha gravado 0, nao deve deixar a prova
     * incapaz de receber envio nenhum.
     */
    public function maxSourceKb(): int
    {
        $configured = (int) $this->max_file_size;

        return $configured > 0 ? $configured : self::defaultMaxSourceKb();
    }

    /**
     * O limite para quem nao tem prova em maos.
     *
     * O Treino Livre (#43) e superficie global e nao pertence a contest
     * nenhum, entao para ele esta constante continua sendo a resposta certa
     * -- nao e esquecimento.
     */
    public static function defaultMaxSourceKb(): int
    {
        return max(1, (int) config('autojudge.max_file_size', 100));
    }

    public function getContestTime(): int
    {
        if (! $this->start_time || now()->lt($this->start_time)) {
            return 0;
        }

        // $a->diffInSeconds($b) returns $b's timestamp minus $a's (signed,
        // not absolute, as of Carbon 3 -- this app's pinned version). Elapsed
        // time since start is "now minus start", so start_time must be the
        // receiver and now() the argument, not the other way around; the
        // previous now()->diffInSeconds($this->start_time) returned a
        // NEGATIVE value for the entire duration a contest is running,
        // silently corrupting every Run/Task/Clarification's stored
        // contest_time/judged_time/completed_time/answered_time and, via
        // Score::updateScore()'s solved_time = floor(contest_time / 60),
        // inverting the scoreboard's ranking (Leaderboard::getScoreboard()
        // sorts by total_time ascending, so a more-negative -- i.e. later
        // -- solve time ranked BETTER).
        return (int) $this->start_time->diffInSeconds(now());
    }
}
