<?php

namespace App\Kit\Chat;

use App\Kit\Brand\Brand;
use App\Kit\Console\ServicePause;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\AgentWebChat;
use App\Models\Call;
use App\Models\ChatConversation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Visitors' conversations in a customer's chat box (contract/kit-api.yaml getChatBox, sendChatMessage, getChatMessages, endChatConversation),
 * for the script on the customer's website (public/chat/widget.js). Each message goes on to Telenow's chat API with the account's own key
 * (chatSend, recorded: contract_k15chat.py); the visitor is told only this platform's words, never the voice platform's, nor its ids.
 *
 * A chat box answers only a browser on one of its websites (its `Origin`), and a conversation only the website it started from. A conversation is
 * known by a token the script keeps, held here only as its hash; it is saved, with its call (App\Models\Call, channel `chat`, read and charged as
 * any other: App\Kit\Calls\Calls), only once the agent has answered its first message. A message is refused while the account is paused (out of
 * credit, suspended, closed), while its plan has no price for a chat, and when messages come too fast (config('kit.chat')).
 *
 * An open conversation holds one of the account's calls-at-once places at Telenow, which ends one only after 30 minutes with no message, so the
 * kit ends one itself (endDue(), kit:chats) once nobody has written for config('kit.chat.idle_minutes'), once its chat box stops answering, and
 * when the visitor ends it; and tells Telenow until Telenow has it ended, so the call is rated and charged.
 *
 * Not here: the chat box's settings and the operator's yes (WebChats), reading and charging the call (App\Kit\Calls, App\Kit\Wallet).
 */
final class Conversations
{
    public function __construct(
        private readonly WebChats $chats,
        private readonly TelenowClient $telenow,
        private readonly ServicePause $pause,
        private readonly Brand $brand,
    ) {}

    /** @return array{title: string, logoUrl: ?string, greeting: ?string, colors: object, words: array<string, string>, messageMaxLength: int} the chat box (ChatBox) */
    public function box(string $key, ?string $origin): array
    {
        $box = $this->answering($key, $origin);

        return [
            'title' => $box->title ?? $box->agent->name,
            'logoUrl' => app(\App\Kit\Agents\AgentLogos::class)->url($box->agent),
            'greeting' => $box->greeting,
            'colors' => (object) $this->brand->current()['colors'],
            'words' => __('chat.words'),
            'messageMaxLength' => config('kit.chat.message_max_length'),
        ];
    }

    /** @return array{conversation: string, reply: string} the visitor's message answered (ChatReply); a new conversation's token is in it once */
    public function send(string $key, ?string $origin, string $address, array $input): array
    {
        $box = $this->answering($key, $origin);
        $values = Validator::make($input, [
            'message' => ['required', 'string', 'max:'.config('kit.chat.message_max_length')],
            'conversation' => ['sometimes', 'string', 'max:128'],
        ], [
            'message.required' => __('chat.message_required'),
            'message.max' => __('chat.message_too_long'),
        ], __('chat.fields'))->validate();
        $message = trim($values['message']);
        if ($message === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['message' => [__('chat.message_required')]]);
        }
        $conversation = isset($values['conversation']) ? $this->conversation($box, (string) $origin, $values['conversation']) : null;
        if ($conversation !== null && $conversation->messages >= config('kit.chat.messages_max')) {
            // Over, as if ended: the script offers a new one, and its place at the voice platform is given back at once.
            $this->markEnded($conversation, 'limit');
            $this->tell($conversation);

            throw new ChatRefused(410, __('chat.too_many_messages'));
        }
        $this->limit($box, $address, $conversation === null);
        $workspace = $box->workspace;
        if ($this->pause->for($workspace) !== null || ! $this->chats->priced($workspace)) {
            throw new ChatRefused(503, __('chat.unavailable'));
        }
        $id = $conversation?->id ?? (string) Str::uuid();
        $body = ['agentId' => $box->agent->telenow_id, 'identifier' => 'kit-'.$id, 'input' => $message]
            + ($conversation === null ? [] : ['sessionId' => $conversation->telenow_id]);
        try {
            $answer = $this->telenow->chatSend($workspace->telenow_key, $body);
        } catch (TelenowError $e) {
            if ($e->status === 409) {
                throw new ChatRefused(409, __('chat.still_answering'));
            }
            if ($e->status === 410 && $conversation !== null) {
                $this->markEnded($conversation, 'platform', true);

                throw new ChatRefused(410, __('chat.ended'));
            }

            throw new ChatRefused(503, __('chat.unavailable'));
        }
        $session = $answer['sessionId'] ?? null;
        $reply = $answer['reply'] ?? null;
        if (! is_string($session) || ! Str::isUuid($session) || ! is_string($reply) || ($conversation !== null && $session !== $conversation->telenow_id)) {
            throw new ChatRefused(503, __('chat.unavailable'));
        }
        $token = null;
        if ($conversation === null) {
            $token = Str::random(config('kit.chat.conversation_token_length'));
            $conversation = (new ChatConversation)->forceFill([
                'id' => $id,
                'web_chat_id' => $box->id,
                'workspace_id' => $workspace->id,
                'token_hash' => hash('sha256', $token),
                'website' => $origin,
                'telenow_id' => $session,
                'call_id' => $this->call($box, $session)->id,
                'messages' => 0,
            ]);
        }
        $conversation->forceFill(['messages' => $conversation->messages + 1, 'last_message_at' => now()])->save();

