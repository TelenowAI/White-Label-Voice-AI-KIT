<?php

namespace App\Kit\Calls\Events;

use App\Kit\Calls\Calls;
use App\Kit\Events\EventHandler;
use App\Models\Workspace;

/**
 * `call.completed` (one per finished call, whatever its outcome — unanswered included): the call's account is the one
 * whose sub-account the event names (`subAccountId`), and the kit reads the call itself from Telenow (Calls::read)
 * rather than trusting the event's own copy of its fields. The operator's own calls reach the kit's endpoint too (it
 * is org-wide on the operator's org) and name no sub-account: they are no customer's, and left alone.
 */
final class CallCompleted implements EventHandler
{
    public function __construct(private readonly Calls $calls) {}

    public function apply(array $body): void
    {
        $sub = $body['subAccountId'] ?? null;
        $call = $body['sessionId'] ?? null;
        if (! is_string($sub) || ! is_string($call)) {
            return;
        }
        $workspace = Workspace::query()->where('telenow_sub_account_id', $sub)->first();
        // A closed customer's key is forgotten: a late event about it has nothing left to read.
        if ($workspace !== null && is_string($workspace->telenow_key)) {
            $this->calls->read($workspace, $call);
        }
    }
}
