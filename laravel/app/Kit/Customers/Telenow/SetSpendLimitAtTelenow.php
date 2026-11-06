<?php

namespace App\Kit\Customers\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Kit\Wallet\SpendLimit;

/**
 * Sets the customer's spend limit at Telenow (§5 C5, App\Kit\Wallet\SpendLimit): reads the sub-account's lifetime total
 * (`getSubAccountUsage`) and its concurrency limit, computes the limit from the balance as it is NOW, and sends it
 * (`updateSubAccount`, only `limits.totalSpendLimitUsd`; Telenow keeps every field it is not sent). Computed when sent,
 * so a write that waited never sets a stale limit.
 */
final class SetSpendLimitAtTelenow extends WorkspaceWrite
{
    public const RESOURCE = 'workspace';

    public const OPERATION = 'limit';

    public function __construct(TelenowClient $telenow, Settings $settings, private readonly SpendLimit $spendLimit)
    {
        parent::__construct($telenow, $settings);
    }

    public function send(OutboxEntry $entry): void
    {
        $workspace = $this->workspace($entry);
        if ($workspace->telenow_sub_account_id === null) {
            throw new TelenowError(0, __('customers.not_made_yet'));
        }
        $key = $this->operatorKey();
        $usage = $this->telenow->subAccountUsage($key, $workspace->telenow_sub_account_id);
        $total = $usage['totalSpendUsd'] ?? null;
        if (! is_int($total) && ! is_float($total)) {
            throw new TelenowError(0, __('customers.no_total'));
        }
        $concurrency = $usage['limits']['maxConcurrentCalls'] ?? null;
        // Telenow answers the total as a JSON number: written out in full, never in exponent form.
        $limit = $this->spendLimit->compute($workspace, rtrim(rtrim(sprintf('%.10F', $total), '0'), '.'), is_int($concurrency) ? $concurrency : null);
        $answer = $this->telenow->updateSubAccount($key, $workspace->telenow_sub_account_id, ['limits' => ['totalSpendLimitUsd' => (float) $limit]]);
        $workspace->forceFill(['spend_limit_usd' => $limit, 'spend_limit_at' => now()]);
        $this->synced($workspace, $answer);
    }
}
