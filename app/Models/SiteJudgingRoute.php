<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteJudgingRoute extends Model
{
    protected $fillable = [
        'host_site_id',
        'source_site_id',
    ];

    public function hostSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'host_site_id');
    }

    public function sourceSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'source_site_id');
    }
}
