<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A visitor's conversation in a chat box (the `chat_conversations` table), written by App\Kit\Chat\Conversations. Its token is kept only as a
 * SHA-256 hash; `telenow_id` (Telenow's chat session, which is also its call's) never leaves the kit's backend.
 */
final class ChatConversation extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['messages' => 'integer', 'last_message_at' => 'datetime', 'ended_at' => 'datetime', 'telenow_ended_at' => 'datetime'];
    }

    public function webChat(): BelongsTo
    {
        return $this->belongsTo(AgentWebChat::class, 'web_chat_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
