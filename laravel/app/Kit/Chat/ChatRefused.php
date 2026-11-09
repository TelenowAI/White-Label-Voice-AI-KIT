<?php

namespace App\Kit\Chat;

use RuntimeException;

/**
 * The kit will not do this with a chat box or a conversation: `status` is the HTTP answer (403 not from one of the chat box's websites, 404 no
 * such chat box or conversation, 409 not possible now or a message still being answered, 410 the conversation has ended (or reached its most messages), 429 messages too fast,
 * 503 the agent cannot answer now) and the message is for the customer or the visitor — it never names the voice platform. Thrown by WebChats and
 * Conversations; AgentWebChatController and ChatController answer it as `{message}`.
 */
final class ChatRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
