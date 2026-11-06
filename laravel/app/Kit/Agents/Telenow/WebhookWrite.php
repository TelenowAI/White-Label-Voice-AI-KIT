<?php

namespace App\Kit\Agents\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Outbox\OutboxHandler;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\AgentWebhook;

/**
 * Sending an agent's web address to Telenow (App\Kit\Agents\AgentWebhooks), with the customer's key: what a create and a removal share.
 */
abstract class WebhookWrite implements OutboxHandler
{
    public const RESOURCE = 'agent_webhooks';

    public function __construct(protected readonly TelenowClient $telenow) {}

    public function waiting(OutboxEntry $entry, TelenowError $error): void
    {
        $this->hook($entry)->forceFill(['sync_state' => 'pending', 'sync_error' => __('agents.will_retry')])->save();
    }

    public function failed(OutboxEntry $entry, TelenowError $error): void
    {
        $this->hook($entry)->forceFill(['sync_state' => 'failed', 'sync_error' => $error->getMessage()])->save();
    }

    protected function hook(OutboxEntry $entry): AgentWebhook
    {
        return AgentWebhook::query()->with('agent.workspace')->findOrFail($entry->kit_id);
    }

    /** @return array{string, string} */
    protected function account(AgentWebhook $hook): array
    {
        $workspace = $hook->agent->workspace;
        if (! is_string($workspace->telenow_key) || $workspace->telenow_key === '' || ! is_string($workspace->telenow_sub_account_id)) {
            throw new TelenowError(0, __('agents.account_not_ready'));
        }

        return [$workspace->telenow_key, $workspace->telenow_sub_account_id];
    }
}
