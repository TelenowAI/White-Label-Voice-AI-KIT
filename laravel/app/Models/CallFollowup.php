<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a member is to do after a call, by when (the `call_followups` table), written only by App\Kit\Calls\CallWork. It is the member's who wrote it,
 * on their own list until it is done. The kit's own, as notes are.
 */
final class CallFollowup extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function finisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
