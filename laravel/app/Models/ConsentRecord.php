<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's agreement to be called (the `consent_records` table), written only by App\Kit\Consent. Never changed or removed, only withdrawn. `telenow_id` never leaves the kit's backend.
 */
final class ConsentRecord extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'revoke_sent_at' => 'datetime', 'tried_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
