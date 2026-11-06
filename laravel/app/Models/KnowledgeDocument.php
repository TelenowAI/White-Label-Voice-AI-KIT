<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One document of a knowledge base (the `knowledge_documents` table), written only by App\Kit\Knowledge. `status` is
 * Telenow's (config('kit.knowledge.statuses') says which mean still processing, ready, failed); `telenow_id` never leaves
 * the kit's backend.
 */
final class KnowledgeDocument extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime', 'char_count' => 'integer'];
    }

    public function base(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
