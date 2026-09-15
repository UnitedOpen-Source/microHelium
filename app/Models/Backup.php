<?php

namespace App\Models;

use Helium\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    /** @return BelongsTo<Contest, $this> */
    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Found by PHPStan: this named `User::class` with no import at all, so
     * inside namespace App\Models it resolved to App\Models\User -- a
     * class that has never existed here. The user model is Helium\User,
     * imported above, and its primary key is `user_id`, which is why the
     * relation names both keys. Reading `$backup->user` was a fatal
     * "Class not found", on a model BackupService writes on every backup,
     * and nothing in the suite ever read it.
     */
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
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

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Issue #142 -- withTrashed() matters here in a way it does not for the
     * run and task numbering this mirrors: `backups` soft-deletes, and a
     * soft-deleted row still occupies its slot in the unique
     * (contest_id, site_id, backup_number) index. Numbering that skipped
     * trashed rows would hand out a number the database then refuses to
     * insert -- and it would do it at the one moment the operator least
     * wants a failure, which is right before they change something.
     */
    public static function getNextBackupNumber(int $contestId, int $siteId): int
    {
        $highest = self::withTrashed()
            ->where('contest_id', $contestId)
            ->where('site_id', $siteId)
            ->max('backup_number');

        return ((int) $highest) + 1;
    }
}
