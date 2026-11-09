<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agent's chat box for the customer's own website (the `agent_web_chats` table), written by App\Kit\Chat\WebChats. `public_key` is in the
 * code the customer puts on their pages; `websites` are the addresses (scheme, host and port alone) it may answer from.
 */
final class AgentWebChat extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'websites' => 'array'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
