<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An own voice cloned into one voice provider (the `own_voice_clones` table, one a provider): Telenow's clone row (`telenow_id`) and its status. Written only by
 * App\Kit\Voices\OwnVoices. The provider's own id for the voice is never kept: agents and previews name the voice by its profile, which Telenow keeps to the account.
 */
final class OwnVoiceClone extends Model
{
    use HasUuids;

    public const READY = 'ready';

    public function voice(): BelongsTo
    {
        return $this->belongsTo(OwnVoice::class, 'own_voice_id');
    }
}
