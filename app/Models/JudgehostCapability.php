<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #117 -- one language a judge machine can actually run.
 *
 * Issue #303 -- e, desde entao, com que versao. `version` e anulavel: um
 * agente anterior a #303 nao a envia, e um toolchain que nao se identificou
 * a tempo tambem nao. Ausente nunca significa recusa -- ver
 * {@see Judgehost::canJudge()}.
 */
class JudgehostCapability extends Model
{
    protected $fillable = ['judgehost_id', 'extension', 'version'];

    /** @return BelongsTo<Judgehost, $this> */
    public function judgehost(): BelongsTo
    {
        return $this->belongsTo(Judgehost::class);
    }
}
