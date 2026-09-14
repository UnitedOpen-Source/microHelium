<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #117 -- one language a judge machine can actually run.
 */
class JudgehostCapability extends Model
{
    protected $fillable = ['judgehost_id', 'extension'];

    public function judgehost(): BelongsTo
    {
        return $this->belongsTo(Judgehost::class);
    }
}
