<?php

namespace App\Kit\Knowledge\Telenow;

use App\Kit\Agents\Telenow\AgentWrite;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/**
 * Sets the bases an agent answers from at Telenow, all at once (`setAgentKnowledgeBases`): the kit's own list as it
 * stands when sent, so a later change never loses to an earlier one. It waits while the agent, or one of its bases, is
 * not made at Telenow yet.
 */
final class SetAgentKnowledgeAtTelenow extends AgentWrite
{
    public function send(OutboxEntry $entry): void
    {
        $agent = $this->agent($entry);
        $key = $this->customerKey($agent);
        $org = $agent->workspace->telenow_sub_account_id;
        if (! is_string($agent->telenow_id) || ! is_string($org)) {
            throw new TelenowError(0, __('agents.not_ready'));
        }
        $bases = $agent->knowledgeBases()->whereNull('knowledge_bases.deleted_at')->get();
        if ($bases->contains(fn ($b) => ! is_string($b->telenow_id))) {
            throw new TelenowError(0, __('knowledge.not_ready'));
        }
        $this->telenow->setAgentKnowledgeBases($key, $org, $agent->telenow_id, $bases->pluck('telenow_id')->values()->all());
        $agent->forceFill(['sync_state' => 'synced', 'sync_error' => null, 'synced_at' => now()])->save();
    }

    public function failed(OutboxEntry $entry, TelenowError $error): void
    {
        $this->agent($entry)->forceFill(['sync_state' => 'failed', 'sync_error' => __('knowledge.not_done')])->save();
    }
}
