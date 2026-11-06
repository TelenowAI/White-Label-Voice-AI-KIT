<?php

namespace App\Kit\Agents\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/** Removes the endpoint at Telenow. One that never got there, or is already gone, has nothing to remove. */
final class DeleteWebhookAtTelenow extends WebhookWrite
{
    public function send(OutboxEntry $entry): void
    {
        $hook = $this->hook($entry);
        if ($hook->telenow_id !== null) {
            [$key, $org] = $this->account($hook);
            try {
                $this->telenow->disableWebhook($key, $org, $hook->telenow_id);
            } catch (TelenowError $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }
        $hook->forceFill(['sync_state' => 'synced', 'sync_error' => null])->save();
    }
}
