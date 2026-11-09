<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Chat\ChatRefused;
use App\Kit\Chat\WebChats;
use App\Models\Agent;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Console → one agent → its chat box for the customer's own website (contract/kit-api.yaml getAgentWebChat, updateAgentWebChat): every member may
 * set it, as with the rest of an agent. An agent of another account is 404. Nothing of the voice platform is answered.
 */
final class AgentWebChatController
{
    public function __construct(private readonly WebChats $chats) {}

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $agent) => response()->json($this->chats->present($agent)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $agent) => response()->json($this->chats->update($agent, $request->all())));
    }

    private function with(Request $request, string $id, \Closure $act): JsonResponse
    {
        /** @var WorkspaceMember $membership */
        $membership = $request->attributes->get('kit.membership');
        $agent = Str::isUuid($id) ? Agent::query()->with('workspace')->where('workspace_id', $membership->workspace_id)->whereNull('deleted_at')->find($id) : null;
        if ($agent === null) {
            return response()->json(['message' => __('agents.not_found')], 404);
        }
        try {
            return $act($agent);
        } catch (ChatRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
