<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'problem_id',
        'number',
        'input_file',
        'output_file',
        'input_hash',
        'output_hash',
        'is_sample',
    ];

    protected $casts = [
        'is_sample' => 'boolean',
    ];

    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class);
    }

    public function getInputPath(): string
    {
        return storage_path("app/{$this->input_file}");
    }

    public function getOutputPath(): string
    {
        return storage_path("app/{$this->output_file}");
    }
}
