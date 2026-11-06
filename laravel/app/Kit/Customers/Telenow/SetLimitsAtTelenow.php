<?php

namespace App\Kit\Customers\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/**
 * Sends the limits the operator set for the customer to Telenow (`updateSubAccount`, `limits.maxConcurrentCalls` and
 * `limits.monthlySpendLimitUsd`, null for none; Telenow keeps the fields it is not sent, so the wallet's
 * `totalSpendLimitUsd` stays as SetSpendLimitAtTelenow set it). Read from the workspace when sent, so a write that
 * waited never sends stale limits.
 */
final class SetLimitsAtTelenow extends WorkspaceWrite
{
    public const OPERATION = 'limits';

    public function send(OutboxEntry $entry): void
    {
        $workspace = $this->workspace($entry);
        if ($workspace->telenow_sub_account_id === null) {
            throw new TelenowError(0, __('customers.not_made_yet'));
        }
        $answer = $this->telenow->updateSubAccount($this->operatorKey(), $workspace->telenow_sub_account_id, ['limits' => [
            'maxConcurrentCalls' => $workspace->max_concurrent_calls,
            'monthlySpendLimitUsd' => $workspace->monthly_cap_usd === null ? null : (float) $workspace->monthly_cap_usd,
        ]]);
        $this->synced($workspace, $answer);
    }
}
