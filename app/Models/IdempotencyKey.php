<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * See App\Services\IdempotencyGuard for the read/write logic and
 * database/migrations/2026_09_11_000201_create_idempotency_keys_table.php
 * for why response_body is encrypted at rest.
 */
class IdempotencyKey extends Model
{
    protected $fillable = [
        'actor',
        'route',
        'idempotency_key',
        'payload_hash',
        'status',
        'response_status',
        'response_body',
    ];
}
