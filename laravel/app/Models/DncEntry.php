<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A number on a customer's do-not-call list (the `dnc_entries` table), written only by App\Kit\Dnc. `telenow_id` is
 * Telenow's entry; it never leaves the kit's backend.
 */
final class DncEntry extends Model
{
    use HasUuids;

    protected $fillable = ['workspace_id', 'phone_number', 'reason'];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
