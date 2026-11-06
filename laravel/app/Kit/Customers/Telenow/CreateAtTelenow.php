<?php

namespace App\Kit\Customers\Telenow;

use App\Kit\Customers\Customers;
use App\Kit\Outbox\Outbox;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Workspace;

/**
 * Makes the customer's Telenow sub-account (SELF_HOSTED_KIT_PLAN.md §5 C2) and keeps its key, which Telenow shows only
 * in this answer. The write carries the workspace id as both `externalId` and Idempotency-Key, so:
 * - a try whose answer was lost, sent again within Telenow's 24 h, gets the original answer back, key and all;
 * - after that Telenow refuses the externalId as taken (409). The sub-account is then found by its externalId (a
 *   closed one does not count) and given a new key, which revokes the lost one.
 * A customer closed while this was on its way gets the sub-account it just made closed at once (CloseAtTelenow).
 */
final class CreateAtTelenow extends WorkspaceWrite
{
    public function __construct(TelenowClient $telenow, Settings $settings, private readonly Outbox $outbox)
    {
        parent::__construct($telenow, $settings);
    }

    public function send(OutboxEntry $entry): void
    {
        $workspace = $this->workspace($entry);
        $key = $this->operatorKey();
        try {
            $made = $this->telenow->createSubAccount($key, $entry->body, $entry->idempotency_key);
            $subAccount = $made['subAccount'];
            $apiKey = $made['apiKey'];
        } catch (TelenowError $e) {
            if ($e->status !== 409) {
                throw $e;
            }
            $open = array_values(array_filter(
                $this->telenow->subAccountsByExternalId($key, $workspace->id),
                fn (array $s) => ($s['status'] ?? null) !== 'closed',
            ));
            if ($open === []) {
                throw $e;
            }
            $subAccount = $open[0];
            $apiKey = $this->telenow->rotateSubAccountKey($key, $subAccount['id'], [
                'keyName' => $entry->body['keyName'], 'keyRole' => $entry->body['keyRole'],
            ])['apiKey'];
        }

        $workspace->forceFill([
            'telenow_sub_account_id' => $subAccount['id'],
            'telenow_key' => $apiKey['key'],
            'telenow_key_last_four' => $apiKey['lastFour'] ?? substr($apiKey['key'], -4),
        ]);
        $this->synced($workspace, $subAccount);
        // Closed while this was on its way: close the sub-account it just made, so none is left behind.
        if (in_array($workspace->status, Workspace::ENDED, true)) {
            $workspace->forceFill(['status' => Workspace::CLOSING, 'closed_at' => null])->save();
            $this->outbox->enqueue(Customers::RESOURCE, $workspace->id, CloseAtTelenow::OPERATION, null);
        }
    }
}
