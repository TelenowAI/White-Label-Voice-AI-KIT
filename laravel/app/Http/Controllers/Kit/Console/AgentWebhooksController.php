<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Agents\AgentRefused;
use App\Kit\Agents\AgentWebhooks;
use App\Models\Agent;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Console → one agent → where its finished calls are sent (contract/kit-api.yaml listAgentWebhooks, addAgentWebhook, removeAgentWebhook, testAgentWebhook, listAgentWebhookDeliveries,
 * replayAgentWebhookDelivery): every member may set them, as with the rest of an agent. An agent of another account is 404. No Telenow id or key is ever answered.
 */
final class AgentWebhooksController
{
    public function __construct(private readonly AgentWebhooks $webhooks) {}

    public function index(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json(['webhooks' => $this->webhooks->list($a), 'events' => config('kit.agents.webhook_events')]));
    }

    public function store(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json($this->webhooks->add($a, $request->all()), 201));
    }

    public function destroy(Request $request, string $id, string $webhookId): JsonResponse|Response
    {
        return $this->with($request, $id, function (Agent $a) use ($webhookId) {
            $this->webhooks->remove($a, $webhookId);

            return response()->noContent();
        });
    }

    public function test(Request $request, string $id, string $webhookId): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json(['deliveries' => $this->webhooks->test($a, $webhookId, $request->all())], 202));
    }

    public function deliveries(Request $request, string $id, string $webhookId): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json(['deliveries' => $this->webhooks->deliveries($a, $webhookId)]));
    }

    public function replay(Request $request, string $id, string $webhookId, string $deliveryId): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Agent $a) => response()->json(['deliveries' => $this->webhooks->replay($a, $webhookId, $deliveryId)], 202));
    }

    private function with(Request $request, string $id, \Closure $act): JsonResponse|Response
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
