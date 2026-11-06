<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of a customer's knowledge bases (the `knowledge_bases` table), written only by App\Kit\Knowledge. `telenow_id` is
 * Telenow's base; it never leaves the kit's backend.
 */
final class KnowledgeBase extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime', 'documents_read_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class)->withTimestamps();
    }
}
