<?php

namespace App\Kit\Customers\Telenow;

use App\Kit\Outbox\OutboxEntry;

/** Lifts the suspension of the customer's sub-account at Telenow (§5 C6); resuming an active one is 200, so a retry is harmless. */
final class ResumeAtTelenow extends WorkspaceWrite
{
    public function send(OutboxEntry $entry): void
    {
        $workspace = $this->workspace($entry);
        $this->synced($workspace, $this->telenow->resumeSubAccount($this->operatorKey(), $workspace->telenow_sub_account_id));
    }
}
