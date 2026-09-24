<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #192 -- um run dentro de um conjunto de rejulgamento, com o veredito
 * antigo guardado ao lado do novo.
 */
class RejudgingRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'rejudging_id',
        'run_id',
        'old_answer_id',
        'old_status',
        'old_judged_time',
        'old_verified_at',
        'old_toolchain_version',
        'old_toolchain_profile',
        'new_answer_id',
        'new_verdict',
        'new_message',
        'new_toolchain_version',
        'new_toolchain_profile',
        'judged_at',
        'error',
    ];

    protected $casts = [
        'old_verified_at' => 'datetime',
        'judged_at' => 'datetime',
    ];

    /** @return BelongsTo<Rejudging, $this> */
    public function rejudging(): BelongsTo
    {
        return $this->belongsTo(Rejudging::class);
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** @return BelongsTo<Answer, $this> */
    public function oldAnswer(): BelongsTo
    {
        return $this->belongsTo(Answer::class, 'old_answer_id');
    }

    /** @return BelongsTo<Answer, $this> */
    public function newAnswer(): BelongsTo
    {
        return $this->belongsTo(Answer::class, 'new_answer_id');
    }

    public function isJudged(): bool
    {
        return $this->judged_at !== null;
    }

    /**
     * O veredito mudou?
     *
     * Um membro que ainda nao rodou, ou que falhou, NAO conta como mudanca:
     * a previa diz "quantos mudariam", e um julgamento que nao aconteceu
     * nao tem opiniao sobre isso. Contar essas linhas como mudanca faria a
     * previa prometer efeito onde nao ha.
     */
    public function changesVerdict(): bool
    {
        if (! $this->isJudged() || $this->error !== null) {
            return false;
        }

        return (int) $this->new_answer_id !== (int) $this->old_answer_id;
    }

    /**
     * Issue #392 -- o julgamento novo foi feito com outra versao do
     * toolchain?
     *
     * Mesma regra de `changesVerdict()`: um membro que nao rodou, ou que
     * falhou, nao opina. E versao desconhecida de UM dos lados tambem nao
     * conta como mudanca -- "nao disse" nao e "mudou" (#303). A previa mostra
     * o que se sabe; afirmar mudanca exige saber os dois lados.
     */
    public function changesToolchain(): bool
    {
        if (! $this->isJudged() || $this->error !== null) {
            return false;
        }

        if ($this->old_toolchain_version === null || $this->new_toolchain_version === null) {
            return false;
        }

        return $this->old_toolchain_version !== $this->new_toolchain_version;
    }
}
