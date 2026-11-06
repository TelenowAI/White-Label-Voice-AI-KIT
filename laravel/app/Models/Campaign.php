<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of a customer's campaigns (the `campaigns` table), written only by App\Kit\Campaigns. `status` and the counts are
 * Telenow's; `telenow_id` never leaves the kit's backend.
 */
final class Campaign extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['retry_on_no_answer' => 'boolean', 'dial_block' => 'array', 'push_summary' => 'array', 'started_at' => 'datetime',
            'completed_at' => 'datetime', 'read_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function callerId(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class, 'phone_number_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(CampaignTarget::class);
    }
}
