<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Agents\AgentBehaviour;
use App\Kit\Agents\AgentRefused;
use App\Kit\Agents\AgentSettings;
use App\Kit\Agents\AgentVersions;
use App\Kit\Agents\Agents;
use App\Kit\Agents\AgentProviderSettings;
use App\Kit\Agents\AgentRealtime;
use App\Kit\Agents\StackCheck;
use App\Kit\Agents\TestCall;
use App\Kit\Catalog\Catalog;
use App\Kit\Email\Emails;
use App\Kit\Catalog\CatalogRefused;
use App\Kit\Catalog\VoicePreview;
use App\Models\Agent;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Console → Agents (contract/kit-api.yaml listAgents, createAgent, getAgent, updateAgent, deleteAgent, retryAgent,
 * startTestCall, previewAgentVoice, getAgentSettingsForm): the current account's agents, read from the kit's DB; every member may build them.
 * An agent of another account, or a deleted one, is 404. Its stack is answered under the kit's own provider keys, with
 * the names customers know them by (App\Kit\Catalog\Catalog). No Telenow id or key is ever answered.
 */
final class AgentsController
{
    public function __construct(private readonly AgentVersions $versions, private readonly AgentSettings $settings, private readonly AgentBehaviour $behaviour, private readonly Emails $emails, private readonly Agents $agents, private readonly TestCall $testCall, private readonly Catalog $catalog, private readonly VoicePreview $preview, private readonly StackCheck $stackCheck, private readonly AgentProviderSettings $providerSettings, private readonly AgentRealtime $realtime) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = config('kit.agents.per_page');
        $page = max(1, (int) $request->query('page', 1));
        $query = Agent::query()->where('workspace_id', $this->membership($request)->workspace_id)->whereNull('deleted_at')
            ->orderByDesc('updated_at')->orderByDesc('id');

        return response()->json([
            'agents' => $query->clone()->forPage($page, $perPage)->get()->map($this->present(...))->all(),
            'total' => $query->count(), 'page' => $page, 'perPage' => $perPage,
        ]);
    }

    /** What the agent form offers: how an agent speaks and listens (App\Kit\Agents\AgentBehaviour), and whether the operator allows transfers. */
    public function settingsForm(): JsonResponse
    {
        return response()->json(['transferAllowed' => $this->settings->transferAllowed(), 'emailOn' => $this->emails->on(), 'groups' => $this->behaviour->form()]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->refusals(fn () => response()->json($this->present($this->agents->create($this->membership($request)->workspace, $request->all())), 201));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($this->present($a)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($this->present($this->agents->update($a, $request->all()))));
    }

    public function restoreVersion(Request $request, string $id, string $version): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($this->present($this->versions->restore($a, (int) $version))));
    }

    public function destroy(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, function (Agent $a) {
            $this->agents->delete($a);

            return response()->noContent();
        });
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($this->present($this->agents->retry($a))));
    }

    public function testCall(Request $request, string $id): JsonResponse
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json(['session' => $this->testCall->start($a)]));
    }

    /** A short clip in the agent's own voice (audio/wav), made on demand. */
    public function voicePreview(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, function (Agent $a) use ($request) {
            try {
                $clip = $this->preview->agent($a, $request->all());
            } catch (CatalogRefused $e) {
                return response()->json(['message' => $e->getMessage()], $e->status);
            }

            return response($clip['bytes'], 200, ['Content-Type' => $clip['type'], 'Cache-Control' => 'no-store']);
        });
    }

    /** Check the voice setup now (App\Kit\Agents\StackCheck): each part's verdict in the kit's words, kept as the agent's last check. */
    public function checkStack(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($this->stackCheck->check($a)));
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function with(Request $request, string $id, \Closure $act): JsonResponse|Response
    {
        $agent = Str::isUuid($id) ? Agent::query()->with('workspace')->where('workspace_id', $this->membership($request)->workspace_id)
            ->whereNull('deleted_at')->find($id) : null;
        if ($agent === null) {
            return response()->json(['message' => __('agents.not_found')], 404);
        }

        return $this->refusals(fn () => $act($agent));
    }

    private function refusals(\Closure $act): JsonResponse|Response
    {
        try {
            return $act();
        } catch (AgentRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    private function present(Agent $a): array
    {
        ['stack' => $stack, 'names' => $names] = $this->catalog->presentStack($a);

        return [
            'id' => $a->id,
            'name' => $a->name,
            'description' => $a->description,
            'systemPrompt' => $a->system_prompt,
            'isActive' => $a->is_active,
            'stack' => $stack,
            'stackNames' => $names,
            // Each provider's own settings and the realtime voice as customers name them (never Telenow's provider ids), over the rest.
            'settings' => $this->providerSettings->forCustomers($this->settings->of($a)) + [AgentRealtime::KEY => $this->realtime->forCustomers($this->settings->of($a)[AgentRealtime::KEY] ?? null)]
                + $this->settings->of($a),
            'stackCheck' => $this->stackCheck->last($a),
            'knowledgeBases' => KnowledgeController::agentBases($a),
            'syncState' => $a->sync_state,
            'syncError' => $a->sync_error,
            'createdAt' => $a->created_at->toIso8601String(),
            'updatedAt' => $a->updated_at->toIso8601String(),
        ];
    }
}
