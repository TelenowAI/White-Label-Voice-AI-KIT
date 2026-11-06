<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Agents\AgentFlows;
use App\Kit\Agents\AgentRefused;
use App\Models\Agent;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Console → one agent → its call steps (contract/kit-api.yaml getAgentFlow, saveAgentFlowDraft, discardAgentFlowDraft, checkAgentFlow, publishAgentFlow, unpublishAgentFlow): every member may build them, as with the rest
 * of an agent. An agent of another account is 404. No key is ever answered, and no id of Telenow's.
 */
final class AgentFlowController
{
    public function __construct(private readonly AgentFlows $flows) {}

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($this->flows->show($a)));
    }

    public function saveDraft(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($this->flows->saveDraft($a, $request->all())));
    }

    public function discardDraft(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($this->flows->discardDraft($a)));
    }

    public function check(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json(['warnings' => $this->flows->check($a, $request->all())]));
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($this->flows->publish($a, $request->all())));
    }

    public function unpublish(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($this->flows->unpublish($a)));
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
        } catch (AgentRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
