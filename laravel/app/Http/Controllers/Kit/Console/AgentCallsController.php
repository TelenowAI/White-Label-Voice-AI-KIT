<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Agents\AgentCalls;
use App\Kit\Agents\AgentRefused;
use App\Models\Agent;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Console → an agent → Call someone (contract/kit-api.yaml getAgentCallForm, placeAgentCall): what the form offers (the account's numbers to call from, the agent's own,
 * the details its words use), and the agent calling one person now (App\Kit\Agents\AgentCalls). Every member may, as they may start a campaign. An agent of another account is 404.
 */
final class AgentCallsController
{
    public function __construct(private readonly AgentCalls $calls) {}

    public function form(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $agent) => response()->json($this->calls->form($agent)));
    }

    public function place(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $agent) => response()->json(['callId' => $this->calls->place($this->membership($request), $agent, $request->all())->id], 201));
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function with(Request $request, string $id, \Closure $act): JsonResponse
    {
        $agent = Str::isUuid($id) ? Agent::query()->with('workspace')->where('workspace_id', $this->membership($request)->workspace_id)->whereNull('deleted_at')->find($id) : null;
        if ($agent === null) {
            return response()->json(['message' => __('agents.not_found')], 404);
        }
        try {
            return $act($agent);
        } catch (AgentRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
