<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a manager told the agent during a live call (the `call_agent_notes` table), written only by App\Kit\Calls\AgentNotes. */
final class CallAgentNote extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['say_now' => 'boolean'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
