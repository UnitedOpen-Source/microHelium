<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';

    protected $fillable = [
        'user_id',
        'route',
        'idempotency_key',
        'payload_hash',
        'response_status',
        'response_body',
    ];

    protected $casts = [
        'response_status' => 'integer',
        'response_body' => 'array',
    ];
}
