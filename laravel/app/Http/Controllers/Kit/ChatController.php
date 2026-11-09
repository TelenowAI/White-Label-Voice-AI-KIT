<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Chat\ChatRefused;
use App\Kit\Chat\Conversations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A customer's chat box on their own website (contract/kit-api.yaml getChatBox, sendChatMessage, getChatMessages, endChatConversation), for the
 * script public/chat/widget.js: no session or cookie, and the browser's `Origin` is checked against the chat box's websites (App\Kit\Chat\
 * Conversations). The CORS headers come from Laravel's HandleCors with its defaults (every `api/*` path, any origin, no credentials), so the
 * script can read every answer, refusals too: whether a website may use a chat box is Conversations' check, not the browser's.
 */
final class ChatController
{
    public function __construct(private readonly Conversations $conversations) {}

    public function show(Request $request, string $chatKey): JsonResponse
    {
        return $this->answer(fn () => response()->json($this->conversations->box($chatKey, $this->origin($request))));
    }

    public function send(Request $request, string $chatKey): JsonResponse
    {
        return $this->answer(fn () => response()->json($this->conversations->send($chatKey, $this->origin($request), (string) $request->ip(), $request->all())));
    }

    public function messages(Request $request, string $chatKey): JsonResponse
    {
        $token = $request->query('conversation');
        if (! is_string($token) || $token === '' || strlen($token) > 128) {
            return response()->json(['message' => __('chat.no_conversation')], 404);
        }

        return $this->answer(fn () => response()->json($this->conversations->messages($chatKey, $this->origin($request), $token)));
    }

    public function end(Request $request, string $chatKey): JsonResponse
    {
        $request->validate(['conversation' => ['required', 'string', 'max:128']], [], __('chat.fields'));

        return $this->answer(function () use ($request, $chatKey) {
            $this->conversations->close($chatKey, $this->origin($request), $request->input('conversation'));

            return response()->json(['ended' => true]);
        });
    }

    /** The website the browser says it is on: its `Origin`, lowercased as the kit keeps websites. An opaque one (`null`) is never one of a chat box's websites. */
    private function origin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');

        return is_string($origin) && $origin !== '' ? strtolower($origin) : null;
    }

    private function answer(\Closure $act): JsonResponse
    {
        try {
            return $act();
        } catch (ChatRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
