<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manager listening in to a live call (the `call_listens` table), written only by App\Kit\Calls\ListenIn: who and when. The kit's own.
 */
final class CallListen extends Model
{
    use HasUuids;

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function listener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
