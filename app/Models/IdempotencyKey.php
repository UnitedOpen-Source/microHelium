<?php

namespace App\Models;

use App\Support\IdempotencyStore;
use Illuminate\Database\Eloquent\Model;

/**
 * @see IdempotencyStore
 */
class IdempotencyKey extends Model
{
    protected $fillable = [
        'user_id',
        'route',
        'idempotency_key',
        'payload_hash',
        'response_status',
        'response_body',
    ];

    protected $casts = [
        'response_body' => 'array',
        'response_status' => 'integer',
    ];
}
