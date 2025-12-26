<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContestLog extends Model
{
    use HasFactory;

    protected $table = 'contest_logs';

    protected $fillable = [
        'contest_id',
        'site_id',
        'user_id',
        'ip_address',
        'type',
        'message',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
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

    public static function log(
        int $contestId,
        string $type,
        string $message,
        ?int $siteId = null,
        ?int $userId = null,
        ?string $ipAddress = null,
        ?array $context = null
    ): self {
        return self::create([
            'contest_id' => $contestId,
            'site_id' => $siteId,
            'user_id' => $userId,
            'ip_address' => $ipAddress ?? request()->ip(),
            'type' => $type,
            'message' => $message,
            'context' => $context,
        ]);
    }

    public static function error(int $contestId, string $message, ?array $context = null): self
    {
        return self::log($contestId, 'error', $message, context: $context);
    }

    public static function warning(int $contestId, string $message, ?array $context = null): self
    {
        return self::log($contestId, 'warning', $message, context: $context);
    }

    public static function info(int $contestId, string $message, ?array $context = null): self
    {
        return self::log($contestId, 'info', $message, context: $context);
    }
}