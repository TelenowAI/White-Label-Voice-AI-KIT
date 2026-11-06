<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person a campaign calls (the `campaign_targets` table): the kit's id travels to Telenow with the push and comes back
 * on the call's result (`outcome`, Telenow's: answered, no-answer, busy, machine, failed, …). `retried_at`: being called again, until a newer result comes.
 */
final class CampaignTarget extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['variables' => 'array', 'pushed_at' => 'datetime', 'completed_at' => 'datetime', 'retried_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
