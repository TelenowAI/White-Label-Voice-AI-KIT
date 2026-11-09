<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Agents\AgentBehaviour;
use App\Kit\Agents\AgentLogos;
use App\Kit\Agents\AgentRefused;
use App\Kit\Agents\PromptCheck;
use App\Kit\Agents\AgentSettings;
use App\Kit\Agents\AgentVersions;
use App\Kit\Agents\Agents;
use App\Kit\Agents\AgentProviderSettings;
use App\Kit\Agents\AgentDuplicate;
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
 * startTestCall, previewAgentVoice, getAgentSettingsForm, duplicateAgent): the current account's agents, read from the kit's DB; every member may build
 * them, and duplicate one (AgentDuplicate).
 * An agent of another account, or a deleted one, is 404. Its stack is answered under the kit's own provider keys, with
 * the names customers know them by (App\Kit\Catalog\Catalog). No Telenow id or key is ever answered.
 */
final class AgentsController
{
    public function __construct(private readonly AgentVersions $versions, private readonly AgentSettings $settings, private readonly AgentBehaviour $behaviour, private readonly Emails $emails, private readonly Agents $agents, private readonly TestCall $testCall, private readonly Catalog $catalog, private readonly VoicePreview $preview, private readonly StackCheck $stackCheck, private readonly AgentProviderSettings $providerSettings, private readonly AgentRealtime $realtime, private readonly AgentDuplicate $duplicate) {}

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

    /** A copy of an agent in the same account, and what is left to do on it (keys to type again, steps to publish…). */
    public function duplicate(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, function (Agent $source) {
            ['agent' => $copy, 'toRedo' => $toRedo] = $this->duplicate->duplicate($source);

            return response()->json(['agent' => $this->present($copy), 'toRedo' => $toRedo], 201);
        });
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

    /** The agent's instructions and opening line as they are being written, checked as the voice platform checks them (App\Kit\Agents\PromptCheck). */
    public function checkPrompt(Request $request, string $id, PromptCheck $check): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($check->check($a->workspace, $a, $request->all())));
    }

    /** The agent's logo: a PNG, JPEG or WebP image, kept in the kit (App\Kit\Agents\AgentLogos). Answers the agent. */
    public function setLogo(Request $request, string $id, AgentLogos $logos): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($this->present($logos->set($a, $request->file('file')))));
    }

    public function removeLogo(Request $request, string $id, AgentLogos $logos): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($this->present($logos->remove($a))));
    }

    /** A new agent's instructions and opening line, checked before it is made: nothing of an agent's own is known yet. */
    public function checkNewPrompt(Request $request, PromptCheck $check): JsonResponse|Response
    {
        $workspace = $this->membership($request)->workspace;
        if (! is_string($workspace->telenow_key)) {
            return response()->json(['message' => __('agents.account_not_ready')], 409);
        }

        return $this->refusals(fn () => response()->json($check->check($workspace, null, $request->all())));
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

    /** An agent as every agent operation answers it (the console's starters answer a new agent so too). */
    public function presentAgent(Agent $a): array
    {
        return $this->present($a);
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
            'logoUrl' => app(AgentLogos::class)->url($a),
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
