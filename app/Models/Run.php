<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Helium\User;

class Run extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contest_id',
        'site_id',
        'user_id',
        'problem_id',
        'language_id',
        'answer_id',
        'run_number',
        'filename',
        'source_file',
        'source_hash',
        'contest_time',
        'judged_time',
        'status',
        'judgehost_id',
        'claimed_at',
        'claim_token',
        'reported_claim_token',
        'give_back_reason',
        'give_back_count',
        'reconcile_attempts',
        'judge_id',
        'judge_site_id',
        'verified_at',
        'verified_by',
        'verify_comment',
        'auto_judge_ip',
        'auto_judge_start',
        'auto_judge_end',
        'auto_judge_result',
        // Issue #196 -- quanto o julgamento realmente levou, na maquina que
        // o fez. Nao muda veredito nenhum: existe para responder se as
        // maquinas sao comparaveis.
        'measured_wall_ms',
        'measured_cpu_ms',
        'auto_judge_stdout',
        'auto_judge_stderr',
    ];

    protected $casts = [
        'auto_judge_start' => 'datetime',
        'auto_judge_end' => 'datetime',
        // Issue #53: the lease reaper compares this against now().
        'claimed_at' => 'datetime',
        // Issue #138: null means "no jury member has released this verdict".
        'verified_at' => 'datetime',
    ];

    /** @return BelongsTo<Contest, $this> */
    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<\Helium\User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\Helium\User::class, 'user_id', 'user_id');
    }

    /** @return BelongsTo<Problem, $this> */
    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class);
    }

    /** @return BelongsTo<Language, $this> */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * Issue #53 -- the machine currently holding this run, if any.
     */
    /** @return BelongsTo<Judgehost, $this> */
    public function judgehost(): BelongsTo
    {
        return $this->belongsTo(Judgehost::class);
    }

    /** @return BelongsTo<Answer, $this> */
    public function answer(): BelongsTo
    {
        return $this->belongsTo(Answer::class);
    }

    /** @return BelongsTo<\Helium\User, $this> */
    public function judge(): BelongsTo
    {
        return $this->belongsTo(\Helium\User::class, 'judge_id', 'user_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function judgeSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'judge_site_id');
    }

    /**
     * Issue #138 -- the jury member who released this verdict to the team.
     *
     * Deliberately not the same column as judge_id: DOMjudge's verifier and
     * its judge are different people by design, and which one signed which
     * half is the whole point of keeping a record.
     */
    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by', 'user_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Issue #138 -- is this run's verdict still being held back from the
     * people competing?
     *
     * Two conditions, and the contest half is the one that makes this safe
     * to ask everywhere: with verification_required off (the default) this
     * is always false, so every call site below behaves exactly as it did
     * before the gate existed.
     *
     * A soft-deleted contest resolves ->contest to null; treat that as "no
     * gate" rather than as "withhold forever", matching how the rest of the
     * codebase reads a null contest (JudgeController::judge()'s
     * `$run->contest?->getContestTime() ?? 0`).
     */
    public function isVerdictWithheld(): bool
    {
        if (! $this->contest?->verification_required) {
            return false;
        }

        return ! $this->isVerified();
    }

    /**
     * Issue #138 -- may this viewer be shown this run's verdict?
     *
     * One definition, used by every team-facing path, because the audit for
     * this issue found the codebase already has two incompatible ideas of
     * "staff": Api\RunController and SubmissionController ask
     * `! isAdmin() && ! isJudge()`, while ScoreboardController::
     * applySiteVisibility() also lets staff, site and spectator accounts
     * through. Picking one here is what stops the gate drifting the way
     * #134/#135 describe.
     *
     * The line drawn: the people running the event (admin, judge, staff,
     * site) see every verdict the moment it exists -- that is their job, and
     * verifying one requires seeing it. Everyone else waits: teams, of
     * course, but also `score` spectator accounts and anonymous visitors,
     * because a projector in the contest hall and the public scoreboard are
     * how a withheld verdict would reach the team anyway. A null viewer is
     * therefore NOT privileged; that also covers the webcast credential
     * (App\Http\Middleware\AuthenticateWebcastCredential never calls
     * Auth::login(), so auth()->user() is null on that route), which is a
     * broadcast and the last place an unreleased verdict should surface.
     */
    public function verdictVisibleTo(?User $viewer): bool
    {
        if (! $this->isVerdictWithheld()) {
            return true;
        }

        return self::viewerSeesWithheldVerdicts($viewer);
    }

    /**
     * The viewer half of verdictVisibleTo(), as a static, because two of
     * the team-facing screens never build a Run at all:
     * SubmissionController::index() and HomeController::index() read runs
     * through DB::table() joins, so they need the same rule without the
     * model. One definition, asked two ways -- the alternative is the same
     * predicate written out three times, which is how gates come apart.
     */
    public static function viewerSeesWithheldVerdicts(?User $viewer): bool
    {
        return (bool) ($viewer?->isAdmin() || $viewer?->isJudge() || $viewer?->isStaff() || $viewer?->isSite());
    }

    /**
     * Issue #138 -- the runs that count towards the standings.
     *
     * A verdict withheld from a team must not be scored for them either: a
     * rank that moves is a verdict announcement.
     *
     * A scope and not an instance predicate, and that is the point. The
     * first version of this was `countsTowardsScore(): bool` on the model,
     * with a docblock claiming Score::recomputeFor() was its only caller.
     * It had NO callers: recomputeFor() builds a query, not a collection of
     * models, so it had spelled the same rule out again in query-builder
     * terms. Two formulations that happened to agree, one of them
     * documented as the single source -- which is precisely how the next
     * person changes the rule in one place and misses the other. Found in
     * review, before it had a chance to drift.
     *
     * $gated is passed in rather than read per run: every row this is asked
     * about belongs to one contest, so asking each of them would be a query
     * per attempt for an answer that cannot differ.
     *
     * Issues #321/#322 -- `answers.counts_as_attempt` entra aqui, e so aqui.
     *
     * Este e o unico lugar em que "este veredito nao conta para a equipe"
     * pode ser verdade. A tentativa obvia -- pular o veredito dentro de
     * `Score::reduceCell()` -- nao muda nada e nao derruba teste nenhum,
     * porque os dois chamadores da reducao carregam a relacao como
     * `answer:id,is_accepted`: a reducao literalmente nao enxerga QUAL
     * veredito ela esta contando, so se ele aceita ou nao. A exclusao tem
     * que mudar quais runs chegam ate ela.
     *
     * E foi por isso que a protecao do #45 e de
     * `AutoJudgeService::handleJudgingError()` nunca funcionou: os dois
     * gravavam o `CS` e deliberadamente NAO chamavam `Score::updateScore()`,
     * o que so adiava a conta. Depois do #171 a celula e uma funcao pura dos
     * runs que contam, entao o proximo veredito da mesma equipe no mesmo
     * problema a recompunha e trazia o `CS` junto -- e a equipe pagava 20
     * minutos por uma falha nossa, no momento em que ninguem estava mais
     * olhando para o run antigo.
     */
    public function scopeCountingTowardsScore(Builder $query, bool $gated): Builder
    {
        return $query
            ->where('status', 'judged')
            ->whereNotNull('answer_id')
            ->whereHas('answer', fn (Builder $q) => $q->where('counts_as_attempt', true))
            ->when($gated, fn (Builder $q) => $q->whereNotNull('verified_at'));
    }

    /**
     * A MESMA regra do escopo acima, perguntada a um run ja carregado.
     *
     * Existe porque o placar congelado (#211) faz uma consulta so para o
     * contest inteiro e filtra a colecao em memoria -- uma consulta por
     * equipe seria centenas numa regional. Ate aqui ele reescrevia o
     * predicado por extenso, e a chegada de `counts_as_attempt` mostrou o
     * preco: o placar ao vivo teria parado de cobrar pelo `CS` e o
     * congelado nao, na mesma requisicao.
     *
     * Um predicado de instancia com este nome ja existiu e foi removido por
     * nao ter chamador nenhum (o docblock dele mentia dizendo que
     * `Score::recomputeFor()` o usava). Este tem um, e e o unico: se algum
     * dia ele ficar sem, some de novo.
     *
     * `$this->answer` e exigido e nao adivinhado de proposito. Se quem
     * carregou os runs esquecer `counts_as_attempt` no `select` da relacao,
     * a resposta vira "nao conta" e o placar zera ruidosamente, em vez de
     * cobrar em silencio a penalidade que esta issue existe para tirar.
     */
    public function countsTowardsScore(bool $gated): bool
    {
        return $this->status === 'judged'
            && $this->answer_id !== null
            && $this->answer !== null
            && (bool) $this->answer->counts_as_attempt
            && (! $gated || $this->verified_at !== null);
    }

    public function getSourcePath(): string
    {
        return storage_path("app/{$this->source_file}");
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isJudged(): bool
    {
        return $this->status === 'judged' && $this->answer_id !== null;
    }

    public function isAccepted(): bool
    {
        return $this->isJudged() && $this->answer?->is_accepted;
    }

    /**
     * Shared by JudgeController's "Atrasado" badge and the
     * runs:reconcile-stuck watchdog (issue #45) -- previously duplicated
     * inline in both places, which had already drifted once (the badge
     * covered pending+judging, the watchdog only pending).
     */
    public function isOverdue(): bool
    {
        if (!in_array($this->status, ['pending', 'judging'], true)) {
            return false;
        }

        $waitLimit = $this->site->max_judge_wait_time ?? 900;

        return $this->created_at->diffInSeconds(now()) > $waitLimit;
    }

    public function getContestTimeFormatted(): string
    {
        $hours = floor($this->contest_time / 3600);
        $minutes = floor(($this->contest_time % 3600) / 60);
        $seconds = $this->contest_time % 60;
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    public static function getNextRunNumber(int $contestId, int $siteId): int
    {
        return self::where('contest_id', $contestId)
            ->where('site_id', $siteId)
            ->max('run_number') + 1 ?? 1;
    }
}
