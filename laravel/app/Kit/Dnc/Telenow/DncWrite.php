<?php

namespace App\Kit\Dnc\Telenow;

use App\Kit\Manifest\Manifest;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Outbox\OutboxHandler;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\DncEntry;

/**
 * What every DNC write to Telenow shares: the entry, the customer's own key and sub-account, and how the outcome shows:
 * `pending` with the kit's "will retry" words while it is tried again, `failed` with the reason once given up (a number
 * Telenow cannot dial says so in the kit's words: Telenow's own text quotes it back), `synced` with Telenow's entry.
 *
 * Used by AddAtTelenow, RemoveAtTelenow.
 */
abstract class DncWrite implements OutboxHandler
{
    public const RESOURCE = 'dnc';

    public function __construct(protected readonly TelenowClient $telenow, protected readonly Manifest $manifest) {}

    public function waiting(OutboxEntry $entry, TelenowError $error): void
    {
        $this->entry($entry)->forceFill(['sync_state' => 'pending', 'sync_error' => __('dnc.will_retry')])->save();
    }

    public function failed(OutboxEntry $entry, TelenowError $error): void
    {
        $this->entry($entry)->forceFill(['sync_state' => 'failed', 'sync_error' => $error->getMessage()])->save();
    }

    protected function entry(OutboxEntry $entry): DncEntry
    {
        return DncEntry::query()->with('workspace')->findOrFail($entry->kit_id);
    }

    /** @return array{0: string, 1: string} the customer's key and sub-account */
    protected function account(DncEntry $row): array
    {
        $w = $row->workspace;
        if (! is_string($w->telenow_key) || $w->telenow_key === '' || ! is_string($w->telenow_sub_account_id)) {
            throw new TelenowError(0, __('dnc.account_not_ready'));
        }

        return [$w->telenow_key, $w->telenow_sub_account_id];
    }
}
