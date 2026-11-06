<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A customer's agent (the `agents` table): what the customer built, written by the kit first; `telenow_id` and
 * `sync_state` say how far Telenow has it (App\Kit\Agents). A deleted agent keeps its row with `deleted_at`.
 */
class Agent extends Model
{
    use HasUuids;

    protected $fillable = ['workspace_id', 'name', 'description', 'system_prompt', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'telenow_snapshot' => 'array',
            'settings' => 'array',
            'stack_check' => 'array',
            'stack_checked_at' => 'datetime',
            'after_call_email' => 'array',
            'last_restore' => 'array',
            'flow_draft' => 'array',
            'flow_draft_saved_at' => 'datetime',
            'synced_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** The knowledge bases it answers from (App\Kit\Knowledge\AgentKnowledge). */
    public function knowledgeBases(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeBase::class)->withTimestamps();
    }
}
