<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SimilarityCheck extends Model
{
    protected $fillable = [
        'user_id',
        'contest_id',
        'problem_id',
        'language_id',
        'status',
        'threshold',
        'team_count',
        'snapshot',
        'engine_version',
        'options',
        'safe_error_code',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'options' => 'array',
        'threshold' => 'integer',
        'team_count' => 'integer',
    ];

    /**
     * Safe, sanitized messages surfaced to the client for a failed check --
     * never the raw exception/process output, which could leak worker
     * paths or stderr content (docs/specs/42-similarity.md: "Sanitizar
     * erros e nunca subir código para serviço externo").
     */
    public const SAFE_ERROR_MESSAGES = [
        'engine_unavailable' => 'O analisador de similaridade está indisponível no momento. Tente novamente mais tarde.',
        'engine_timeout' => 'A análise excedeu o tempo limite e foi interrompida.',
        'engine_failed' => 'Não foi possível concluir a análise deste conjunto de submissões.',
        'insufficient_sources' => 'Menos de duas equipes com código-fonte disponível restaram para comparar.',
        'worker_interrupted' => 'A análise foi interrompida antes de concluir. Solicite uma nova.',
        'dispatch_failed' => 'Não foi possível iniciar a análise em segundo plano. Solicite uma nova.',
        'result_persist_failed' => 'A análise foi concluída, mas o resultado não pôde ser salvo. Solicite uma nova.',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Helium\User::class, 'user_id', 'user_id');
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function pairs(): HasMany
    {
        return $this->hasMany(SimilarityPair::class);
    }

    public function safeErrorMessage(): ?string
    {
        if ($this->status !== 'failed') {
            return null;
        }

        return self::SAFE_ERROR_MESSAGES[$this->safe_error_code] ?? 'Não foi possível concluir a análise. Solicite uma nova.';
    }
}
