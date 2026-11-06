<?php

namespace App\Kit\Consent\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Outbox\OutboxHandler;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Workspace;

/**
 * What every consent write to Telenow shares: the customer's own key and sub-account. Nothing on the page follows from a write that waits or is given up: a record says it is not saved yet until it is, and `kit:consent` tries a given-up one again.
 *
 * Used by PushConsentAtTelenow, RevokeConsentAtTelenow, SetConsentRuleAtTelenow.
 */
abstract class ConsentWrite implements OutboxHandler
{
    public const RESOURCE = 'consent';

    public const RULE_RESOURCE = 'consent_rule';

    public function __construct(protected readonly TelenowClient $telenow) {}

    public function waiting(OutboxEntry $entry, TelenowError $error): void {}

    public function failed(OutboxEntry $entry, TelenowError $error): void {}

    /** @return array{0: string, 1: string} the customer's key and sub-account */
    protected function account(Workspace $workspace): array
    {
        if (! is_string($workspace->telenow_key) || $workspace->telenow_key === '' || ! is_string($workspace->telenow_sub_account_id)) {
            throw new TelenowError(0, __('consent.account_not_ready'));
        }

        return [$workspace->telenow_key, $workspace->telenow_sub_account_id];
    }
}
