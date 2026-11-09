<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A starter agent the operator offers every account (the `starter_agents` table), written only by App\Kit\Agents\StarterAgents: `agent` is what a new
 * agent is made with (description, prompt, settings, voice setup), `flow` its call steps, both taken without anything of the source's account.
 */
final class StarterAgent extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['agent' => 'array', 'flow' => 'array', 'published_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
