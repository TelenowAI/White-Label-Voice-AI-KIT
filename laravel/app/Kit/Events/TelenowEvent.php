<?php

namespace App\Kit\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * One delivery from Telenow (the `telenow_events` table): its raw body as it arrived, and how applying it went.
 * Written and read only by Inbox.
 */
final class TelenowEvent extends Model
{
    protected function casts(): array
    {
        return [
            'next_attempt_at' => 'datetime',
            'locked_until' => 'datetime',
            'applied_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
