<?php

namespace App\Kit\Outbox;

use App\Kit\Support\JsonBody;
use Illuminate\Database\Eloquent\Model;

/**
 * One write waiting to reach Telenow (the `telenow_outbox` table): which kit row it changes (`resource`, `kit_id`),
 * what to do (`operation`, `body`), the Idempotency-Key every try carries, and how the tries went. Written and read
 * only by Outbox; a handler reads its `body`.
 */
class OutboxEntry extends Model
{
    protected $table = 'telenow_outbox';

    protected $fillable = ['resource', 'kit_id', 'operation', 'body', 'idempotency_key', 'next_attempt_at'];

    protected function casts(): array
    {
        return [
            'body' => JsonBody::class,
            'next_attempt_at' => 'datetime',
            'locked_until' => 'datetime',
            'done_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