        return ['conversation' => $token ?? $values['conversation'], 'reply' => $reply];
    }

    /** @return array{conversation: string, messages: list<array{role: string, text: string, at: ?string}>} the conversation so far (ChatConversation) */
    public function messages(string $key, ?string $origin, string $token): array
    {
        $box = $this->answering($key, $origin);
        $conversation = $this->conversation($box, (string) $origin, $token);
        try {
            $said = $this->telenow->chatMessages($box->workspace->telenow_key, $conversation->telenow_id);
        } catch (TelenowError) {
            throw new ChatRefused(503, __('chat.unavailable'));
        }
        $roles = config('kit.chat.roles');
        $messages = [];
        foreach ($said as $one) {
            $role = $roles[$one['role'] ?? ''] ?? null;
            if ($role === null || ! is_string($one['content'] ?? null)) {
                continue;
            }
            $messages[] = ['role' => $role, 'text' => $one['content'], 'at' => self::instant($one['createdAt'] ?? null)];
        }

        return ['conversation' => $token, 'messages' => $messages];
    }

    /** The visitor ends it. One ended already is no error; Telenow is told now, or by kit:chats when it cannot be reached. */
    public function close(string $key, ?string $origin, string $token): void
    {
        $box = $this->chatBox($key, $origin);
        $conversation = ChatConversation::query()->where('web_chat_id', $box->id)->where('token_hash', hash('sha256', $token))->first();
        if ($conversation === null) {
            throw new ChatRefused(404, __('chat.no_conversation'));
        }
        if ($conversation->website !== $origin) {
            throw new ChatRefused(403, __('chat.website_not_allowed'));
        }
        if ($conversation->ended_at === null) {
            $this->markEnded($conversation, 'visitor');
        }
        if ($conversation->telenow_ended_at === null) {
            $this->tell($conversation);
        }
    }

    /**
     * End the conversations nobody has written in for config('kit.chat.idle_minutes'), and every open one of a chat box that no longer answers (turned
     * off, its agent deleted, paused or not on the voice platform, or web chat no longer offered); then tell Telenow of each ended one it may still
     * hold open, up to config('kit.chat.ends_per_run') a run. @return int how many Telenow now has ended
     */
    public function endDue(): int
    {
        $most = config('kit.chat.ends_per_run');
        $idle = now()->subMinutes(config('kit.chat.idle_minutes'));
        ChatConversation::query()->whereNull('ended_at')->where('last_message_at', '<=', $idle)->orderBy('last_message_at')->limit($most)->get()
            ->each(fn (ChatConversation $c) => $this->markEnded($c, 'idle'));
        $off = ChatConversation::query()->whereNull('ended_at');
        if ($this->chats->allowed()) {
            $off->whereHas('webChat', fn ($box) => $box->where('enabled', false)->orWhereNull('public_key')
                ->orWhereHas('agent', fn ($agent) => $agent->whereNotNull('deleted_at')->orWhere('is_active', false)->orWhereNull('telenow_id')));
        }
        $off->orderBy('last_message_at')->limit($most)->get()->each(fn (ChatConversation $c) => $this->markEnded($c, 'off'));
        $told = 0;
        $pending = ChatConversation::query()->whereNotNull('ended_at')->whereNull('telenow_ended_at')->whereNotNull('telenow_id')
            ->with('workspace')->orderBy('ended_at')->limit($most)->get();
        foreach ($pending as $conversation) {
            $told += $this->tell($conversation) ? 1 : 0;
        }

        return $told;
    }

    /** No more messages in it; `$telenowEnded` when Telenow has ended it already (it answered 410), else it is told by close() or kit:chats. */
    private function markEnded(ChatConversation $conversation, string $reason, bool $telenowEnded = false): void
    {
        $conversation->forceFill(['ended_at' => $conversation->ended_at ?? now(), 'end_reason' => $conversation->end_reason ?? $reason]
            + ($telenowEnded ? ['telenow_ended_at' => now()] : []))->save();
        $this->readSoon($conversation);
    }

    /** Tell Telenow it is over: ended, or not a session it has (a 404, also ended), is done; unreachable or refused is tried again by kit:chats. */
    private function tell(ChatConversation $conversation): bool
    {
        try {
            $this->telenow->chatEnd($conversation->workspace->telenow_key, $conversation->telenow_id);
        } catch (TelenowError $e) {
            if (! in_array($e->status, [404, 410], true)) {
                return false;
            }
        }
        $conversation->forceFill(['telenow_ended_at' => now()])->save();
        $this->readSoon($conversation);

        return true;
    }

    /** Its call is read soon: it has ended, and Telenow rates it a few minutes after (App\Kit\Calls\Calls waits for that). */
    private function readSoon(ChatConversation $conversation): void
    {
        if ($conversation->call_id !== null) {
            Call::query()->whereKey($conversation->call_id)->where('detail_state', 'pending')->update(['next_read_at' => now()]);
        }
    }

    /** The chat box at `$key` that answers now, for a browser on one of its websites. */
    private function answering(string $key, ?string $origin): AgentWebChat
    {
        $box = $this->chatBox($key, $origin);
        if (! $this->chats->working($box)) {
            throw new ChatRefused(404, __('chat.not_found'));
        }

        return $box;
    }

    /** The chat box at `$key`, for a browser on one of its websites, whether or not it answers now (a visitor may still end a conversation). */
    private function chatBox(string $key, ?string $origin): AgentWebChat
    {
        $box = strlen($key) <= 64 ? AgentWebChat::query()->with(['agent', 'workspace'])->where('public_key', $key)->first() : null;
        if ($box === null || ! $this->chats->allowed()) {
            throw new ChatRefused(404, __('chat.not_found'));
        }
        if ($origin === null || ! in_array($origin, (array) $box->websites, true)) {
            throw new ChatRefused(403, __('chat.website_not_allowed'));
        }

        return $box;
    }

    /** The open conversation `$token` names in `$box`, from the website it started on. */
    private function conversation(AgentWebChat $box, string $origin, string $token): ChatConversation
    {
        $conversation = ChatConversation::query()->with('workspace')->where('web_chat_id', $box->id)->where('token_hash', hash('sha256', $token))->first();
        if ($conversation === null) {
            throw new ChatRefused(404, __('chat.no_conversation'));
        }
        if ($conversation->website !== $origin) {
            throw new ChatRefused(403, __('chat.website_not_allowed'));
        }
        if ($conversation->ended_at !== null) {
            throw new ChatRefused(410, __('chat.ended'));
        }

        return $conversation;
    }

    /** Too fast from one address to this chat box, too many new conversations from it, or too many messages to all of the account's chat boxes: 429. */
    private function limit(AgentWebChat $box, string $address, bool $starting): void
    {
        $limits = [["chat-msg|{$box->id}|{$address}", config('kit.chat.messages_per_minute'), 60], ["chat-account|{$box->workspace_id}", config('kit.chat.account_messages_per_minute'), 60]];
        if ($starting) {
            $limits[] = ["chat-new|{$box->id}|{$address}", config('kit.chat.conversations_per_hour'), 3600];
        }
        foreach ($limits as [$name, $most]) {
            if (RateLimiter::tooManyAttempts($name, $most)) {
                throw new ChatRefused(429, __('chat.too_fast'));
            }
        }
        foreach ($limits as [$name, , $seconds]) {
            RateLimiter::hit($name, $seconds);
        }
    }

    /** The conversation's call, kept at once so it shows in the account's calls; read and charged as any other once it ends. */
    private function call(AgentWebChat $box, string $session): Call
    {
        $call = Call::query()->where('telenow_id', $session)->first() ?? (new Call)->forceFill(['workspace_id' => $box->workspace_id, 'telenow_id' => $session]);
        if (! $call->exists) {
            $call->forceFill([
                'agent_id' => $box->agent_id,
                'status' => 'active',
                'direction' => config('kit.chat.rated_as.direction'),
                'channel' => config('kit.chat.rated_as.channel'),
                'started_at' => now(),
                'detail_state' => 'pending',
                'reads' => 0,
                'next_read_at' => now()->addSeconds(config('kit.calls.reread_base_seconds')),
            ])->save();
        }

        return $call;
    }

    private static function instant(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.v\Z');
        } catch (\Throwable) {
            return null;
        }
    }
}
