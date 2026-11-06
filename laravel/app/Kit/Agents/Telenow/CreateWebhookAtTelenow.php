<?php

namespace App\Kit\Agents\Telenow;

use App\Kit\Outbox\NotYet;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;
use App\Models\AgentWebhook;

/**
 * Makes the endpoint at Telenow, for the agent, and keeps its secret encrypted until the customer is shown it. Telenow's create takes no idempotency key and answers the secret only once, so a
 * try after a lost answer first removes what the lost one may have made: the same address on the same agent that no row of the kit's holds.
 */
final class CreateWebhookAtTelenow extends WebhookWrite
{
    public function send(OutboxEntry $entry): void
    {
        $hook = $this->hook($entry);
        $agent = $hook->agent;
        if ($agent->telenow_id === null) {
            if ($hook->deleted_at !== null) {
                return; // removed before its agent got there: there is nothing to make or to wait for
            }
            throw new NotYet(__('agents.not_ready'));
        }
        [$key, $org] = $this->account($hook);
        if ($entry->attempts > 1) {
            $this->removeWhatALostTryMade($hook, $key, $org);
        }
        if ($hook->deleted_at !== null) {
            return; // removed while this waited its turn
        }
        try {
            $data = $this->telenow->createWebhook($key, $org, ['url' => $hook->url, 'events' => $hook->events, 'agentId' => $agent->telenow_id, 'includeTranscript' => $hook->include_transcript]);
        } catch (TelenowError $e) {
            throw $e->status === 400 ? new TelenowError(400, __('agents.webhook_refused')) : $e;
        }
        $hook->forceFill(['telenow_id' => $data['endpoint']['id'], 'signing_secret' => $data['signing_secret'], 'sync_state' => 'synced', 'sync_error' => null])->save();
    }

    private function removeWhatALostTryMade(AgentWebhook $hook, string $key, string $org): void
    {
        foreach ($this->telenow->webhooks($key, $org) as $endpoint) {
            if (($endpoint['url'] ?? null) === $hook->url && ($endpoint['agent_id'] ?? null) === $hook->agent->telenow_id) {
                $this->telenow->disableWebhook($key, $org, $endpoint['id']);
            }
        }
    }
}
