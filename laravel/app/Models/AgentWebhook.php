<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A web address an agent's finished calls are sent to (the `agent_webhooks` table), written by App\Kit\Agents\AgentWebhooks. `telenow_id` says Telenow has it and never leaves the kit's
 * backend. `signing_secret` is held encrypted only until it has been shown once.
 */
final class AgentWebhook extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['events' => 'array', 'include_transcript' => 'boolean', 'signing_secret' => 'encrypted', 'deleted_at' => 'datetime'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
