<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contest_id',
        'site_id',
        'user_id',
        'backup_number',
        'filename',
        'file_path',
        'file_size',
        'status',
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
        return $this->belongsTo(User::class);
    }

    public function getFilePath(): string
    {
        return storage_path("app/{$this->file_path}");
    }

    public function getFileSizeFormatted(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public static function getNextBackupNumber(int $contestId, int $siteId): int
    {
        return self::where('contest_id', $contestId)
            ->where('site_id', $siteId)
            ->max('backup_number') + 1 ?? 1;
    }
}
