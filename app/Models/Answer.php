<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Answer extends Model
{
    use HasFactory;

    protected $fillable = [
        'contest_id',
        'name',
        'short_name',
        'is_accepted',
        'is_fake',
        'sort_order',
    ];

    protected $casts = [
        'is_accepted' => 'boolean',
        'is_fake' => 'boolean',
    ];

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    public static function getDefaultAnswers(): array
    {
        return [
            ['name' => 'Accepted', 'short_name' => 'AC', 'is_accepted' => true, 'sort_order' => 1],
            ['name' => 'Compilation Error', 'short_name' => 'CE', 'is_accepted' => false, 'sort_order' => 2],
            ['name' => 'Runtime Error', 'short_name' => 'RE', 'is_accepted' => false, 'sort_order' => 3],
            ['name' => 'Time Limit Exceeded', 'short_name' => 'TLE', 'is_accepted' => false, 'sort_order' => 4],
            ['name' => 'Memory Limit Exceeded', 'short_name' => 'MLE', 'is_accepted' => false, 'sort_order' => 5],
            ['name' => 'Wrong Answer', 'short_name' => 'WA', 'is_accepted' => false, 'sort_order' => 6],
            ['name' => 'Presentation Error', 'short_name' => 'PE', 'is_accepted' => false, 'sort_order' => 7],
            ['name' => 'Contact Staff', 'short_name' => 'CS', 'is_accepted' => false, 'sort_order' => 8],
        ];
    }
}
