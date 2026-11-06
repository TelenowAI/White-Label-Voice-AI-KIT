<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note a member wrote on a call (the `call_notes` table), written only by App\Kit\Calls\CallWork. The kit's own: never sent to Telenow, which would name
 * the account's key rather than the member (recorded, contract_k9human.py).
 */
final class CallNote extends Model
{
    use HasUuids;

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
