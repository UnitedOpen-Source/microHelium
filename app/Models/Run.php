<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'judge_id',
        'judge_site_id',
        'answer1_id',
        'judge1_id',
        'answer2_id',
        'judge2_id',
        'auto_judge_ip',
        'auto_judge_start',
        'auto_judge_end',
        'auto_judge_result',
        'auto_judge_stdout',
        'auto_judge_stderr',
    ];

    protected $casts = [
        'auto_judge_start' => 'datetime',
        'auto_judge_end' => 'datetime',
    ];

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Helium\User::class, 'user_id', 'user_id');
    }

    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(Answer::class);
    }

    public function judge(): BelongsTo
    {
        return $this->belongsTo(\Helium\User::class, 'judge_id', 'user_id');
    }

    public function judgeSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'judge_site_id');
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
