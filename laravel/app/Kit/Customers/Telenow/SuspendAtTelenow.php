<?php

namespace App\Kit\Customers\Telenow;

use App\Kit\Outbox\OutboxEntry;

/**
 * Suspends the customer's sub-account at Telenow (§5 C6): new calls are refused and live ones end. Suspending an
 * already suspended sub-account is 200 at Telenow, so a retried write is harmless.
 */
final class SuspendAtTelenow extends WorkspaceWrite
{
    public function send(OutboxEntry $entry): void
    {
        $workspace = $this->workspace($entry);
        $this->synced($workspace, $this->telenow->suspendSubAccount($this->operatorKey(), $workspace->telenow_sub_account_id, $entry->body['reason'] ?? null));
    }
}
