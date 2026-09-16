<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #219 -- uma mudanca no contest, com um token que ordena.
 */
class ContestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'contest_id',
        'type',
        'object_id',
        'op',
        'payload',
        'after_freeze',
    ];

    protected $casts = [
        'payload' => 'array',
        'after_freeze' => 'boolean',
    ];

    /** @return BelongsTo<Contest, $this> */
    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    /**
     * O que este observador pode ver agora.
     *
     * Um evento marcado como da janela de congelamento so aparece para quem
     * nao e da organizacao DEPOIS do descongelamento. A alternativa -- nunca
     * emitir -- deixaria o cliente publico com um placar que nunca se
     * completa; a outra -- emitir na hora -- vazaria.
     */
    public function scopeVisibleTo(Builder $query, bool $unrestricted): Builder
    {
        return $query->when(! $unrestricted, fn (Builder $q) => $q->where('after_freeze', false));
    }
}
