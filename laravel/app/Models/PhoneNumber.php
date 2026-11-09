<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a customer's phone numbers (the `phone_numbers` table), written only by App\Kit\Numbers. `telenow_id` is
 * Telenow's number; it, the carrier and its record never leave the kit's backend.
 */
final class PhoneNumber extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['next_renewal_at' => 'datetime', 'released_at' => 'datetime', 'routed' => 'boolean', 'agent_caller_id' => 'boolean',
            'renewal_warned_for' => 'datetime', 'get_back_asked_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
