<?php

namespace App\Kit\Customers\Telenow;

use App\Kit\Customers\Settling;
use App\Kit\Outbox\NotYet;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Workspace;

/**
 * Closes the customer's sub-account at Telenow for good (`closeSubAccount`: keys revoked, calls refused). It waits, never
 * giving up (NotYet), until the customer is settled: its numbers released, its live calls ended, its last calls and fees
 * read and charged (Settling), because nothing of theirs can be read afterwards. Telenow refuses (409) while it still
 * holds a number the kit has not seen, so that waits too. Closing a closed sub-account is 200 at Telenow, so a repeated
 * write is harmless and finishes a close whose answer was lost. Once closed, the kit forgets the customer's key (Telenow
 * revoked it) and the customer is `closed`.
 */
final class CloseAtTelenow extends WorkspaceWrite
{
    public const OPERATION = 'close';

    /** Telenow's answer while the sub-account still holds numbers. */
    private const HOLDS_NUMBERS = 409;

    public function __construct(TelenowClient $telenow, Settings $settings, private readonly Settling $settling)
    {
        parent::__construct($telenow, $settings);
    }

    public function send(OutboxEntry $entry): void
    {
        $workspace = $this->workspace($entry);
        if ($workspace->telenow_sub_account_id === null) {
            $workspace->forceFill(['status' => Workspace::CLOSED, 'closed_at' => now()])->save();

            return;
        }
        $operatorKey = $this->operatorKey();
        $waiting = $this->settling->waitingFor($workspace, $operatorKey);
        if ($waiting !== null) {
            throw new NotYet($waiting);
        }
        try {
            $answer = $this->telenow->closeSubAccount($operatorKey, $workspace->telenow_sub_account_id);
        } catch (TelenowError $e) {
            // Still holding a number Telenow knows of (released elsewhere, not yet seen here): wait, as above.
            throw $e->status === self::HOLDS_NUMBERS ? new NotYet($e->getMessage()) : $e;
        }
        $workspace->forceFill(['status' => Workspace::CLOSED, 'closed_at' => now(), 'telenow_key' => null]);
        $this->synced($workspace, $answer);
    }
}
