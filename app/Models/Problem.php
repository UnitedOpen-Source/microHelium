<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Problem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contest_id',
        'short_name',
        'name',
        'basename',
        'description',
        'description_file',
        'input_file',
        'input_file_hash',
        'color_name',
        'color_hex',
        'time_limit',
        'memory_limit',
        'output_limit',
        'auto_judge',
        'is_fake',
        'sort_order',
    ];

    protected $casts = [
        'auto_judge' => 'boolean',
        'is_fake' => 'boolean',
    ];

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function testCases(): HasMany
    {
        return $this->hasMany(TestCase::class)->orderBy('number');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    public function clarifications(): HasMany
    {
        return $this->hasMany(Clarification::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    public function getPackagePath(): string
    {
        return storage_path("app/problems/{$this->contest_id}/{$this->basename}");
    }

    public function getCompileScriptPath(string $language): string
    {
        return $this->getPackagePath() . "/compile/{$language}";
    }

    public function getRunScriptPath(string $language): string
    {
        return $this->getPackagePath() . "/run/{$language}";
    }

    public function getCompareScriptPath(string $language): string
    {
        return $this->getPackagePath() . "/compare/{$language}";
    }

    public function getLimitsScriptPath(string $language): string
    {
        return $this->getPackagePath() . "/limits/{$language}";
    }

    public function getInputPath(int $testNumber): string
    {
        return $this->getPackagePath() . "/input/{$testNumber}";
    }

    public function getOutputPath(int $testNumber): string
    {
        return $this->getPackagePath() . "/output/{$testNumber}";
    }
}
