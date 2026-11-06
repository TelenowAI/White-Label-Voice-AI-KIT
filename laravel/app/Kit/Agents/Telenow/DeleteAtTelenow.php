<?php

namespace App\Kit\Agents\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/**
 * Deletes the agent at Telenow. One Telenow no longer has (404: deleted by an earlier try whose answer was lost) is
 * as good as deleted. The kit keeps its row, marked deleted (SELF_HOSTED_KIT_PLAN.md §3.8 a.7). A delete Telenow
 * refuses shows the agent again, with the reason and a retry: it still exists there, so it must not look gone.
 */
final class DeleteAtTelenow extends AgentWrite
{
    public function send(OutboxEntry $entry): void
    {
        $agent = $this->agent($entry);
        if ($agent->telenow_id !== null) {
            try {
                $this->telenow->deleteAgent($this->customerKey($agent), $agent->telenow_id);
            } catch (TelenowError $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }
        $agent->forceFill(['deleted_at' => $agent->deleted_at ?? now(), 'sync_state' => 'synced', 'sync_error' => null, 'synced_at' => now()])->save();
    }

    public function failed(OutboxEntry $entry, TelenowError $error): void
    {
        parent::failed($entry, $error);
        $this->agent($entry)->forceFill(['deleted_at' => null])->save();
    }
}
