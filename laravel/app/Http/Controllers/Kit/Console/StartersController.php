<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Agents\AgentRefused;
use App\Kit\Agents\StarterAgents;
use App\Models\StarterAgent;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Console → New agent → a starter (contract/kit-api.yaml listOfferedStarterAgents, createAgentFromStarter): the ready-made agents the operator offers,
 * and a new agent in the current account made from one (App\Kit\Agents\StarterAgents). Every member may, as they may make an agent. A withdrawn
 * starter is 404. Nothing of where a starter came from is answered.
 */
final class StartersController
{
    public function __construct(private readonly StarterAgents $starters, private readonly AgentsController $agents) {}

    public function index(): JsonResponse
    {
        return response()->json(['starters' => StarterAgent::query()->whereNull('withdrawn_at')->orderBy('name')->orderBy('id')->get()
            ->map(fn (StarterAgent $s) => ['id' => $s->id, 'name' => $s->name, 'description' => $s->description])->all()]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $starter = Str::isUuid($id) ? StarterAgent::query()->whereNull('withdrawn_at')->find($id) : null;
        if ($starter === null) {
            return response()->json(['message' => __('starters.not_found')], 404);
        }
        /** @var WorkspaceMember $member */
        $member = $request->attributes->get('kit.membership');
        try {
            ['agent' => $agent, 'toRedo' => $toRedo] = $this->starters->createFrom($member->workspace, $starter);
        } catch (AgentRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['agent' => $this->agents->presentAgent($agent), 'toRedo' => $toRedo], 201);
    }
}
