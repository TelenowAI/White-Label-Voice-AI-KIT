<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of an account's own voices (the `own_voices` table): a person who agreed to have their voice cloned, as Telenow's voice profile (`telenow_id`, never shown to customers), and who in
 * the kit confirmed their release, and when. Written only by App\Kit\Voices\OwnVoices; a deleted one keeps its row (`deleted_at`) as the record of that confirmation.
 */
final class OwnVoice extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'sample_added_at' => 'datetime', 'deleted_at' => 'datetime', 'sample_seconds' => 'float'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clones(): HasMany
    {
        return $this->hasMany(OwnVoiceClone::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
