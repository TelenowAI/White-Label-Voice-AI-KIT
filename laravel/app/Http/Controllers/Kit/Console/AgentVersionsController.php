<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Agents\AgentRefused;
use App\Kit\Agents\AgentVersions;
use App\Models\Agent;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Console → one agent → its versions (contract/kit-api.yaml listAgentVersions, getAgentVersionChanges, getAgentVersionResults): every member may read them, as with the rest of an agent. An agent of
 * another account is 404. Putting one back answers the agent, so it is AgentsController::restoreVersion.
 */
final class AgentVersionsController
{
    public function __construct(private readonly AgentVersions $versions) {}

    public function index(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json(['versions' => $this->versions->list($a), 'lastRestore' => $this->versions->lastRestore($a)]));
    }

    public function changes(Request $request, string $id, string $version): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json(['changes' => $this->versions->changes($a, (int) $version)]));
    }

    public function results(Request $request, string $id, string $version): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($this->versions->results($a, (int) $version)));
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
