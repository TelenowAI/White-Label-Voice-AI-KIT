<?php

namespace App\Kit\Agents;

use App\Kit\Agents\Telenow\WebhookWrite;
use App\Kit\Outbox\Outbox;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;
use App\Models\AgentWebhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sending an agent's finished calls to a customer's own web address: Telenow's webhook endpoints, one per address, scoped to the agent, made with the customer's key. Telenow signs and sends
 * them, retries, checks the address and keeps the record of each try, so the kit itself never calls a customer's address. What was recorded (contract_k4hooks.py):
 * - a secret is answered once, at create, so it is held encrypted until the customer has been shown it once;
 * - an endpoint cannot be changed, only removed and made again, with a new secret;
 * - it outlives its agent, so removing an agent removes them (Agents::delete);
 * - Telenow takes any event name, so the kit offers only those in config('kit.agents.webhook_events'). `call.ended` is left out: its `recordingMode` can name the platform. Recordings are too:
 *   their address is the platform's;
 * - a test send or a replay needs Telenow's own setting (WEBHOOK_MANUAL_SENDS_PER_HOUR), and comes from a budget per hour: both refusals are said in the customer's words;
 * - the body carries Telenow's ids for the call and the agent, which name nothing and mean nothing to the customer.
 *
 * Not here: sending the writes (Telenow/*WebhookAtTelenow), the routes (AgentWebhooksController).
 */
final class AgentWebhooks
{
    public function __construct(private readonly Outbox $outbox, private readonly TelenowClient $telenow, private readonly AgentRequest $requests) {}

    /** @return list<array<string, mixed>> the agent's addresses, each with how it is doing at Telenow if that can be read just now */
    public function list(Agent $agent): array
    {
        $hooks = $this->query($agent)->orderBy('created_at')->orderBy('id')->get();
        $live = $hooks->contains(fn (AgentWebhook $h) => $h->telenow_id !== null) ? $this->live($agent) : null;

        return $hooks->map(fn (AgentWebhook $h) => $this->present($h, $live))->all();
    }

    /** @return array<string, mixed> the new address, with its secret the once */
    public function add(Agent $agent, array $input): array
    {
        $values = Validator::make($input, [
            'url' => ['required', 'string', 'max:'.config('kit.agents.limits.address_max_length')],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', Rule::in(config('kit.agents.webhook_events'))],
            'includeTranscript' => ['sometimes', 'boolean'],
        ], [], __('agents.webhook_fields'))->validate();
        $problem = $this->requests->addressProblem($values['url']);
        if ($problem === null && $this->requests->placeholders($values['url']) !== []) {
            $problem = 'webhook_placeholder';
        }
        if ($problem === null && $this->query($agent)->count() >= config('kit.agents.limits.webhooks_per_agent_max')) {
            $problem = 'webhooks_too_many';
        }
        if ($problem === null && $this->query($agent)->get()->contains(fn (AgentWebhook $h) => $h->url === $values['url'])) {
            $problem = 'webhook_twice';
        }
        if ($problem !== null) {
            throw ValidationException::withMessages(['url' => [__("agents.{$problem}")]]);
        }
        $hook = DB::transaction(function () use ($agent, $values) {
            $hook = (new AgentWebhook)->forceFill([
                'agent_id' => $agent->id, 'url' => $values['url'], 'events' => array_values(array_unique($values['events'])), 'include_transcript' => (bool) ($values['includeTranscript'] ?? false), 'sync_state' => 'pending',
            ]);
            $hook->save();
            $this->outbox->enqueue(WebhookWrite::RESOURCE, $hook->id, 'create', []);

            return $hook;
        });
        $this->outbox->runFor(WebhookWrite::RESOURCE, $hook->id);

        return $this->present($hook->fresh(), $this->live($agent));
    }

    public function remove(Agent $agent, string $id): void
    {
        $this->removeOne($this->find($agent, $id));
    }

    /** Every address of the agent: it would outlive the agent at Telenow. */
    public function removeAll(Agent $agent): void
    {
        foreach ($this->query($agent)->get() as $hook) {
            $this->removeOne($hook);
        }
    }

    private function removeOne(AgentWebhook $hook): void
    {
        if ($hook->telenow_id === null && $this->outbox->hasFailed(WebhookWrite::RESOURCE, $hook->id)) {
            $this->outbox->cancel(WebhookWrite::RESOURCE, $hook->id, __('agents.cancelled'));
            $hook->forceFill(['deleted_at' => now(), 'sync_state' => 'synced', 'sync_error' => null, 'signing_secret' => null])->save();

            return;
        }
        DB::transaction(function () use ($hook) {
            $hook->forceFill(['deleted_at' => now(), 'sync_state' => 'pending', 'signing_secret' => null])->save();
            $this->outbox->enqueue(WebhookWrite::RESOURCE, $hook->id, 'delete', null);
        });
        $this->outbox->runFor(WebhookWrite::RESOURCE, $hook->id);
    }

    /** @return list<array<string, mixed>> the latest tries, with this test among them */
    public function test(Agent $agent, string $id, array $input): array
    {
        $hook = $this->atTelenow($agent, $id);
        $event = Validator::make($input, ['event' => ['required', Rule::in($hook->events)]], [], __('agents.webhook_fields'))->validate()['event'];
        [$key, $org] = $this->account($agent);
        $this->manual(fn () => $this->telenow->testWebhook($key, $org, $hook->telenow_id, $event));

        return $this->deliveries($agent, $id);
    }

    /** @return list<array<string, mixed>> the latest tries, with this one sent again among them */
    public function replay(Agent $agent, string $id, string $deliveryId): array
    {
        $hook = $this->atTelenow($agent, $id);
        [$key, $org] = $this->account($agent);
        $this->manual(fn () => $this->telenow->replayWebhookDelivery($key, $org, $hook->telenow_id, $deliveryId, (string) Str::uuid()));

        return $this->deliveries($agent, $id);
    }

    /** @return list<array<string, mixed>> the latest tries, newest first: never what was in them */
    public function deliveries(Agent $agent, string $id): array
    {
        $hook = $this->find($agent, $id);
        if ($hook->telenow_id === null) {
            return [];
        }
        [$key, $org] = $this->account($agent);
        try {
            $rows = $this->telenow->webhookDeliveries($key, $org, $hook->telenow_id, config('kit.agents.webhook_deliveries_shown'));
        } catch (TelenowError $e) {
            throw new AgentRefused(409, __('agents.webhook_unreadable'));
        }

        return array_map(fn (array $row) => [
            'id' => (string) ($row['id'] ?? ''), 'event' => (string) ($row['event_type'] ?? ''), 'status' => (string) ($row['status'] ?? ''), 'attempt' => (int) ($row['attempt'] ?? 0),
            'responseStatus' => is_int($row['response_status'] ?? null) ? $row['response_status'] : null, 'problem' => $this->problem($row), 'kind' => is_string($row['delivery_kind'] ?? null) ? $row['delivery_kind'] : null,
            'at' => is_string($row['created_at'] ?? null) ? $row['created_at'] : null, 'canSendAgain' => in_array($row['status'] ?? null, config('kit.agents.webhook_finished_statuses'), true),
        ], $rows);
    }

    /** Why a try failed, in the customer's words: Telenow's own error holds its internals. */
    private function problem(array $row): ?string
    {
        if (($row['status'] ?? null) === 'delivered') {
            return null;
        }
        $kind = $row['error_kind'] ?? null;
        if (is_string($kind) && is_array(__('agents.webhook_problems')) && isset(__('agents.webhook_problems')[$kind])) {
            return __('agents.webhook_problems')[$kind];
        }
        if (is_int($row['response_status'] ?? null)) {
            return __('agents.webhook_answered', ['status' => $row['response_status']]);
        }

        return is_string($row['error'] ?? null) || is_string($kind) ? __('agents.webhook_problems.other') : null;
    }

    /** A test or a replay: Telenow's refusals in the customer's words. */
    private function manual(\Closure $send): void
    {
        try {
            $send();
        } catch (TelenowError $e) {
            throw new AgentRefused(409, match ($e->status) {
                503 => __('agents.webhook_test_off'),
                429 => __('agents.webhook_test_busy'),
                409 => __('agents.webhook_still_trying'),
                400 => __('agents.webhook_refused'),
                default => __('agents.webhook_unreadable'),
            });
        }
    }

    private function find(Agent $agent, string $id): AgentWebhook
    {
        $hook = Str::isUuid($id) ? $this->query($agent)->find($id) : null;
        if ($hook === null) {
            throw new AgentRefused(404, __('agents.webhook_not_found'));
        }

        return $hook;
    }

    private function atTelenow(Agent $agent, string $id): AgentWebhook
    {
        $hook = $this->find($agent, $id);
        if ($hook->telenow_id === null) {
            throw new AgentRefused(409, __('agents.not_ready'));
        }

        return $hook;
    }

    private function query(Agent $agent): \Illuminate\Database\Eloquent\Builder
    {
        return AgentWebhook::query()->where('agent_id', $agent->id)->whereNull('deleted_at');
    }

    /** @return ?array<string, array> Telenow's active endpoints of the account by id, null when they cannot be read just now */
    private function live(Agent $agent): ?array
    {
        try {
            [$key, $org] = $this->account($agent);

            return array_column($this->telenow->webhooks($key, $org), null, 'id');
        } catch (TelenowError) {
            return null;
        }
    }

    /** @return array{string, string} */
    private function account(Agent $agent): array
    {
        $workspace = $agent->workspace;
        if (! is_string($workspace->telenow_key) || ! is_string($workspace->telenow_sub_account_id)) {
            throw new TelenowError(0, __('agents.account_not_ready'));
        }

        return [$workspace->telenow_key, $workspace->telenow_sub_account_id];
    }

    /** A hook as the customer sees it. Its secret is answered this once, then gone from the kit. */
    private function present(AgentWebhook $hook, ?array $live): array
    {
        $secret = $hook->signing_secret;
        if ($secret !== null) {
            $hook->forceFill(['signing_secret' => null])->save();
        }
        $at = $hook->telenow_id !== null && $live !== null ? ($live[$hook->telenow_id] ?? null) : null;

        return [
            'id' => $hook->id, 'url' => $hook->url, 'events' => $hook->events, 'includeTranscript' => $hook->include_transcript, 'syncState' => $hook->sync_state, 'syncError' => $hook->sync_error,
            'signingSecret' => $secret,
            'lastOkAt' => is_string($at['last_ok_at'] ?? null) ? $at['last_ok_at'] : null,
            'failing' => is_string($at['last_error'] ?? null),
            // Telenow stops an address for good when it answers 410 or stops passing its check, and then no longer lists it.
            'stopped' => $hook->telenow_id !== null && $live !== null && $at === null,
        ];
    }
}
